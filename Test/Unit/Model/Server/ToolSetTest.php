<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Server;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use PHPUnit\Framework\TestCase;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Commands;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\Model\Uploads;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Connection;
use Zero\Mcp\Model\Drafts;
use Zero\Mcp\Model\Server\ToolSet;

class ToolSetTest extends TestCase
{
    public function testToolsWithoutParametersHaveAnObjectForProperties(): void
    {
        $noParameters = $this->createStub(ToolInterface::class);
        $noParameters->method('name')->willReturn('store_stats');
        $noParameters->method('description')->willReturn('Store numbers.');
        $noParameters->method('changes')->willReturn(false);
        $noParameters->method('schema')->willReturn(['type' => 'object', 'properties' => []]);
        $tools = $this->createStub(Tools::class);
        $tools->method('allowed')->willReturn([$noParameters]);
        $connection = $this->createStub(Connection::class);
        $connection->method('tools')->willReturn($tools);
        $set = new ToolSet(
            $this->createStub(Agent::class),
            $this->createStub(Conversations::class),
            $this->createStub(Uploads::class),
            $this->createStub(Drafts::class),
            $this->createStub(Config::class),
            $this->createStub(BackendUrl::class)
        );

        $json = (string) json_encode($set->list($connection));

        // claude.ai drops the whole tools/list when one schema has "properties": [] (a list, not an object).
        $this->assertStringNotContainsString('"properties":[]', $json);
        $this->assertStringContainsString('"name":"store_stats","title":"Store stats"', $json);
        $this->assertMatchesRegularExpression('/"name":"connection_info".*?"properties":\{\}/', $json);
        $this->assertStringContainsString('"properties":{"change_id":{"type":"integer"}}', $json);
    }

    public function testClaudeCannotConfirmWhatTheAdminConfirmsNorAnotherConnectionsChange(): void
    {
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('action')->willReturnMap([
            [5, 1, ['action_id' => 5, 'conversation_id' => 10, 'tool' => 'cancel_order', 'status' => 'proposed']],
            [6, 1, ['action_id' => 6, 'conversation_id' => 99, 'tool' => 'update_product', 'status' => 'proposed']],
        ]);
        $config = $this->createStub(Config::class);
        $config->method('confirmsInAdmin')->willReturnCallback(static fn (string $tool) => $tool === 'cancel_order');
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturn('https://store.example/admin/zero_mcp/change/index/');
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('confirm');
        $connection = $this->createStub(Connection::class);
        $connection->method('userId')->willReturn(1);
        $connection->method('conversationId')->willReturn(10);
        $set = new ToolSet($agent, $conversations, $this->createStub(Uploads::class), $this->createStub(Drafts::class), $config, $url);

        $inAdmin = $set->call($connection, 'confirm_change', ['change_id' => 5]);
        $this->assertArrayNotHasKey('isError', $inAdmin);
        $this->assertStringContainsString('confirmed by the admin in the store admin', $inAdmin['content'][0]['text']);
        $this->assertStringContainsString('https://store.example/admin/zero_mcp/change/index/', $inAdmin['content'][0]['text']);

        $other = $set->call($connection, 'confirm_change', ['change_id' => 6]);
        $this->assertTrue($other['isError']);
        $this->assertStringContainsString('No change #6 of this connection', $other['content'][0]['text']);
    }

    private const JOB_URL = 'https://store.example/admin/zero_ai/job/index/job_id/3/';

    private function url(): BackendUrl
    {
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route, array $params = []) => match (true) {
            $route === 'zero_ai/job/index' && ($params['_nosecret'] ?? false) === true => 'https://store.example/admin/zero_ai/job/index/job_id/' . $params['job_id'] . '/',
            $route === 'zero_mcp/change/index' => 'https://store.example/admin/zero_mcp/change/index/',
            default => 'https://store.example/admin/' . $route . '/with-secret/',
        });
        return $url;
    }

    private function connection(?Tools $tools = null): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('userId')->willReturn(1);
        $connection->method('conversationId')->willReturn(10);
        $connection->method('tools')->willReturn($tools ?? $this->createStub(Tools::class));
        $connection->method('commands')->willReturn($this->createStub(Commands::class));
        $connection->method('userName')->willReturn('admin');
        $connection->method('clientName')->willReturn('Claude');
        return $connection;
    }

    private function set(Agent $agent, ?Config $config = null, ?Conversations $conversations = null, string $tool = 'update_product', string $result = ''): ToolSet
    {
        if ($conversations === null) {
            $conversations = $this->createStub(Conversations::class);
            $conversations->method('action')->willReturn(['action_id' => 5, 'conversation_id' => 10, 'tool' => $tool, 'status' => 'done', 'input' => ['sku' => 'MH01'], 'result' => $result]);
        }
        return new ToolSet($agent, $conversations, $this->createStub(Uploads::class), $this->createStub(Drafts::class), $config ?? $this->createStub(Config::class), $this->url());
    }

    private function agentUndoing(array $result): Agent
    {
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->once())->method('undo')->with(5, 1, $this->isInstanceOf(Tools::class))->willReturn($result);
        return $agent;
    }

    private static function json(array $answer): array
    {
        return (array) json_decode($answer['content'][0]['text'], true);
    }

    public function testUndoOfAChangeThatCannotBeUndoneIsAnError(): void
    {
        $answer = $this->set($this->agentUndoing([
            'status' => 'not_undoable',
            'result' => 'Change #5 (set_settings) can\'t be undone: nothing was kept to put back. Change it again instead.',
        ]), null, null, 'set_settings')->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertTrue($answer['isError']);
        $this->assertSame('Change #5: not_undoable (Change #5 (set_settings) can\'t be undone: nothing was kept to put back. Change it again instead.)', $answer['content'][0]['text']);
    }

    public function testUndoOfABulkJobIsQueuedWithTheJobPage(): void
    {
        $answer = $this->set($this->agentUndoing(['status' => 'undoing', 'result' => ['job_id' => 3, 'products' => 12]]), null, null, 'bulk_write_products')
            ->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertArrayNotHasKey('isError', $answer);
        $this->assertSame([
            'change_id' => 5,
            'status' => 'undoing',
            'result' => ['job_id' => 3, 'products' => 12, 'job_url' => self::JOB_URL],
            'note' => 'The products get their old texts back in the background; the job page shows when it is done.',
        ], self::json($answer));
    }

    public function testAnUndoneChangeIsASuccess(): void
    {
        $answer = $this->set($this->agentUndoing(['status' => 'undone', 'result' => ['sku' => 'MH01', 'restored' => 'price']]))
            ->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertArrayNotHasKey('isError', $answer);
        $this->assertSame(['change_id' => 5, 'status' => 'undone', 'result' => ['sku' => 'MH01', 'restored' => 'price']], self::json($answer));
    }

    public function testUndoOfAnUndoneChangeIsAnError(): void
    {
        $answer = $this->set($this->agentUndoing(['status' => 'already_undone', 'result' => 'Change #5 is undone already.']))
            ->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertTrue($answer['isError']);
        $this->assertSame('Change #5: already_undone (Change #5 is undone already.)', $answer['content'][0]['text']);
    }

    public function testEveryOtherUndoStatusIsAnError(): void
    {
        foreach (['busy', 'failed', 'missing', 'proposed', 'cancelled'] as $status) {
            $answer = $this->set($this->agentUndoing(['status' => $status, 'result' => '']))->call($this->connection(), 'undo_change', ['change_id' => 5]);
            $this->assertTrue($answer['isError'], $status);
            $this->assertSame('Change #5: ' . $status, $answer['content'][0]['text']);
        }
    }

    public function testABulkJobStillWritingSaysWhereToStopIt(): void
    {
        $answer = $this->set(
            $this->agentUndoing(['status' => 'failed', 'result' => 'It is still writing: cancel it first, or wait.', 'still_writing' => true]),
            null,
            null,
            'bulk_write_products',
            '{"job_id":3,"products":40}'
        )->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertTrue($answer['isError']);
        $this->assertSame('Change #5: failed (It is still writing: cancel it first, or wait. Stop it at ' . self::JOB_URL . ')', $answer['content'][0]['text']);
    }

    public function testAnotherFailureGetsNoStopHint(): void
    {
        $answer = $this->set(
            $this->agentUndoing(['status' => 'failed', 'result' => 'It is still writing: cancel it first, or wait.']),
            null,
            null,
            'bulk_write_products',
            '{"job_id":3}'
        )->call($this->connection(), 'undo_change', ['change_id' => 5]);

        $this->assertTrue($answer['isError']);
        $this->assertStringNotContainsString('Stop it at', $answer['content'][0]['text'], 'the flag, not the wording, decides');
    }

    public function testStillWritingWithNoJobInTheConfirmedChangeGetsNoStopHint(): void
    {
        foreach (['', '{"products":40}', '{"job_id":0}'] as $stored) {
            $answer = $this->set(
                $this->agentUndoing(['status' => 'failed', 'result' => 'It is still writing: cancel it first, or wait.', 'still_writing' => true]),
                null,
                null,
                'bulk_write_products',
                $stored
            )->call($this->connection(), 'undo_change', ['change_id' => 5]);

            $this->assertTrue($answer['isError'], $stored);
            $this->assertSame('Change #5: failed (It is still writing: cancel it first, or wait.)', $answer['content'][0]['text'], $stored);
        }
    }

    public function testConfirmIsASuccessOnlyWhenDone(): void
    {
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('action')->willReturn(['action_id' => 5, 'conversation_id' => 10, 'tool' => 'update_product', 'status' => 'proposed', 'input' => ['sku' => 'MH01', 'name' => 'New']]);
        $agent = $this->createStub(Agent::class);
        $agent->method('confirm')->willReturnOnConsecutiveCalls(
            ['status' => 'undone', 'result' => ''],
            ['status' => 'failed', 'result' => 'No product with SKU MH01.'],
            ['status' => 'done', 'result' => ['id' => 1, 'sku' => 'MH01']]
        );
        $set = $this->set($agent, null, $conversations);

        $undone = $set->call($this->connection(), 'confirm_change', ['change_id' => 5]);
        $this->assertTrue($undone['isError']);
        $this->assertSame('Change #5: undone', $undone['content'][0]['text']);

        $failed = $set->call($this->connection(), 'confirm_change', ['change_id' => 5]);
        $this->assertTrue($failed['isError']);
        $this->assertSame('Change #5: failed (No product with SKU MH01.)', $failed['content'][0]['text']);

        $done = $set->call($this->connection(), 'confirm_change', ['change_id' => 5]);
        $this->assertArrayNotHasKey('isError', $done);
        $this->assertSame(['change_id' => 5, 'status' => 'done', 'result' => ['id' => 1, 'sku' => 'MH01']], self::json($done));
    }

    public function testABulkConfirmLinksToTheJobPage(): void
    {
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('action')->willReturn(['action_id' => 5, 'conversation_id' => 10, 'tool' => 'bulk_write_products', 'status' => 'proposed', 'input' => []]);
        $agent = $this->createStub(Agent::class);
        $agent->method('confirm')->willReturn(['status' => 'done', 'result' => ['job_id' => 3, 'products' => 40]]);

        $done = $this->set($agent, null, $conversations)->call($this->connection(), 'confirm_change', ['change_id' => 5]);

        $this->assertSame(['job_id' => 3, 'products' => 40, 'job_url' => self::JOB_URL], self::json($done)['result']);
    }

    public function testListChangesAsksForTheNewestWithinTheLimit(): void
    {
        $asked = [];
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('recentActions')->willReturnCallback(static function (int $id, int $limit) use (&$asked) {
            $asked[] = [$id, $limit];
            return [['action_id' => '9', 'tool' => 'ship_order', 'preview' => ['title' => 'Ship order #1'], 'status' => 'proposed', 'created_at' => '2026-10-29 10:00:00']];
        });
        $set = $this->set($this->createStub(Agent::class), null, $conversations);

        $rows = self::json($set->call($this->connection(), 'list_changes', ['limit' => 500]));
        $set->call($this->connection(), 'list_changes', []);
        $set->call($this->connection(), 'list_changes', ['limit' => 0]);
        $set->call($this->connection(), 'list_changes', ['limit' => -5]);

        $this->assertSame([[10, 100], [10, 20], [10, 1], [10, 1]], $asked);
        $this->assertSame([['change_id' => 9, 'tool' => 'ship_order', 'title' => 'Ship order #1', 'status' => 'proposed', 'created_at' => '2026-10-29 10:00:00']], $rows);
    }

    private function priceConfig(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('confirmsInAdmin')->willReturnCallback(static fn (string $tool, array $input = []) => $tool === 'update_product' && array_key_exists('price', $input));
        $config->method('adminConfirmFields')->willReturnCallback(static fn (string $tool) => $tool === 'update_product' ? ['price', 'qty'] : []);
        return $config;
    }

    private function updateProductTools(): Tools
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('name')->willReturn('update_product');
        $tool->method('changes')->willReturn(true);
        $tool->method('description')->willReturn('Change a product.');
        $tool->method('schema')->willReturn(['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]]);
        $tool->method('preview')->willReturn(['title' => 'Change product MH01', 'lines' => ['price' => '45 → 40']]);
        $ship = $this->createStub(ToolInterface::class);
        $ship->method('name')->willReturn('ship_order');
        $ship->method('changes')->willReturn(true);
        $ship->method('schema')->willReturn(['type' => 'object', 'properties' => []]);
        $tools = $this->createStub(Tools::class);
        $tools->method('get')->willReturnCallback(static fn (string $name) => $name === 'update_product' ? $tool : null);
        $tools->method('allowed')->willReturn(['update_product' => $tool, 'ship_order' => $ship]);
        return $tools;
    }

    public function testAPriceChangeIsConfirmedInTheAdmin(): void
    {
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('propose')->willReturn(5);
        $set = $this->set($this->createStub(Agent::class), $this->priceConfig(), $conversations);
        $connection = $this->connection($this->updateProductTools());

        $price = $set->call($connection, 'update_product', ['sku' => 'MH01', 'price' => 40]);
        $this->assertStringContainsString('This kind of change is confirmed by the admin in the store admin', $price['content'][0]['text']);
        $this->assertStringContainsString('https://store.example/admin/zero_mcp/change/index/', $price['content'][0]['text']);

        $name = $set->call($connection, 'update_product', ['sku' => 'MH01', 'name' => 'New']);
        $this->assertStringContainsString('when they agree, call confirm_change with change_id 5', $name['content'][0]['text']);
    }

    public function testClaudeCannotConfirmAProposedPriceChange(): void
    {
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('action')->willReturn(['action_id' => 5, 'conversation_id' => 10, 'tool' => 'update_product', 'status' => 'proposed', 'input' => ['sku' => 'MH01', 'price' => 40]]);
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->never())->method('confirm');

        $answer = $this->set($agent, $this->priceConfig(), $conversations)->call($this->connection(), 'confirm_change', ['change_id' => 5]);

        $this->assertArrayNotHasKey('isError', $answer);
        $this->assertStringStartsWith('Change #5 is not done. This kind of change is confirmed by the admin in the store admin', $answer['content'][0]['text']);
    }

    public function testARecordAclRefusalProposesNothing(): void
    {
        $tools = $this->updateProductTools();
        $tools->method('authorize')->willThrowException(new LocalizedException(__('Your role doesn\'t allow %1 on these records: it needs %2.', 'update_product', 'Magento_Review::reviews_all')));
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->never())->method('propose');

        $answer = $this->set($this->createStub(Agent::class), $this->priceConfig(), $conversations)->call($this->connection($tools), 'update_product', ['sku' => 'MH01']);

        $this->assertTrue($answer['isError']);
        $this->assertSame('Your role doesn\'t allow update_product on these records: it needs Magento_Review::reviews_all.', $answer['content'][0]['text']);
    }

    public function testTheListAndTheInfoNameTheFieldEntries(): void
    {
        $config = $this->priceConfig();
        $set = $this->set($this->createStub(Agent::class), $config);
        $connection = $this->connection($this->updateProductTools());

        $list = array_column($set->list($connection), null, 'name');
        $this->assertStringContainsString('A change of price, qty the admin confirms in the store admin', $list['update_product']['description']);
        $this->assertStringContainsString('Other changes cannot be undone', $list['undo_change']['description']);
        $this->assertSame(100, $list['list_changes']['inputSchema']['properties']['limit']['maximum']);
        $this->assertSame(20, $list['list_changes']['inputSchema']['properties']['limit']['default']);

        $info = self::json($set->call($connection, 'connection_info', []));
        $this->assertSame(['update_product:price', 'update_product:qty'], $info['confirmed_in_admin']);
        $this->assertStringContainsString('other changes cannot be undone', $info['how_changes_work']);
    }
}
