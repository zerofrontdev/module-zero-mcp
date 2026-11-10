<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Plugin;

use Magento\Backend\App\AbstractAction;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\Url;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;

/**
 * Claude's links into the admin survive the admin's sign-in (Changes Waiting
 * for You, a bulk job's page): Magento sends a signed-in admin (after two-step
 * verification too) to the startup page, so the link is remembered in the
 * session before the sign-in and the startup page is that page once; reaching
 * the page signed in forgets it. Runs before Magento's own authentication plugin.
 */
class AdminDeepLink
{
    public const SESSION = 'zero_mcp_after_login';
    private const PAGES = ['zero_mcp_change_index' => 'zero_mcp/change/index', 'zero_ai_job_index' => 'zero_ai/job/index'];
    private const JOB_PAGE = '#^zero_ai/job/index/job_id/\d+\z#';

    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session
    ) {
    }

    public function beforeDispatch(AbstractAction $subject, RequestInterface $request): void
    {
        if (!$request instanceof HttpRequest || !isset(self::PAGES[$request->getFullActionName()])) {
            return;
        }
        if ($this->auth->isLoggedIn()) {
            $this->session->unsetData(self::SESSION);
            return;
        }
        if ($request->isGet()) {
            $page = self::PAGES[$request->getFullActionName()];
            if ($request->getFullActionName() === 'zero_ai_job_index') {
                $page .= '/job_id/' . (int) $request->getParam('job_id');
            }
            $this->session->setData(self::SESSION, $page);
        }
    }

    public function afterGetStartupPageUrl(Url $subject, string $result): string
    {
        $page = $this->auth->isLoggedIn() ? $this->session->getData(self::SESSION) : null;
        if (!is_string($page)) {
            return $result;
        }
        return in_array($page, self::PAGES, true) || preg_match(self::JOB_PAGE, $page) ? $page : $result;
    }
}
