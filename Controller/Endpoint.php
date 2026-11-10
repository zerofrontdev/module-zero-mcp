<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Zero\Mcp\Model\Config;

/**
 * An endpoint of the connector: JSON in and out, no session, no form key
 * (a bearer token, a one-time code or a signed key instead), never
 * cached. Off when the connector is off.
 */
abstract class Endpoint implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly RawFactory $rawFactory,
        protected readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    protected function http(): HttpRequest
    {
        /** @var HttpRequest $request */
        $request = $this->request;
        return $request;
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): Raw
    {
        $raw = $this->raw((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, 'application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            $raw->setHeader($name, $value, true);
        }
        return $raw;
    }

    protected function raw(string $body, int $status, string $type, bool $cacheable = false): Raw
    {
        $raw = $this->rawFactory->create();
        $raw->setHttpResponseCode($status)->setHeader('Content-Type', $type, true)->setContents($body);
        $raw->setHeader('Cache-Control', $cacheable ? 'public, max-age=300' : 'no-store', true);
        $raw->setHeader('X-Robots-Tag', 'noindex', true);
        return $raw;
    }

    protected function off(): Raw
    {
        return $this->json(['error' => 'not_found', 'error_description' => 'The Claude connector is off on this store.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $content = (string) $this->http()->getContent();
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }
}
