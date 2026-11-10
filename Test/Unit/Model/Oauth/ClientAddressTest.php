<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Oauth;

use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Model\Oauth\ClientAddress;

/**
 * The registration limit's address is one no client can set: the peer, or behind our own loopback proxy chain the
 * X-Real-IP our public nginx writes; X-Forwarded-For is never read.
 */
class ClientAddressTest extends TestCase
{
    private function address(array $server): string
    {
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturnCallback(static fn (string $key) => $server[$key] ?? null);
        return (new ClientAddress($request))->get();
    }

    public function testThePeerWhenItIsNotThisBox(): void
    {
        $this->assertSame('203.0.113.7', $this->address(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_REAL_IP' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.9']));
        $this->assertSame('2001:db8::1', $this->address(['REMOTE_ADDR' => '2001:db8::1']));
    }

    public function testXRealIpOnlyBehindLoopback(): void
    {
        foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1', '127.0.1.1'] as $peer) {
            $this->assertSame('198.51.100.1', $this->address(['REMOTE_ADDR' => $peer, 'HTTP_X_REAL_IP' => ' 198.51.100.1 ']), $peer);
        }
    }

    public function testXForwardedForIsNeverRead(): void
    {
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.9']));
    }

    public function testAnUnusableAddressIsEmpty(): void
    {
        $this->assertSame('', $this->address([]), 'no peer');
        $this->assertSame('', $this->address(['REMOTE_ADDR' => 'not-an-ip']));
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1']), 'loopback without X-Real-IP');
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '127.0.0.1']), 'X-Real-IP loopback');
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '::1']));
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '198.51.100.1, 203.0.113.7']), 'a list is not an address');
        $this->assertSame('', $this->address(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => 'evil']));
    }
}
