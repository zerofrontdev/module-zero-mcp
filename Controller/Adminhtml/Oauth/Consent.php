<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Adminhtml\Oauth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\NewConnectionNotice;
use Zero\Mcp\Model\Oauth\Clients;
use Zero\Mcp\Model\Oauth\OauthException;
use Zero\Mcp\Model\Oauth\Pending;
use Zero\Mcp\Model\Oauth\Service;

/**
 * Where the admin allows a connection Claude asked for, signed in to the
 * admin as for any other page. The request comes from the cookie of the
 * browser that opened the connect page (Observer\PendingConsent brings the
 * admin here after sign-in) or from the code on that page, typed here; it
 * is then kept in the admin session, never taken from the form. Allow
 * sends the code to the client, Deny an access_denied; either takes the
 * request, so it is used once.
 */
class Consent extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    private const SESSION = 'zero_mcp_pending_id';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Pending $pending,
        private readonly Service $service,
        private readonly Clients $clients,
        private readonly CookieManagerInterface $cookies,
        private readonly CookieMetadataFactory $cookieMetadata,
        private readonly TimezoneInterface $timezone,
        private readonly NewConnectionNotice $notice,
        private readonly RemoteAddress $remoteAddress
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $key = (string) $this->cookies->getCookie(Pending::COOKIE);
        if ($key !== '') {
            $this->cookies->deleteCookie(Pending::COOKIE, $this->cookieMetadata->createCookieMetadata()->setPath('/'));
            $row = $this->pending->byKey($key);
            if ($row !== null) {
                $this->_session->setData(self::SESSION, (int) $row['pending_id']);
            }
        }
        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('The Claude connector is off: Stores > Configuration > Zero > AI Assistant > Claude Connector.'));
            return $this->resultRedirectFactory->create()->setPath('adminhtml/dashboard/index');
        }
        if ($this->getRequest()->isPost()) {
            return $this->post();
        }
        $id = (int) $this->_session->getData(self::SESSION);
        $row = $id > 0 ? $this->pending->get($id) : null;
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_Mcp::connections');
        $page->getConfig()->getTitle()->prepend(__('Connect Claude'));
        $page->getLayout()->getBlock('zero.mcp.consent')?->setData('request', $row === null ? null : [
            'pending_id' => (int) $row['pending_id'],
            'client_name' => (string) ($this->clients->get((string) $row['client_id'])['client_name'] ?? $row['client_id']),
            'redirect_host' => (string) (parse_url((string) $row['redirect_uri'], PHP_URL_HOST) ?: $row['redirect_uri']),
            'expires' => $this->timezone->formatDateTime($row['expires_at'] . ' UTC', \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT),
            'user' => (string) $this->_auth->getUser()?->getUserName(),
            'allowed' => $this->_authorization->isAllowed('Zero_AiAssistant::chat'),
        ]);
        return $page;
    }

    private function post()
    {
        $request = $this->getRequest();
        $back = $this->resultRedirectFactory->create()->setPath('zero_mcp/oauth/consent');
        $code = trim((string) $request->getParam('code', ''));
        if ($code !== '') {
            $row = $this->pending->byCode($code);
            if ($row === null) {
                $this->messageManager->addErrorMessage(__('No request has this code. It may have expired: requests last %1 minutes.', (int) (Pending::TTL / 60)));
            } else {
                $this->_session->setData(self::SESSION, (int) $row['pending_id']);
            }
            return $back;
        }
        $id = (int) $this->_session->getData(self::SESSION);
        if ($id === 0 || (int) $request->getParam('pending_id') !== $id) {
            $this->messageManager->addErrorMessage(__('This request is not the one waiting any more: check it again.'));
            return $back;
        }
        $this->_session->unsetData(self::SESSION);
        $row = $this->pending->take($id);
        if ($row === null) {
            $this->messageManager->addErrorMessage(__('The request expired. In Claude, connect again.'));
            return $back;
        }
        $url = $this->service->deny($row);
        if ($request->getParam('decision') === 'allow') {
            try {
                $url = $this->service->approve($row, (int) $this->_auth->getUser()->getId());
                $user = $this->_auth->getUser();
                $this->notice->notify(
                    trim($user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getUserName() . ')'),
                    (string) ($this->clients->get((string) $row['client_id'])['client_name'] ?? $row['client_id']),
                    (string) $this->remoteAddress->getRemoteAddress(),
                    (string) $request->getHeader('User-Agent')
                );
            } catch (OauthException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
                return $back;
            }
        }
        return $this->resultRedirectFactory->create()->setUrl($url);
    }
}
