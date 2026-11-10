<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Adminhtml\Change;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Zero\AiAssistant\Model\Agent;
use Zero\Mcp\Model\WaitingChanges;

/**
 * Confirm or Cancel a change from Claude, by the admin in the admin: the
 * one decision Claude itself cannot make. The admin's own change only;
 * the role is checked again as in the admin chat (Agent with the
 * session's permissions).
 */
class Decide extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_AiAssistant::chat';

    public function __construct(
        Context $context,
        private readonly WaitingChanges $waiting,
        private readonly Agent $agent
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $back = $this->resultRedirectFactory->create()->setPath('zero_mcp/change/index');
        $actionId = (int) $this->getRequest()->getParam('action_id');
        $userId = (int) $this->_auth->getUser()->getId();
        if ($this->waiting->get($actionId, $userId) === null) {
            $this->messageManager->addErrorMessage(__('Change #%1 is not waiting for you any more.', $actionId));
            return $back;
        }
        if ($this->getRequest()->getParam('decision') !== 'confirm') {
            $this->agent->cancel($actionId, $userId);
            $this->messageManager->addSuccessMessage(__('Change #%1 cancelled.', $actionId));
            return $back;
        }
        set_time_limit(300);
        $result = $this->agent->confirm($actionId, $userId);
        if ($result['status'] === 'done') {
            $this->messageManager->addSuccessMessage(__('Change #%1 done. Claude sees it when you go back to the conversation.', $actionId));
        } else {
            $this->messageManager->addErrorMessage(__('Change #%1: %2', $actionId, is_string($result['result']) && $result['result'] !== '' ? $result['result'] : $result['status']));
        }
        return $back;
    }
}
