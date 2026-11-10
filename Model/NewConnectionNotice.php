<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A new Claude connection told at once: a message in the admin's
 * notifications (the bell) and an email to the store (Notify of New
 * Connections; the store's general contact unless another address is set).
 * A stolen admin password that connects Claude is seen the same minute.
 */
class NewConnectionNotice
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly NotifierInterface $notifier,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notify(string $user, string $client, string $ip, string $browser): void
    {
        if (!$this->scopeConfig->isSetFlag('zero_mcp/general/notify_new')) {
            return;
        }
        $url = $this->backendUrl->getUrl('zero_mcp/connection/index');
        $time = gmdate('Y-m-d H:i') . ' UTC';
        $this->notifier->addMajor(
            (string) __('New Claude connection: %1 (%2)', $user, $client),
            (string) __('%1 allowed %2 to connect as their admin user, %3 from %4. Revoke it in System > Other Settings > Claude Connections if this was not them.', $user, $client, $time, $ip),
            $url
        );
        $to = trim((string) $this->scopeConfig->getValue('zero_mcp/general/notify_email'))
            ?: (string) $this->scopeConfig->getValue('trans_email/ident_general/email');
        if ($to === '') {
            return;
        }
        $store = $this->storeManager->getDefaultStoreView();
        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier('zero_mcp_general_new_connection_template')
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars([
                    'user' => $user,
                    'client' => $client,
                    'time' => $time,
                    'ip' => $ip,
                    'browser' => mb_substr($browser, 0, 200),
                    'connections_url' => $url,
                    'store_name' => (string) ($store?->getFrontendName() ?: $store?->getName()),
                ])
                ->setFromByScope('general')
                ->addTo($to)
                ->getTransport()
                ->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Zero_Mcp: the new connection email was not sent: ' . $e->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
