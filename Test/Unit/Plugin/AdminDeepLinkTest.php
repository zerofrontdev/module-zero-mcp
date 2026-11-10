<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Plugin;

use Magento\Backend\App\AbstractAction;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\Url;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\Session\Storage;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Plugin\AdminDeepLink;

/**
 * Claude's link to a bulk job's page survives the sign-in: remembered with its job id (an int), used once as the
 * startup page, forgotten once the admin reaches a page signed in; nothing else is accepted as the startup page.
 */
class AdminDeepLinkTest extends TestCase
{
    /** @var Storage the session's data */
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage();
    }

    private function remember(string $page): void
    {
        $this->storage->setData(AdminDeepLink::SESSION, $page);
    }

    private function plugin(bool $loggedIn): AdminDeepLink
    {
        $auth = $this->createStub(Auth::class);
        $auth->method('isLoggedIn')->willReturn($loggedIn);
        // The admin session as Magento has it (its data goes to its storage through __call), without starting a session.
        $session = (new \ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SessionManager::class, 'storage'))->setValue($session, $this->storage);
        return new AdminDeepLink($auth, $session);
    }

    private function request(string $action, array $params = [], bool $get = true): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($action);
        $request->method('isGet')->willReturn($get);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
        return $request;
    }

    public function testTheJobLinkIsRememberedWithItsJobIdAsAnInt(): void
    {
        $this->plugin(false)->beforeDispatch($this->createStub(AbstractAction::class), $this->request('zero_ai_job_index', ['job_id' => '12abc/../x']));

        $this->assertSame('zero_ai/job/index/job_id/12', $this->storage->getData(AdminDeepLink::SESSION));
    }

    public function testAPostOrAnotherPageIsNotRemembered(): void
    {
        $plugin = $this->plugin(false);
        $plugin->beforeDispatch($this->createStub(AbstractAction::class), $this->request('zero_ai_job_index', ['job_id' => '12'], false));
        $plugin->beforeDispatch($this->createStub(AbstractAction::class), $this->request('sales_order_index'));

        $this->assertSame([], $this->storage->getData());
    }

    public function testAfterTheSignInTheStartupPageIsTheJobPage(): void
    {
        $this->remember('zero_ai/job/index/job_id/12');

        $this->assertSame('zero_ai/job/index/job_id/12', $this->plugin(true)->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard'));
    }

    public function testTheChangesPageStillWorks(): void
    {
        $this->remember('zero_mcp/change/index');

        $this->assertSame('zero_mcp/change/index', $this->plugin(true)->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard'));
    }

    public function testOnlyAKnownPageIsAccepted(): void
    {
        foreach ([
            "zero_ai/job/index/job_id/12\n",
            'zero_ai/job/index/job_id/12/../../admin/user',
            'zero_ai/job/index/job_id/',
            'zero_ai/job/index/job_id/-1',
            'https://evil.example/zero_ai/job/index/job_id/1',
            'admin/user/index',
        ] as $page) {
            $this->remember($page);
            $this->assertSame('admin/dashboard', $this->plugin(true)->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard'), json_encode($page));
        }
    }

    public function testBeforeTheSignInTheStartupPageIsUnchanged(): void
    {
        $this->remember('zero_ai/job/index/job_id/12');

        $this->assertSame('admin/dashboard', $this->plugin(false)->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard'));
    }

    public function testReachingThePageSignedInForgetsTheLink(): void
    {
        $this->remember('zero_ai/job/index/job_id/12');
        $plugin = $this->plugin(true);

        $plugin->beforeDispatch($this->createStub(AbstractAction::class), $this->request('zero_ai_job_index', ['job_id' => '12']));

        $this->assertArrayNotHasKey(AdminDeepLink::SESSION, $this->storage->getData());
        $this->assertSame('admin/dashboard', $plugin->afterGetStartupPageUrl($this->createStub(Url::class), 'admin/dashboard'));
    }
}
