<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Oauth;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Zero\Mcp\Controller\Endpoint;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\Service;

/**
 * /.well-known/oauth-authorization-server: where the OAuth endpoints are.
 */
class Metadata extends Endpoint
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Config $config,
        private readonly Service $service
    ) {
        parent::__construct($request, $rawFactory, $config);
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return $this->off();
        }
        return $this->json($this->service->metadata(), 200, ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }
}
