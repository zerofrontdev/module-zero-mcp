<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Zero\Mcp\Model\Oauth\Tokens;

/**
 * Revoke a connection: its tokens stop working at once; Claude asks the
 * admin to sign in again.
 */
class Revoke extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_Mcp::connections';

    public function __construct(Action\Context $context, private readonly Tokens $tokens)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($this->tokens->get($id)) {
            $this->tokens->revoke($id);
            $this->messageManager->addSuccessMessage(__('Connection #%1 is revoked.', $id));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
