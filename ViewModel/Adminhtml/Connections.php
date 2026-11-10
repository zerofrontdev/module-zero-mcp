<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\WaitingChanges;
use Zero\Mcp\Model\Oauth\Tokens;

/**
 * System > Other Settings > Claude Connections: every connection (an admin user on
 * a device), what it did last, Revoke; and how to add the store in Claude.
 */
class Connections implements ArgumentInterface
{
    public function __construct(
        private readonly Tokens $tokens,
        private readonly Config $config,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly TimezoneInterface $timezone,
        private readonly WaitingChanges $waiting,
        private readonly AuthSession $session
    ) {
    }

    /**
     * The signed-in admin's changes from Claude waiting in Changes Waiting for You.
     */
    public function waiting(): int
    {
        return $this->waiting->count((int) $this->session->getUser()?->getId());
    }

    /**
     * @return array<int, array>
     */
    public function connections(): array
    {
        return $this->tokens->all();
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function serverUrl(): string
    {
        return $this->config->serverUrl();
    }

    public function url(string $route, array $params = []): string
    {
        return $this->url->getUrl($route, $params);
    }

    public function formKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function format(?string $utc): string
    {
        return $utc ? $this->timezone->formatDateTime($utc . ' UTC', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) : '';
    }

    /**
     * @return string live, expired or revoked
     */
    public function status(array $connection): string
    {
        if ($connection['revoked_at'] !== null) {
            return 'revoked';
        }
        return strtotime($connection['refresh_expires_at'] . ' UTC') < time() ? 'expired' : 'live';
    }
}
