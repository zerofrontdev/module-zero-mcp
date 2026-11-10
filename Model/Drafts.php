<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Photo upload links (zero_mcp_draft): Claude asks for one, the admin
 * opens it on the phone and takes the photos, which land in the
 * connection's conversation as uploads; Claude reads them back by the
 * draft. The key in the link is the only secret; it lasts TTL seconds.
 */
class Drafts
{
    public const TTL = 1800;
    public const MAX_FILES = 10;
    private const TABLE = 'zero_mcp_draft';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array{draft_id: int, key: string}
     */
    public function create(int $tokenId, int $conversationId, string $name): array
    {
        $key = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->resource->getConnection()->insert($this->table(), [
            'token_id' => $tokenId,
            'conversation_id' => $conversationId,
            'key_hash' => hash('sha256', $key),
            'name' => mb_substr($name, 0, 255),
            'upload_ids' => '[]',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ]);
        return ['draft_id' => (int) $this->resource->getConnection()->lastInsertId($this->table()), 'key' => $key];
    }

    /**
     * The draft a link's key opens, while it lasts.
     */
    public function byKey(string $key): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where('key_hash = ?', hash('sha256', $key)));
        return $row && strtotime($row['expires_at'] . ' UTC') >= time() ? $this->decode($row) : null;
    }

    public function get(int $draftId, int $tokenId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where('draft_id = ?', $draftId)->where('token_id = ?', $tokenId));
        return $row ? $this->decode($row) : null;
    }

    /**
     * @param int[] $uploadIds
     */
    public function addUploads(int $draftId, array $uploadIds): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table(), ['upload_ids'])->where('draft_id = ?', $draftId));
        $ids = array_values(array_unique(array_merge((array) json_decode((string) ($row['upload_ids'] ?? '[]'), true), $uploadIds)));
        $db->update($this->table(), ['upload_ids' => json_encode(array_map('intval', $ids))], ['draft_id = ?' => $draftId]);
    }

    private function decode(array $row): array
    {
        $row['draft_id'] = (int) $row['draft_id'];
        $row['token_id'] = (int) $row['token_id'];
        $row['conversation_id'] = (int) $row['conversation_id'];
        $row['upload_ids'] = array_map('intval', (array) json_decode((string) $row['upload_ids'], true));
        return $row;
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
