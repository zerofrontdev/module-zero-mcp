<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Zero\AiAssistant\Model\Tools;
use Zero\Mcp\Model\Config;

/**
 * The assistant's changing tools of the enabled modules, for Confirm in
 * the Admin, each followed by its field entries (Config::FIELD_KINDS:
 * "update_product: price" stands for the value update_product:price).
 */
class ChangingTools implements OptionSourceInterface
{
    public function __construct(
        private readonly Tools $tools
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->tools->changingNames() as $name) {
            $options[] = ['value' => $name, 'label' => $name];
            foreach (Config::FIELD_KINDS[$name] ?? [] as $field) {
                $options[] = ['value' => $name . ':' . $field, 'label' => $name . ': ' . $field];
            }
        }
        return $options;
    }
}
