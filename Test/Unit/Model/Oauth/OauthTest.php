<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Oauth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\Clients;
use Zero\Mcp\Model\Oauth\Codes;
use Zero\Mcp\Model\Oauth\Pending;
use Zero\Mcp\Model\Oauth\Service;

/**
 * The pieces of the OAuth flow that must be exactly right: PKCE, which
 * redirect URIs a client may use, the waiting request's secrets, the redirect
 * with the code.
 */
class OauthTest extends TestCase
{
    public function testPkceS256(): void
    {
        // RFC 7636 appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $this->assertTrue(Codes::verifies($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        $this->assertFalse(Codes::verifies($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cN'));
        $this->assertFalse(Codes::verifies('short', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'), 'verifiers are 43 to 128 characters');
    }

    public function testRedirectUrisClaudeLoopbackAndTheStoresOwn(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('extraRedirectUris')->willReturn(['https://agent.example.com/oauth/cb']);
        $clients = new Clients($this->createStub(ResourceConnection::class), $config);
        $this->assertTrue($clients->isRedirectAllowed(Config::CLAUDE_CALLBACK));
        $this->assertTrue($clients->isRedirectAllowed('http://localhost:3118/callback'));
        $this->assertTrue($clients->isRedirectAllowed('http://127.0.0.1/callback'));
        $this->assertTrue($clients->isRedirectAllowed('https://agent.example.com/oauth/cb'));
        $this->assertFalse($clients->isRedirectAllowed('https://evil.example/cb'));
        $this->assertFalse($clients->isRedirectAllowed('http://localhost.evil.example/callback'));
        $this->assertFalse($clients->isRedirectAllowed('https://claude.ai/api/mcp/auth_callback?x=1'));

        $client = ['redirect_uris' => [Config::CLAUDE_CALLBACK, 'http://localhost/callback', 'http://127.0.0.1/callback']];
        $this->assertTrue($clients->matches($client, 'http://localhost:51234/callback'), 'loopback matches with any port');
        $this->assertTrue($clients->matches($client, 'http://127.0.0.1:8/callback'));
        $this->assertTrue($clients->matches($client, Config::CLAUDE_CALLBACK));
        $this->assertFalse($clients->matches($client, 'http://localhost:51234/other'));
        $this->assertFalse($clients->matches($client, 'https://claude.ai/api/mcp/other'));
    }

    public function testPendingRequestKeepsOnlyHashesAndFindsTheCodeAsTyped(): void
    {
        $inserted = [];
        $db = $this->createStub(AdapterInterface::class);
        $db->method('insert')->willReturnCallback(function (string $table, array $row) use (&$inserted) {
            $inserted = $row;
            return 1;
        });
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function (string $cond, $value) use ($select, &$wheres) {
            $wheres[$cond] = $value;
            return $select;
        });
        $db->method('select')->willReturn($select);
        $db->method('fetchRow')->willReturn(false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $pending = new Pending($resource);

        $made = $pending->create([
            'client' => ['client_id' => 'zmc_1'], 'redirect_uri' => Config::CLAUDE_CALLBACK, 'state' => 's',
            'code_challenge' => 'c', 'scope' => Config::SCOPE, 'resource' => null,
        ]);
        $this->assertMatchesRegularExpression('/^zmcp_[0-9a-f]{64}$/', $made['key']);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $made['code'], 'no 0/O or 1/I to misread');
        $this->assertSame(hash('sha256', $made['key']), $inserted['key_hash']);
        $this->assertSame(hash('sha256', str_replace('-', '', $made['code'])), $inserted['code_hash']);
        $this->assertNotContains($made['key'], $inserted);
        $this->assertNotContains($made['code'], $inserted);

        $this->assertNull($pending->byCode('abc'), 'too short: no query');
        $this->assertSame([], $wheres);
        $pending->byCode(' abcd-2345 ');
        $this->assertSame(hash('sha256', 'ABCD2345'), $wheres['code_hash = ?']);
        $this->assertArrayHasKey('expires_at >= ?', $wheres, 'an expired request is not found');
    }

    public function testRedirectKeepsTheClientsQueryAndEncodes(): void
    {
        $this->assertSame(
            'http://localhost:3118/callback?code=abc&state=s%20t',
            Service::redirect('http://localhost:3118/callback', ['code' => 'abc', 'state' => 's t'])
        );
        $this->assertSame(
            'https://claude.ai/api/mcp/auth_callback?error=access_denied',
            Service::redirect('https://claude.ai/api/mcp/auth_callback', ['error' => 'access_denied', 'state' => ''])
        );
    }
}
