<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tools;
use Zero\Mcp\Model\Config\Source\ChangingTools;

/**
 * Confirm in the Admin offers each changing tool and, after it, its field entries, so saving the multiselect keeps
 * the tool:field values of the default.
 */
class ChangingToolsTest extends TestCase
{
    public function testFieldEntriesFollowTheirTool(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('changingNames')->willReturn(['schedule_change', 'ship_order', 'update_product']);

        $this->assertSame([
            ['value' => 'schedule_change', 'label' => 'schedule_change'],
            ['value' => 'schedule_change:price', 'label' => 'schedule_change: price'],
            ['value' => 'schedule_change:special_price', 'label' => 'schedule_change: special_price'],
            ['value' => 'ship_order', 'label' => 'ship_order'],
            ['value' => 'update_product', 'label' => 'update_product'],
            ['value' => 'update_product:price', 'label' => 'update_product: price'],
            ['value' => 'update_product:special_price', 'label' => 'update_product: special_price'],
            ['value' => 'update_product:qty', 'label' => 'update_product: qty'],
        ], (new ChangingTools($tools))->toOptionArray());
    }

    public function testAToolOfADisabledModuleOffersNoFields(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('changingNames')->willReturn(['ship_order']);

        $this->assertSame([['value' => 'ship_order', 'label' => 'ship_order']], (new ChangingTools($tools))->toOptionArray());
    }
}
