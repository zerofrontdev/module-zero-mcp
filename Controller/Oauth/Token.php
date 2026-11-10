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
use Zero\Mcp\Model\Oauth\OauthException;
use Zero\Mcp\Model\Oauth\Service;

/**
 * The token endpoint: a code (with its PKCE verifier) or a refresh token,
 * form-encoded, for an access token and a new refresh token.
 */
class Token extends Endpoint
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
        if (!$this->http()->isPost()) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'POST the grant.'], 405);
        }
        $post = (array) $this->http()->getPostValue();
        if (!$post) {
            $post = $this->body();
        }
        try {
            return $this->json($this->service->token($post), 200, ['Pragma' => 'no-cache']);
        } catch (OauthException $e) {
            return $this->json($e->body(), $e->status);
        }
    }
}
