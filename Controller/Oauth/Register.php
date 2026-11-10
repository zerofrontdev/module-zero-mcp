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
use Zero\Mcp\Model\Oauth\ClientAddress;
use Zero\Mcp\Model\Oauth\Clients;
use Zero\Mcp\Model\Oauth\OauthException;
use Zero\Mcp\Model\Oauth\RegistrationLimit;

/**
 * Dynamic Client Registration (RFC 7591): Claude registers itself as a
 * public client with its callback URIs; JSON in, JSON out, 201. Every POST
 * counts against the address's and the store's hourly limits (RegistrationLimit,
 * ClientAddress), valid or not.
 */
class Register extends Endpoint
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Config $config,
        private readonly Clients $clients,
        private readonly RegistrationLimit $limit,
        private readonly ClientAddress $clientAddress
    ) {
        parent::__construct($request, $rawFactory, $config);
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return $this->off();
        }
        if (!$this->http()->isPost()) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'POST a registration request.'], 405);
        }
        if (!$this->limit->allow($this->clientAddress->get())) {
            return $this->json([
                'error' => 'temporarily_unavailable',
                'error_description' => 'Too many client registrations from this address; try again later.',
            ], 429, ['Retry-After' => (string) $this->limit->retryAfter()]);
        }
        try {
            return $this->json($this->clients->register($this->body()), 201);
        } catch (OauthException $e) {
            return $this->json($e->body(), $e->status);
        }
    }
}
