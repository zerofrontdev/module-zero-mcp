<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Zero\AiAssistant\Model\Conversations;
use Zero\Mcp\Model\Acl\UserAuthorizationFactory;
use Zero\Mcp\Model\Config;

/**
 * The OAuth 2.1 authorization server Claude talks to: discovery metadata,
 * the authorization request checked, the admin's consent (given in the
 * admin, behind its sign-in, two-step verification and lockout) turned
 * into a code, the code (with PKCE) or a refresh token turned into
 * tokens. Public clients only (no client secret); S256 PKCE required.
 */
class Service
{
    public function __construct(
        private readonly Config $config,
        private readonly Clients $clients,
        private readonly Codes $codes,
        private readonly Tokens $tokens,
        private readonly UserAuthorizationFactory $authorizationFactory,
        private readonly Conversations $conversations
    ) {
    }

    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'issuer' => $this->config->origin(),
            'authorization_endpoint' => $this->config->url('zero_mcp/oauth/authorize'),
            'token_endpoint' => $this->config->url('zero_mcp/oauth/token'),
            'registration_endpoint' => $this->config->url('zero_mcp/oauth/register'),
            'scopes_supported' => [Config::SCOPE, 'offline_access'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'service_documentation' => 'https://zerofront.dev/docs',
        ];
    }

    /**
     * RFC 9728 protected resource metadata for /mcp.
     *
     * @return array<string, mixed>
     */
    public function resourceMetadata(): array
    {
        return [
            'resource' => $this->config->serverUrl(),
            'authorization_servers' => [$this->config->origin()],
            'scopes_supported' => [Config::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Zero store',
        ];
    }

    public function resourceMetadataUrl(): string
    {
        return $this->config->url('.well-known/oauth-protected-resource/mcp');
    }

    /**
     * The authorization request as it arrived, checked. A bad client or
     * redirect URI throws (the user is told, never redirected); anything
     * else wrong is returned to the client as an error redirect.
     *
     * @param array<string, mixed> $query
     * @return array{client: array, redirect_uri: string, state: string, code_challenge: string, scope: string, resource: string|null, error: string|null}
     * @throws OauthException
     */
    public function authorizationRequest(array $query): array
    {
        $client = $this->clients->get((string) ($query['client_id'] ?? ''));
        if ($client === null) {
            throw new OauthException('invalid_client', 'Unknown client. In Claude, remove the connector and add it again.');
        }
        $redirect = (string) ($query['redirect_uri'] ?? '');
        if ($redirect === '' || !$this->clients->matches($client, $redirect)) {
            throw new OauthException('invalid_request', 'The redirect URI is not one this client registered.');
        }
        $request = [
            'client' => $client,
            'redirect_uri' => $redirect,
            'state' => (string) ($query['state'] ?? ''),
            'code_challenge' => (string) ($query['code_challenge'] ?? ''),
            'scope' => Config::SCOPE,
            'resource' => isset($query['resource']) ? (string) $query['resource'] : null,
            'error' => null,
        ];
        if (($query['response_type'] ?? '') !== 'code') {
            $request['error'] = 'unsupported_response_type';
        } elseif ($request['code_challenge'] === '' || ($query['code_challenge_method'] ?? '') !== 'S256') {
            $request['error'] = 'invalid_request';
        }
        return $request;
    }

    /**
     * The admin, signed in to the admin, allowed the request: the code for
     * the client.
     *
     * @param array<string, mixed> $pending a row Pending::take() returned
     * @return string the redirect URL with the code
     * @throws OauthException when the user's role may not connect
     */
    public function approve(array $pending, int $userId): string
    {
        $authorization = $this->authorizationFactory->create(['userId' => $userId]);
        if (!$authorization->isAllowed('Zero_AiAssistant::chat')) {
            throw new OauthException('access_denied', (string) __('This admin user\'s role has no AI Assistant: System > Permissions > User Roles > Role Resources > AI Assistant.'), 403);
        }
        $code = $this->codes->issue(
            (string) $pending['client_id'],
            $userId,
            (string) $pending['redirect_uri'],
            (string) $pending['code_challenge'],
            (string) $pending['scope'],
            $pending['resource'] !== null ? (string) $pending['resource'] : null
        );
        return self::redirect((string) $pending['redirect_uri'], ['code' => $code, 'state' => (string) $pending['state']]);
    }

    /**
     * The admin said no: the client is told so.
     *
     * @param array<string, mixed> $pending a row Pending::take() returned
     */
    public function deny(array $pending): string
    {
        return self::redirect((string) $pending['redirect_uri'], ['error' => 'access_denied', 'state' => (string) $pending['state']]);
    }

    /**
     * The token endpoint's answer.
     *
     * @param array<string, mixed> $post form fields
     * @return array<string, mixed>
     * @throws OauthException
     */
    public function token(array $post): array
    {
        $clientId = self::field($post, 'client_id');
        $grant = self::field($post, 'grant_type');
        if ($grant === 'refresh_token') {
            $refreshed = $this->tokens->refresh(
                self::field($post, 'refresh_token'),
                $clientId,
                fn (int $userId) => $this->authorizationFactory->create(['userId' => $userId])->isAllowed('Zero_AiAssistant::chat')
            );
            if ($refreshed === null) {
                throw new OauthException('invalid_grant', 'The refresh token is not valid any more: sign in again.');
            }
            return $refreshed + ['token_type' => 'Bearer', 'scope' => Config::SCOPE];
        }
        if ($grant !== 'authorization_code') {
            throw new OauthException('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
        }
        $code = $this->codes->take(self::field($post, 'code'));
        if ($code === null) {
            throw new OauthException('invalid_grant', 'The authorization code is unknown, used or expired.');
        }
        if ($code['client_id'] !== $clientId || $code['redirect_uri'] !== self::field($post, 'redirect_uri')) {
            throw new OauthException('invalid_grant', 'The code was issued to another client or redirect URI.');
        }
        if (!Codes::verifies(self::field($post, 'code_verifier'), (string) $code['code_challenge'])) {
            throw new OauthException('invalid_grant', 'PKCE verification failed.');
        }
        $client = $this->clients->get($clientId);
        $conversation = $this->conversations->create(
            (int) $code['user_id'],
            (string) __('Claude connector · %1 · %2', $client['client_name'] ?? 'MCP client', date('Y-m-d'))
        );
        $issued = $this->tokens->issue((int) $code['user_id'], $clientId, (string) ($client['client_name'] ?? ''), (string) $code['scope'], $conversation);
        unset($issued['token_id']);
        return $issued + ['token_type' => 'Bearer', 'scope' => (string) $code['scope']];
    }

    /**
     * @param array<string, string> $params
     */
    public static function redirect(string $uri, array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A form field as text; an array (grant_type[]=x) counts as missing, so the
     * grant is refused as invalid instead of failing with "Array to string conversion".
     *
     * @param array<string, mixed> $post
     */
    private static function field(array $post, string $name): string
    {
        $value = $post[$name] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
