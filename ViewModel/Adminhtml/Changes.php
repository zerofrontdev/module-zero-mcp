<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\ViewModel\Adminhtml;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Mcp\Model\WaitingChanges;

/**
 * Changes Waiting for You: the signed-in admin's changes from Claude.
 */
class Changes implements ArgumentInterface
{
    public function __construct(
        private readonly WaitingChanges $waiting,
        private readonly Session $session,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @return array<int, array>
     */
    public function changes(): array
    {
        return $this->waiting->forUser((int) $this->session->getUser()?->getId());
    }

    public function url(string $route, array $params = []): string
    {
        return $this->url->getUrl($route, $params);
    }

    public function formKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function format(string $utc): string
    {
        return $this->timezone->formatDateTime($utc . ' UTC', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
    }
}
