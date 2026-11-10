<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores > Configuration > Zero > AI Assistant > Claude Connector, and the
 * URLs the connector lives at: the MCP endpoint /mcp and the OAuth
 * endpoints, all on the default store view's secure base URL.
 */
class Config
{
    public const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    public const SCOPE = 'store';

    /**
     * The fields Confirm in the Admin can name per tool (tool:field entries).
     */
    public const FIELD_KINDS = [
        'update_product' => ['price', 'special_price', 'qty'],
        'schedule_change' => ['price', 'special_price'],
    ];

    /**
     * Where a tool's input holds the fields it changes, when not at its top level.
     */
    private const FIELD_CONTAINER = ['schedule_change' => 'fields'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('zero_mcp/general/enabled');
    }

    public function accessSeconds(): int
    {
        return max(1, (int) $this->scopeConfig->getValue('zero_mcp/general/access_hours')) * 3600;
    }

    public function refreshSeconds(): int
    {
        return max(1, (int) $this->scopeConfig->getValue('zero_mcp/general/refresh_days')) * 86400;
    }

    public function callsPerHour(): int
    {
        return max(1, (int) $this->scopeConfig->getValue('zero_mcp/general/calls_per_hour'));
    }

    /**
     * Whether the connect page links to the admin. Off by default: the
     * page is public, and the link would show the admin's address to
     * anyone who opens it.
     */
    public function linksToAdmin(): bool
    {
        return $this->scopeConfig->isSetFlag('zero_mcp/general/admin_link');
    }

    /**
     * Registrations of OAuth clients allowed per address and clock hour (RegistrationLimit); 30 when not set.
     */
    public function registrationsPerHour(): int
    {
        $value = (int) $this->scopeConfig->getValue('zero_mcp/general/registrations_per_hour');
        return $value > 0 ? $value : 30;
    }

    /**
     * Whether the admin confirms this change in the admin (Changes Waiting for You), never Claude with
     * confirm_change: money, customers' emails, settings, shipping, prices, what cannot be put back.
     * Confirm in the Admin lists tools ("ship_order": every change of it) and tool:field entries
     * ("update_product:price": a change of it that gives that field; schedule_change's are under its "fields").
     *
     * @param array<string, mixed> $input the change's input, for the field entries
     */
    public function confirmsInAdmin(string $tool, array $input = []): bool
    {
        $entries = $this->adminConfirmEntries();
        if (in_array($tool, $entries, true)) {
            return true;
        }
        $given = self::FIELD_CONTAINER[$tool] ?? null;
        $given = (array) ($given === null ? $input : ($input[$given] ?? []));
        foreach ($this->adminConfirmFields($tool) as $field) {
            if (array_key_exists($field, $given)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fields of this tool whose changes are confirmed in the admin (its tool:field entries).
     *
     * @return string[]
     */
    public function adminConfirmFields(string $tool): array
    {
        $fields = [];
        foreach ($this->adminConfirmEntries() as $entry) {
            [$name, $field] = array_pad(explode(':', $entry, 2), 2, '');
            if ($name === $tool && in_array($field, self::FIELD_KINDS[$tool] ?? [], true)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Confirm in the Admin as a list of tool and tool:field entries.
     *
     * @return string[]
     */
    private function adminConfirmEntries(): array
    {
        $entries = array_map('trim', explode(',', (string) $this->scopeConfig->getValue('zero_mcp/general/admin_confirm')));
        return array_values(array_filter($entries, static fn (string $entry) => $entry !== ''));
    }

    /**
     * @return string[]
     */
    public function extraRedirectUris(): array
    {
        $lines = preg_split('/\R/', (string) $this->scopeConfig->getValue('zero_mcp/general/extra_redirect_uris')) ?: [];
        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * "https://demo.zerofront.dev": the issuer, and what every URL hangs from.
     */
    public function origin(): string
    {
        $base = (string) $this->storeManager->getDefaultStoreView()?->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * The URL the admin enters in Claude.
     */
    public function serverUrl(): string
    {
        return $this->origin() . '/mcp';
    }

    public function url(string $path): string
    {
        return $this->origin() . '/' . ltrim($path, '/');
    }
}
