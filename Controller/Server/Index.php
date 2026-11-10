<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Server;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Zero\Mcp\Controller\Endpoint;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\ConnectionFactory;
use Zero\Mcp\Model\Oauth\Service;
use Zero\Mcp\Model\Oauth\Tokens;
use Zero\Mcp\Model\Server;

/**
 * /mcp, Streamable HTTP: a JSON-RPC message (or batch) by POST with a
 * bearer token, answered as JSON. Without a valid token, 401 with where
 * to sign in (WWW-Authenticate), which starts Claude's OAuth flow. GET
 * (an event stream) isn't offered: nothing is pushed between calls.
 */
class Index extends Endpoint
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Config $config,
        private readonly Server $server,
        private readonly Service $service,
        private readonly Tokens $tokens,
        private readonly ConnectionFactory $connectionFactory
    ) {
        parent::__construct($request, $rawFactory, $config);
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return $this->off();
        }
        $method = strtoupper((string) $this->http()->getMethod());
        if ($method === 'OPTIONS') {
            return $this->raw('', 204, 'text/plain');
        }
        $token = $this->bearer();
        $row = $token !== '' ? $this->tokens->byAccess($token) : null;
        if ($row === null) {
            $challenge = 'Bearer resource_metadata="' . $this->service->resourceMetadataUrl() . '", scope="' . Config::SCOPE . '"'
                . ($token !== '' ? ', error="invalid_token", error_description="The token is unknown, expired or revoked."' : '');
            return $this->json(Server::fail(null, -32001, 'Unauthorized'), 401, ['WWW-Authenticate' => $challenge]);
        }
        if ($method !== 'POST') {
            return $this->json(Server::fail(null, -32000, 'Method not allowed: POST a JSON-RPC message.'), 405, ['Allow' => 'POST, OPTIONS']);
        }
        $message = json_decode((string) $this->http()->getContent(), true);
        if (!is_array($message)) {
            return $this->json(Server::fail(null, -32700, 'Parse error'), 400);
        }
        $connection = $this->connectionFactory->create(['token' => $row]);
        // The admin user as they are now: removed or deactivated ends the connection, a role without the AI
        // Assistant stops it.
        if ($connection->authorization()->roleId() === null) {
            $this->tokens->revoke($connection->tokenId());
            return $this->json(Server::fail(null, -32001, 'Unauthorized'), 401, ['WWW-Authenticate' => 'Bearer error="invalid_token", error_description="The admin user is inactive or removed."']);
        }
        if (!$connection->authorization()->isAllowed('Zero_AiAssistant::chat')) {
            return $this->json(Server::fail($message['id'] ?? null, -32003, 'This admin user\'s role has no AI Assistant any more.'), 403);
        }
        $answer = $this->server->handle($message, $connection);
        $headers = ['MCP-Protocol-Version' => Server::PROTOCOL];
        return $answer === null ? $this->raw('', 202, 'text/plain') : $this->json($answer, 200, $headers);
    }

    private function bearer(): string
    {
        $header = (string) $this->http()->getHeader('Authorization');
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
    }
}
