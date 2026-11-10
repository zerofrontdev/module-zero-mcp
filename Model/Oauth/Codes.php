<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Magento\Framework\App\ResourceConnection;

/**
 * Authorization codes (zero_mcp_code): one use, ten minutes, bound to the
 * client, the redirect URI and the PKCE challenge; only their hash is kept.
 */
class Codes
{
    public const TTL = 600;
    private const TABLE = 'zero_mcp_code';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return string the code to send back to the client
     */
    public function issue(string $clientId, int $userId, string $redirectUri, string $codeChallenge, string $scope, ?string $resourceUrl): string
    {
        $code = 'zmac_' . bin2hex(random_bytes(24));
        $this->resource->getConnection()->insert($this->table(), [
            'code_hash' => hash('sha256', $code),
            'client_id' => $clientId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'scope' => $scope,
            'resource' => $resourceUrl,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ]);
        return $code;
    }

    /**
     * The code's row, taken: a second use finds nothing.
     */
    public function take(string $code): ?array
    {
        $db = $this->resource->getConnection();
        $hash = hash('sha256', $code);
        $row = $db->fetchRow($db->select()->from($this->table())->where('code_hash = ?', $hash));
        if (!$row) {
            return null;
        }
        // Whoever deletes the row has the code: two exchanges at once, one gets tokens.
        $taken = $db->delete($this->table(), ['code_hash = ?' => $hash]);
        $db->delete($this->table(), ['expires_at < ?' => gmdate('Y-m-d H:i:s')]);
        return $taken === 1 && strtotime($row['expires_at'] . ' UTC') >= time() ? $row : null;
    }

    /**
     * PKCE S256: base64url(sha256(verifier)) is the challenge.
     */
    public static function verifies(string $verifier, string $challenge): bool
    {
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($expected, $challenge);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
