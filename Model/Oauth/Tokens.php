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
 * Connections (zero_mcp_token): an admin user on one device, with an
 * access token and a refresh token (hashes only). A refresh rotates both.
 * Revoked or expired, a connection answers 401 and Claude asks the admin
 * to sign in again.
 */
class Tokens
{
    /**
     * Seconds after a rotation in which the old refresh token is refused but does not end the connection.
     */
    private const REPLAY_GRACE = 30;

    private const TABLE = 'zero_mcp_token';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{token_id: int, access_token: string, refresh_token: string, expires_in: int}
     */
    public function issue(int $userId, string $clientId, string $clientName, string $scope, ?int $conversationId = null): array
    {
        [$access, $refresh] = [self::random('zmca_'), self::random('zmcr_')];
        $this->resource->getConnection()->insert($this->table(), [
            'user_id' => $userId,
            'client_id' => $clientId,
            'client_name' => mb_substr($clientName, 0, 255),
            'access_hash' => hash('sha256', $access),
            'refresh_hash' => hash('sha256', $refresh),
            'scope' => $scope,
            'conversation_id' => $conversationId,
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->accessSeconds()),
            'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->refreshSeconds()),
        ]);
        return [
            'token_id' => (int) $this->resource->getConnection()->lastInsertId($this->table()),
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $this->config->accessSeconds(),
        ];
    }

    /**
     * New access and refresh tokens on the same connection; the old refresh
     * token stops working at once.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null null: unknown, revoked or expired
     */
    /**
     * @param callable(int): bool $mayConnect whether the admin user may still connect (active, AI Assistant)
     */
    public function refresh(string $refreshToken, string $clientId, callable $mayConnect): ?array
    {
        $row = $this->byHash('refresh_hash', $refreshToken);
        if ($row === null) {
            $this->replayed($refreshToken);
            return null;
        }
        if ($row['client_id'] !== $clientId || $row['revoked_at'] !== null
            || strtotime($row['refresh_expires_at'] . ' UTC') < time()) {
            return null;
        }
        if (!$mayConnect((int) $row['user_id'])) {
            $this->revoke((int) $row['token_id']);
            return null;
        }
        [$access, $refresh] = [self::random('zmca_'), self::random('zmcr_')];
        $rotated = $this->resource->getConnection()->update($this->table(), [
            'access_hash' => hash('sha256', $access),
            'refresh_hash' => hash('sha256', $refresh),
            'previous_refresh_hash' => $row['refresh_hash'],
            'refreshed_at' => gmdate('Y-m-d H:i:s'),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->accessSeconds()),
        ], ['token_id = ?' => (int) $row['token_id'], 'refresh_hash = ?' => $row['refresh_hash']]);
        if ($rotated !== 1) {
            return null;
        }
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $this->config->accessSeconds()];
    }

    /**
     * A refresh token already rotated came back: someone else has it (or had
     * it). The connection ends, unless it was rotated a moment ago (a client
     * retrying the same refresh).
     */
    private function replayed(string $refreshToken): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table(), ['token_id', 'refreshed_at'])
            ->where('previous_refresh_hash = ?', hash('sha256', $refreshToken))
            ->where('revoked_at IS NULL'));
        if ($row && strtotime($row['refreshed_at'] . ' UTC') < time() - self::REPLAY_GRACE) {
            $this->revoke((int) $row['token_id']);
        }
    }

    /**
     * The live connection an access token belongs to.
     */
    public function byAccess(string $accessToken): ?array
    {
        $row = $this->byHash('access_hash', $accessToken);
        if ($row === null || $row['revoked_at'] !== null || strtotime($row['access_expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $row;
    }

    public function get(int $tokenId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where('token_id = ?', $tokenId));
        return $row ? $this->decode($row) : null;
    }

    /**
     * @return array<int, array> newest first, with the admin user's name
     */
    public function all(int $limit = 200): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from(['t' => $this->table()])
            ->joinLeft(['u' => $this->resource->getTableName('admin_user')], 'u.user_id = t.user_id', ['username', 'firstname', 'lastname'])
            ->order('t.token_id DESC')
            ->limit($limit));
        return array_map([$this, 'decode'], $rows);
    }

    public function setConversation(int $tokenId, int $conversationId): void
    {
        $this->resource->getConnection()->update($this->table(), ['conversation_id' => $conversationId], ['token_id = ?' => $tokenId]);
    }

    public function revoke(int $tokenId): void
    {
        $this->resource->getConnection()->update($this->table(), ['revoked_at' => gmdate('Y-m-d H:i:s')], ['token_id = ?' => $tokenId]);
    }

    /**
     * Every live connection of an admin user ended (the user removed or deactivated).
     */
    public function revokeUser(int $userId): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['user_id = ?' => $userId, 'revoked_at IS NULL']
        );
    }

    /**
     * A call counted; false when the connection is over its hour's limit.
     */
    public function count(int $tokenId, string $action, int $perHour): bool
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table(), ['hour_calls', 'hour_start'])->where('token_id = ?', $tokenId));
        $hourStart = $row && $row['hour_start'] ? strtotime($row['hour_start'] . ' UTC') : 0;
        $sameHour = $hourStart > time() - 3600;
        $hourCalls = $sameHour ? (int) $row['hour_calls'] + 1 : 1;
        if ($hourCalls > $perHour) {
            return false;
        }
        $db->update($this->table(), [
            'last_used_at' => gmdate('Y-m-d H:i:s'),
            'last_action' => mb_substr($action, 0, 255),
            'calls' => new \Zend_Db_Expr('calls + 1'),
            'hour_calls' => $hourCalls,
            'hour_start' => $sameHour ? $row['hour_start'] : gmdate('Y-m-d H:i:s'),
        ], ['token_id = ?' => $tokenId]);
        return true;
    }

    private function byHash(string $column, string $token): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where($column . ' = ?', hash('sha256', $token)));
        return $row ? $this->decode($row) : null;
    }

    private function decode(array $row): array
    {
        $row['token_id'] = (int) $row['token_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['conversation_id'] = $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null;
        return $row;
    }

    private static function random(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
