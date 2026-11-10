<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Oauth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\Clients;

/**
 * Anyone may register a client, so what is kept of its metadata is a few keys, each at most 255 characters.
 */
class ClientsTest extends TestCase
{
    public function testKeptMetadataValuesAreCappedAt255(): void
    {
        $inserted = [];
        $db = $this->createStub(AdapterInterface::class);
        $db->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$inserted) {
            $inserted = [$table, $row];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);

        $result = (new Clients($resource, $this->createStub(Config::class)))->register([
            'client_name' => str_repeat('n', 300),
            'redirect_uris' => [Config::CLAUDE_CALLBACK],
            'client_uri' => 'https://claude.ai/' . str_repeat('a', 1000),
            'logo_uri' => ['nested' => str_repeat('b', 400)],
            'software_version' => 3,
            'contacts' => [str_repeat('c', 400)],
        ]);

        $this->assertSame('zero_mcp_client', $inserted[0]);
        $metadata = json_decode($inserted[1]['metadata'], true);
        $this->assertSame(['client_name', 'client_uri', 'logo_uri', 'software_version'], array_keys($metadata), 'other keys are dropped');
        foreach ($metadata as $key => $value) {
            $this->assertIsString($value, $key);
            $this->assertLessThanOrEqual(255, mb_strlen($value), $key);
        }
        $this->assertSame(255, mb_strlen($metadata['client_uri']));
        $this->assertSame('3', $metadata['software_version']);
        $this->assertSame(255, mb_strlen($inserted[1]['client_name']));
        $this->assertSame(255, mb_strlen($result['client_name']));
    }
}
