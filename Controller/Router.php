<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Zero\Mcp\Model\Config;

/**
 * The connector's root-level paths, as MCP clients expect them:
 * - /mcp: the MCP endpoint;
 * - /.well-known/oauth-protected-resource[/mcp]: which authorization server;
 * - /.well-known/oauth-authorization-server: the OAuth endpoints.
 * Everything else of the connector is under /zero_mcp/… (the standard router).
 */
class Router implements RouterInterface
{
    private const PATHS = [
        'mcp' => ['server', 'index'],
        '.well-known/oauth-protected-resource' => ['oauth', 'prm'],
        '.well-known/oauth-protected-resource/mcp' => ['oauth', 'prm'],
        '.well-known/oauth-authorization-server' => ['oauth', 'metadata'],
        '.well-known/openid-configuration' => ['oauth', 'metadata'],
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function match(RequestInterface $request)
    {
        if (!$request instanceof HttpRequest || $request->getModuleName() === 'zero_mcp') {
            return null;
        }
        $path = trim((string) $request->getPathInfo(), '/');
        if (!isset(self::PATHS[$path]) || !$this->config->isEnabled()) {
            return null;
        }
        [$controller, $action] = self::PATHS[$path];
        $request->setModuleName('zero_mcp')->setControllerName($controller)->setActionName($action)->setPathInfo('/zero_mcp/' . $controller . '/' . $action);
        $request->setAlias(\Magento\Framework\UrlInterface::REWRITE_REQUEST_PATH_ALIAS, $path);
        /** @var ActionInterface $forward */
        $forward = $this->actionFactory->create(Forward::class);
        return $forward;
    }
}
