<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Commands;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Connection;
use Zero\Mcp\Model\Oauth\Tokens;
use Zero\Mcp\Model\Server;
use Zero\Mcp\Model\Server\Docs;
use Zero\Mcp\Model\Server\ToolSet;

/**
 * JSON-RPC dispatch: initialize, the lists, a call counted against the
 * hour's limit, prompts from the commands, notifications unanswered,
 * unknown methods and bad messages refused.
 */
#[AllowMockObjectsWithoutExpectations]
class ServerTest extends TestCase
{
    private ToolSet&MockObject $tools;
    private Tokens&MockObject $tokens;
    private Connection&MockObject $connection;
    private Server $server;

    protected function setUp(): void
    {
        $this->tools = $this->createMock(ToolSet::class);
        $this->tools->method('list')->willReturn([['name' => 'search_products']]);
        $this->tools->method('call')->willReturnCallback(fn ($c, $name, $args) => ['content' => [['type' => 'text', 'text' => $name . ':' . json_encode($args)]]]);
        $docs = $this->createMock(Docs::class);
        $docs->method('list')->willReturn([['uri' => 'zero://docs/Mcp', 'name' => 'Zero_Mcp', 'title' => 't', 'description' => 'd', 'mimeType' => 'text/markdown']]);
        $docs->method('read')->willReturnCallback(fn ($uri) => $uri === 'zero://docs/Mcp' ? '# Zero_Mcp' : null);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $path === 'zero_mcp/general/calls_per_hour' ? 2 : null);
        $this->tokens = $this->createMock(Tokens::class);
        $this->tokens->method('count')->willReturnCallback(fn ($id, $action, $perHour) => $perHour === 2 && $action !== 'over_limit');
        $commands = $this->createStub(Commands::class);
        $commands->method('allowed')->willReturn(['orders' => ['label' => 'Show orders', 'text' => 'Show the orders[ of {args}].', 'example' => 'today']]);
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('tokenId')->willReturn(7);
        $this->connection->method('commands')->willReturn($commands);
        $this->server = new Server($this->tools, $docs, new Config($scopeConfig, $this->createStub(StoreManagerInterface::class)), $this->tokens, $this->createStub(LoggerInterface::class));
    }

    public function testInitializeAndTheLists(): void
    {
        $init = $this->server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']], $this->connection);
        $this->assertSame('2025-11-25', $init['result']['protocolVersion']);
        $this->assertSame('Zero for Magento', $init['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);
        $old = $this->server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']], $this->connection);
        $this->assertSame(Server::PROTOCOL, $old['result']['protocolVersion'], 'an unknown version gets ours');

        $tools = $this->server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $this->connection);
        $this->assertSame('search_products', $tools['result']['tools'][0]['name']);
        $prompts = $this->server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'], $this->connection);
        $this->assertSame('orders', $prompts['result']['prompts'][0]['name']);
        $prompt = $this->server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/get', 'params' => ['name' => 'orders', 'arguments' => ['args' => 'today']]], $this->connection);
        $this->assertSame('Show the orders of today.', $prompt['result']['messages'][0]['content']['text']);
        $doc = $this->server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/read', 'params' => ['uri' => 'zero://docs/Mcp']], $this->connection);
        $this->assertSame('# Zero_Mcp', $doc['result']['contents'][0]['text']);
        $missing = $this->server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'zero://docs/Nope']], $this->connection);
        $this->assertSame(-32601, $missing['error']['code']);
    }

    public function testACallIsCountedAndRefusedOverTheLimit(): void
    {
        $call = $this->server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'search_products', 'arguments' => ['query' => 'bag']]], $this->connection);
        $this->assertSame('search_products:{"query":"bag"}', $call['result']['content'][0]['text']);
        $over = $this->server->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'over_limit']], $this->connection);
        $this->assertTrue($over['result']['isError']);
        $this->assertStringContainsString('calls for this hour', $over['result']['content'][0]['text']);
    }

    public function testNotificationsBatchesAndBadMessages(): void
    {
        $this->assertNull($this->server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->connection));
        $batch = $this->server->handle([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope'],
        ], $this->connection);
        $this->assertCount(2, $batch);
        $this->assertSame(-32601, $batch[1]['error']['code']);
        $bad = $this->server->handle(['id' => 1, 'method' => 'ping'], $this->connection);
        $this->assertSame(-32600, $bad['error']['code']);
    }
}
