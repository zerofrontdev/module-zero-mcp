<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\User\Model\User;
use Zero\Mcp\Model\Oauth\Tokens;

/**
 * An admin user removed or deactivated: their Claude connections end at
 * once, not at their next call.
 */
class RevokeUserConnections implements ObserverInterface
{
    public function __construct(
        private readonly Tokens $tokens
    ) {
    }

    public function execute(Observer $observer): void
    {
        $user = $observer->getEvent()->getData('object');
        if (!$user instanceof User || !$user->getId()) {
            return;
        }
        if ($observer->getEvent()->getName() === 'admin_user_delete_after' || !(int) $user->getIsActive()) {
            $this->tokens->revokeUser((int) $user->getId());
        }
    }
}
