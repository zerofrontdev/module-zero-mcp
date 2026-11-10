<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Oauth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Zero\Mcp\Controller\Endpoint;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\OauthException;
use Zero\Mcp\Model\Oauth\Pending;
use Zero\Mcp\Model\Oauth\Service;
use Zero\Mcp\Model\Pages;

/**
 * The authorization endpoint: Claude sends the admin here. No password is
 * asked on the storefront: the request waits (Pending) for the admin to
 * allow it in the admin, behind the admin's own sign-in, two-step
 * verification, reCAPTCHA and lockout. This browser gets a cookie, so
 * signing in to the admin here leads straight to the request; the code on
 * the page finds it from anywhere else (Claude Connections).
 */
class Authorize extends Endpoint
{
    private const FIELDS = ['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'response_type', 'scope', 'resource'];

    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Config $config,
        private readonly Service $service,
        private readonly Pending $pending,
        private readonly Pages $pages,
        private readonly CookieManagerInterface $cookies,
        private readonly CookieMetadataFactory $cookieMetadata,
        private readonly BackendUrl $backendUrl
    ) {
        parent::__construct($request, $rawFactory, $config);
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return $this->raw($this->pages->error((string) __('Not available'), (string) __('The Claude connector is off on this store.')), 404, 'text/html; charset=utf-8');
        }
        $params = [];
        foreach (self::FIELDS as $field) {
            $params[$field] = $this->request->getParam($field, '');
            $params[$field] = is_scalar($params[$field]) ? (string) $params[$field] : '';
        }
        try {
            $authorization = $this->service->authorizationRequest($params);
        } catch (OauthException $e) {
            return $this->raw($this->pages->error((string) __('Cannot connect'), $e->getMessage()), 400, 'text/html; charset=utf-8');
        }
        if ($authorization['error'] !== null) {
            return $this->redirect(Service::redirect($authorization['redirect_uri'], ['error' => $authorization['error'], 'state' => $authorization['state']]));
        }
        $pending = $this->pending->create($authorization);
        $this->cookies->setPublicCookie(Pending::COOKIE, $pending['key'], $this->cookieMetadata->createPublicCookieMetadata()
            ->setPath('/')
            ->setDuration(Pending::TTL)
            ->setHttpOnly(true)
            ->setSecure($this->http()->isSecure())
            ->setSameSite('Lax'));
        $html = $this->pages->authorize([
            'client_name' => (string) $authorization['client']['client_name'],
            'redirect_host' => (string) (parse_url($authorization['redirect_uri'], PHP_URL_HOST) ?: $authorization['redirect_uri']),
            'code' => $pending['code'],
            'minutes' => (int) (Pending::TTL / 60),
            'admin_url' => $this->config->linksToAdmin() ? $this->backendUrl->getRouteUrl('adminhtml', ['_nosecret' => true]) : null,
        ]);
        return $this->raw($html, 200, 'text/html; charset=utf-8');
    }

    private function redirect(string $url)
    {
        $raw = $this->raw('', 302, 'text/plain');
        $raw->setHeader('Location', $url, true);
        return $raw;
    }
}
