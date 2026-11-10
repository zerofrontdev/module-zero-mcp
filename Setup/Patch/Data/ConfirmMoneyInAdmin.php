<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Confirm in the Admin gained shipping, fiscal receipts and product prices and qty (etc/config.xml). Saving the
 * Claude Connector group in the admin stores the list that was shown, so a store that never changed it may hold the
 * old default: that one (the same set, in any order) becomes the new default. A list the store changed is left alone.
 */
class ConfirmMoneyInAdmin implements DataPatchInterface
{
    private const PATH = 'zero_mcp/general/admin_confirm';
    private const OLD_DEFAULT = 'cancel_order,invoice_order,comment_order,answer_quote_request,update_return,'
        . 'delete_waybill,delete_ukrposhta_shipment,set_settings';
    private const NEW_DEFAULT = 'cancel_order,invoice_order,ship_order,comment_order,answer_quote_request,'
        . 'update_return,delete_waybill,delete_ukrposhta_shipment,set_settings,send_fiscal_receipts,'
        . 'update_product:price,update_product:special_price,update_product:qty,'
        . 'schedule_change:price,schedule_change:special_price';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function apply(): self
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('core_config_data');
        $where = ['path = ?' => self::PATH, 'scope = ?' => 'default', 'scope_id = ?' => 0];
        $select = $db->select()->from($table, 'value');
        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }
        $stored = $db->fetchOne($select);
        if ($stored !== false && $stored !== null && self::set((string) $stored) === self::set(self::OLD_DEFAULT)) {
            $db->update($table, ['value' => self::NEW_DEFAULT], $where);
        }
        return $this;
    }

    /**
     * @return string[] the entries, trimmed and sorted
     */
    private static function set(string $list): array
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $e) => $e !== ''));
        $entries = array_values(array_unique($entries));
        sort($entries);
        return $entries;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
