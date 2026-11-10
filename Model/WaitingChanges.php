<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\Framework\App\ResourceConnection;
use Zero\AiAssistant\Model\Conversations;

/**
 * Changes a connection proposed that wait for the admin in the admin
 * (Controller\Adminhtml\Change): the admin user's own, in a connection's
 * conversation, still proposed and not expired.
 */
class WaitingChanges
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array> newest first, each with the connection's client_name
     */
    public function forUser(int $userId): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($this->select($userId)->order('a.action_id DESC')->limit(50));
        foreach ($rows as $i => $row) {
            $rows[$i]['preview'] = (array) json_decode((string) $row['preview'], true);
        }
        return $rows;
    }

    public function get(int $actionId, int $userId): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($this->select($userId)->where('a.action_id = ?', $actionId));
        return $row ?: null;
    }

    public function count(int $userId): int
    {
        $select = $this->select($userId)->reset(\Magento\Framework\DB\Select::COLUMNS)->columns(['n' => new \Zend_Db_Expr('COUNT(*)')]);
        return (int) $this->resource->getConnection()->fetchOne($select);
    }

    private function select(int $userId): \Magento\Framework\DB\Select
    {
        return $this->resource->getConnection()->select()
            ->from(['a' => $this->resource->getTableName('zero_ai_action')], ['action_id', 'tool', 'preview', 'created_at'])
            ->join(['t' => $this->resource->getTableName('zero_mcp_token')], 't.conversation_id = a.conversation_id', ['client_name'])
            ->where('a.user_id = ?', $userId)
            ->where('a.status = ?', 'proposed')
            ->where('a.created_at >= ?', gmdate('Y-m-d H:i:s', time() - Conversations::PROPOSAL_TTL));
    }
}
