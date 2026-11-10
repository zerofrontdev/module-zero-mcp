<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Magento\Framework\App\ResourceConnection;

/**
 * Authorization requests waiting for an admin to allow them in the admin
 * (Controller\Adminhtml\Oauth\Consent). Each has two secrets, only their
 * hashes stored: a long key for the browser that started it (a cookie, so
 * the admin who signs in there is taken to it) and a short code shown on
 * the connect page (typed in the admin when the admin lives elsewhere).
 * Ten minutes, then gone; taken once.
 */
class Pending
{
    public const TTL = 600;
    public const COOKIE = 'zero_mcp_pending';
    private const TABLE = 'zero_mcp_pending';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param array<string, mixed> $request from Service::authorizationRequest()
     * @return array{key: string, code: string} the cookie's key and the code to show
     */
    public function create(array $request): array
    {
        $db = $this->resource->getConnection();
        $db->delete($this->table(), ['expires_at < ?' => gmdate('Y-m-d H:i:s')]);
        $key = 'zmcp_' . bin2hex(random_bytes(32));
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $db->insert($this->table(), [
            'key_hash' => hash('sha256', $key),
            'code_hash' => hash('sha256', $code),
            'client_id' => (string) $request['client']['client_id'],
            'redirect_uri' => (string) $request['redirect_uri'],
            'state' => mb_substr((string) $request['state'], 0, 1024),
            'code_challenge' => (string) $request['code_challenge'],
            'scope' => (string) $request['scope'],
            'resource' => $request['resource'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ]);
        return ['key' => $key, 'code' => substr($code, 0, 4) . '-' . substr($code, 4)];
    }

    public function byKey(string $key): ?array
    {
        return $key === '' ? null : $this->find('key_hash', hash('sha256', $key));
    }

    /**
     * The code as the admin typed it: case, spaces and dashes do not matter.
     */
    public function byCode(string $code): ?array
    {
        $code = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        return strlen($code) !== 8 ? null : $this->find('code_hash', hash('sha256', $code));
    }

    public function get(int $id): ?array
    {
        return $this->find('pending_id', $id);
    }

    /**
     * The request, taken: of two Allow clicks (or Allow and Deny) only one
     * gets it.
     */
    public function take(int $id): ?array
    {
        $row = $this->get($id);
        if ($row === null) {
            return null;
        }
        $taken = $this->resource->getConnection()->delete($this->table(), [
            'pending_id = ?' => $id,
            'expires_at >= ?' => gmdate('Y-m-d H:i:s'),
        ]);
        return $taken === 1 ? $row : null;
    }

    private function find(string $column, string|int $value): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where($column . ' = ?', $value)
            ->where('expires_at >= ?', gmdate('Y-m-d H:i:s')));
        return $row ?: null;
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
