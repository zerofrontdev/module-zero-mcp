<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Adminhtml\Change;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Zero\Mcp\Plugin\AdminDeepLink;

/**
 * Changes from Claude waiting for the admin: the ones the store confirms
 * in the admin only (Confirm in the Admin), with Confirm and Cancel
 * (Decide). Claude links here, so this page opens without the URL's
 * secret key (it only shows; Decide posts with the form key), and after
 * the admin's sign-in (AdminDeepLink).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_AiAssistant::chat';

    public function execute()
    {
        $this->_session->unsetData(AdminDeepLink::SESSION);
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_Mcp::connections');
        $page->getConfig()->getTitle()->prepend(__('Changes Waiting for You'));
        return $page;
    }

    /**
     * @inheritDoc
     */
    protected function _validateSecretKey()
    {
        return true;
    }
}
