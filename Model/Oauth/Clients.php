<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Magento\Framework\App\ResourceConnection;
use Zero\Mcp\Model\Config;

/**
 * OAuth clients (zero_mcp_client): Claude registers one per connection
 * (Dynamic Client Registration, RFC 7591). A client may only send the
 * admin back to Claude's own callback, to a loopback address (Claude Code,
 * any port) or to a URI the store listed itself.
 */
class Clients
{
    private const TABLE = 'zero_mcp_client';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $metadata the registration request
     * @return array<string, mixed> the registration response
     * @throws OauthException
     */
    public function register(array $metadata): array
    {
        // Only text URIs; a nested array or object counts as none (never "Array to string conversion").
        $uris = array_values(array_filter(array_map(
            static fn ($uri) => is_scalar($uri) ? (string) $uri : '',
            (array) ($metadata['redirect_uris'] ?? [])
        )));
        if (!$uris) {
            throw new OauthException('invalid_client_metadata', 'redirect_uris is required.');
        }
        foreach ($uris as $uri) {
            if (!$this->isRedirectAllowed($uri)) {
                throw new OauthException('invalid_redirect_uri', 'Redirect URI not allowed: ' . $uri);
            }
        }
        $name = is_scalar($metadata['client_name'] ?? null) ? trim((string) $metadata['client_name']) : '';
        $name = mb_substr($name, 0, 255) ?: 'MCP client';
        $clientId = 'zmc_' . bin2hex(random_bytes(16));
        $this->resource->getConnection()->insert($this->table(), [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => json_encode($uris),
            'metadata' => json_encode($this->keptMetadata($metadata), JSON_UNESCAPED_SLASHES),
        ]);
        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => Config::SCOPE,
        ];
    }

    /**
     * The metadata kept with the client: a few descriptive keys, each value at most 255 characters.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    private function keptMetadata(array $metadata): array
    {
        $kept = [];
        foreach (array_intersect_key($metadata, array_flip(['client_name', 'client_uri', 'logo_uri', 'software_id', 'software_version'])) as $key => $value) {
            $kept[$key] = mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES), 0, 255);
        }
        return $kept;
    }

    public function get(string $clientId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where('client_id = ?', $clientId));
        if (!$row) {
            return null;
        }
        $row['redirect_uris'] = (array) json_decode((string) $row['redirect_uris'], true);
        return $row;
    }

    /**
     * Whether the client registered $uri: exact, or loopback with any port.
     */
    public function matches(array $client, string $uri): bool
    {
        foreach ($client['redirect_uris'] as $registered) {
            if ($registered === $uri || (self::isLoopback($registered) && self::isLoopback($uri) && self::withoutPort($registered) === self::withoutPort($uri))) {
                return true;
            }
        }
        return false;
    }

    public function isRedirectAllowed(string $uri): bool
    {
        if ($uri === Config::CLAUDE_CALLBACK || in_array($uri, $this->config->extraRedirectUris(), true)) {
            return true;
        }
        return self::isLoopback($uri);
    }

    /**
     * http://localhost[:port]/… or http://127.0.0.1[:port]/… (RFC 8252 native clients).
     */
    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)
            && empty($parts['query']) && empty($parts['fragment']);
    }

    private static function withoutPort(string $uri): string
    {
        return (string) preg_replace('#^(http://(?:localhost|127\.0\.0\.1)):\d+#', '$1', $uri);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
