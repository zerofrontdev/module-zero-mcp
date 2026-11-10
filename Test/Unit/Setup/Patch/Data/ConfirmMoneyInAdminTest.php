<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Setup\Patch\Data\ConfirmMoneyInAdmin;

/**
 * A stored Confirm in the Admin that is the old default (the group saved untouched) becomes the new one; a list the
 * store changed, another scope, or no stored value are left alone; running it again changes nothing.
 */
class ConfirmMoneyInAdminTest extends TestCase
{
    private const NEW = 'cancel_order,invoice_order,ship_order,comment_order,answer_quote_request,update_return,delete_waybill,'
        . 'delete_ukrposhta_shipment,set_settings,send_fiscal_receipts,update_product:price,update_product:special_price,'
        . 'update_product:qty,schedule_change:price,schedule_change:special_price';

    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private array $updates = [];

    /** @var array<string, mixed> the select's conditions */
    private array $wheres = [];

    private function patch(mixed $stored): ConfirmMoneyInAdmin
    {
        $this->updates = [];
        $this->wheres = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[$cond] = $value;
            return $select;
        });
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchOne')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $db->method('update')->willReturnCallback(function (string $table, array $bind, array $where) use (&$stored) {
            $this->updates[] = [$table, $bind, $where];
            $stored = $bind['value'];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ConfirmMoneyInAdmin($resource);
    }

    public function testAnUntouchedDefaultIsUpgraded(): void
    {
        // The old default, in another order, with spaces and a duplicate.
        $patch = $this->patch('set_settings, cancel_order,invoice_order,comment_order,answer_quote_request,update_return,delete_waybill,delete_ukrposhta_shipment,cancel_order');

        $patch->apply();

        $where = ['path = ?' => 'zero_mcp/general/admin_confirm', 'scope = ?' => 'default', 'scope_id = ?' => 0];
        $this->assertSame([['core_config_data', ['value' => self::NEW], $where]], $this->updates);
        $this->assertSame($where, $this->wheres, 'reads the default scope only');
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $patch = $this->patch('cancel_order,invoice_order,comment_order,answer_quote_request,update_return,delete_waybill,delete_ukrposhta_shipment,set_settings');

        $patch->apply();
        $patch->apply();

        $this->assertCount(1, $this->updates);
    }

    public function testACustomisedListIsLeftAlone(): void
    {
        foreach ([
            'cancel_order,invoice_order',
            'cancel_order,invoice_order,comment_order,answer_quote_request,update_return,delete_waybill,delete_ukrposhta_shipment,set_settings,hold_order',
            '',
        ] as $stored) {
            $this->patch($stored)->apply();
            $this->assertSame([], $this->updates, $stored);
        }
    }

    public function testNoStoredValueIsLeftAlone(): void
    {
        foreach ([false, null] as $stored) {
            $this->patch($stored)->apply();
            $this->assertSame([], $this->updates);
        }
    }

    public function testItDependsOnNothing(): void
    {
        $this->assertSame([], ConfirmMoneyInAdmin::getDependencies());
        $this->assertSame([], $this->patch(false)->getAliases());
    }
}
