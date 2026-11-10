<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Model\Config;

/**
 * Confirm in the Admin (D10): whole tools, and tool:field entries that cover a change only when it gives that field.
 */
class ConfigTest extends TestCase
{
    private const D10 = ['cancel_order', 'invoice_order', 'ship_order', 'comment_order', 'answer_quote_request', 'update_return',
        'delete_waybill', 'delete_ukrposhta_shipment', 'set_settings', 'send_fiscal_receipts', 'update_product:price',
        'update_product:special_price', 'update_product:qty', 'schedule_change:price', 'schedule_change:special_price'];

    private function config(array $values): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        return new Config($scope, $this->createStub(StoreManagerInterface::class));
    }

    private function defaultList(): string
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/config.xml');
        return (string) $xml->default->zero_mcp->general->admin_confirm;
    }

    public function testTheDefaultListCoversMoneyShippingAndPrices(): void
    {
        $entries = explode(',', $this->defaultList());
        sort($entries);
        $expected = self::D10;
        sort($expected);
        $this->assertSame($expected, $entries);

        $config = $this->config(['zero_mcp/general/admin_confirm' => $this->defaultList()]);
        foreach (['ship_order', 'set_settings', 'send_fiscal_receipts', 'cancel_order', 'invoice_order'] as $tool) {
            $this->assertTrue($config->confirmsInAdmin($tool), $tool);
        }
        $this->assertFalse($config->confirmsInAdmin('hold_order'));
        $this->assertSame(['price', 'special_price', 'qty'], $config->adminConfirmFields('update_product'));
        $this->assertSame(['price', 'special_price'], $config->adminConfirmFields('schedule_change'));
    }

    public function testTheDefaultRegistrationLimitIs30(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/config.xml');
        $this->assertSame('30', (string) $xml->default->zero_mcp->general->registrations_per_hour);
    }

    public function testAFieldEntryMatchesOnlyWhenTheFieldIsGiven(): void
    {
        $config = $this->config(['zero_mcp/general/admin_confirm' => ' ship_order , update_product:price,update_product:qty ']);

        $this->assertTrue($config->confirmsInAdmin('update_product', ['sku' => 'MH01', 'price' => 10]));
        $this->assertTrue($config->confirmsInAdmin('update_product', ['sku' => 'MH01', 'qty' => 0]));
        $this->assertFalse($config->confirmsInAdmin('update_product', ['sku' => 'MH01', 'description' => '<p>x</p>']));
        $this->assertFalse($config->confirmsInAdmin('update_product', ['sku' => 'MH01', 'special_price' => 5]), 'special_price is not listed');
        $this->assertFalse($config->confirmsInAdmin('update_product'), 'no input: the whole tool is not listed');
        $this->assertTrue($config->confirmsInAdmin('ship_order'), 'entries are trimmed');
    }

    public function testAFieldGivenAsNullStillCounts(): void
    {
        $config = $this->config(['zero_mcp/general/admin_confirm' => 'update_product:special_price']);

        // Removing the special price is a price change too.
        $this->assertTrue($config->confirmsInAdmin('update_product', ['sku' => 'MH01', 'special_price' => null]));
    }

    public function testScheduleChangeFieldsAreReadFromItsFields(): void
    {
        $config = $this->config(['zero_mcp/general/admin_confirm' => 'schedule_change:price']);

        $this->assertTrue($config->confirmsInAdmin('schedule_change', ['sku' => 'MH01', 'fields' => ['price' => 9]]));
        $this->assertFalse($config->confirmsInAdmin('schedule_change', ['sku' => 'MH01', 'price' => 9, 'fields' => ['name' => 'Sale']]), 'a top-level price is not the change');
        $this->assertFalse($config->confirmsInAdmin('schedule_change', ['sku' => 'MH01']));
    }

    public function testAnEntryForAFieldNotInFieldKindsIsIgnored(): void
    {
        $config = $this->config(['zero_mcp/general/admin_confirm' => 'update_product:name,ship_order:notify,bogus']);

        $this->assertSame([], $config->adminConfirmFields('update_product'));
        $this->assertSame([], $config->adminConfirmFields('ship_order'));
        $this->assertFalse($config->confirmsInAdmin('update_product', ['name' => 'x']));
        $this->assertFalse($config->confirmsInAdmin('ship_order'));
    }

    public function testRegistrationsPerHourFallsBackTo30(): void
    {
        $this->assertSame(30, $this->config([])->registrationsPerHour());
        $this->assertSame(30, $this->config(['zero_mcp/general/registrations_per_hour' => '0'])->registrationsPerHour());
        $this->assertSame(30, $this->config(['zero_mcp/general/registrations_per_hour' => '-5'])->registrationsPerHour());
        $this->assertSame(12, $this->config(['zero_mcp/general/registrations_per_hour' => '12'])->registrationsPerHour());
    }
}
