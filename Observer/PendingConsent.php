<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Observer;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Zero\Mcp\Model\Oauth\Pending;

/**
 * An admin who opened Claude's connect page in this browser lands on the
 * request after signing in: the admin's own sign-in (and two-step
 * verification, which ends on the startup page) runs first, then any
 * admin page they open goes to Connect Claude while the request waits.
 * Only when the browser has the connect page's cookie.
 */
class PendingConsent implements ObserverInterface
{
    private const SKIP = ['zero_mcp_oauth_consent', 'adminhtml_auth_', 'tfa_', 'mui_', 'adminhtml_noroute'];

    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly Pending $pending,
        private readonly Auth $auth,
        private readonly UrlInterface $url,
        private readonly ActionFlag $actionFlag,
        private readonly ResponseInterface $response
    ) {
    }

    public function execute(Observer $observer): void
    {
        $key = $this->cookies->getCookie(Pending::COOKIE);
        // A cookie sent as an array (name[a]=1) counts as none.
        $key = is_string($key) ? $key : '';
        /** @var HttpRequest $request */
        $request = $observer->getEvent()->getData('request');
        if ($key === '' || !$request instanceof HttpRequest || !$request->isGet() || $request->isAjax()
            || $this->actionFlag->get('', ActionInterface::FLAG_NO_DISPATCH) || !$this->auth->isLoggedIn()) {
            return;
        }
        $action = $request->getFullActionName();
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return;
            }
        }
        if ($this->pending->byKey($key) === null) {
            return;
        }
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($this->url->getUrl('zero_mcp/oauth/consent'));
        }
    }
}
