<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Model\Oauth;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\RegistrationLimit;

/**
 * Client registrations per address and fixed clock hour, and a store-wide ceiling of ten times that.
 */
class RegistrationLimitTest extends TestCase
{
    private const HOUR = 1793232000; // 2026-10-29 00:00:00 UTC, a window start

    /** @var array<string, string> the in-memory cache */
    private array $cache = [];

    /** @var array<int, array{0: string, 1: array, 2: int|null}> each save's key, tags and lifetime */
    private array $saves = [];

    private function limit(int $perHour): RegistrationLimit
    {
        $this->cache = [];
        $this->saves = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key, array $tags = [], $lifetime = null) {
            $this->cache[$key] = $data;
            $this->saves[] = [$key, $tags, $lifetime];
            return true;
        });
        $config = $this->createStub(Config::class);
        $config->method('registrationsPerHour')->willReturn($perHour);
        return new RegistrationLimit($cache, $config);
    }

    public function testTheLimitIsPerAddressAndHour(): void
    {
        $limit = $this->limit(2);

        $this->assertTrue($limit->allow('203.0.113.7', self::HOUR));
        $this->assertTrue($limit->allow('203.0.113.7', self::HOUR + 10));
        $this->assertFalse($limit->allow('203.0.113.7', self::HOUR + 3599), 'the third in the hour');
        $this->assertTrue($limit->allow('198.51.100.1', self::HOUR + 20), 'another address has its own count');
        $this->assertTrue($limit->allow('203.0.113.7', self::HOUR + 3600), 'the next window');
    }

    public function testTheCounterIsKeyedByAHashOfTheAddressAndKeptJustOverAnHour(): void
    {
        $this->limit(30)->allow('203.0.113.7', self::HOUR + 5);

        $window = intdiv(self::HOUR, 3600);
        $this->assertSame([
            ['zero_mcp_registration_' . hash('sha256', '203.0.113.7') . '_' . $window, [], 3660],
            ['zero_mcp_registration_store_' . $window, [], 3660],
        ], $this->saves);
        $this->assertStringNotContainsString('203.0.113.7', implode(' ', array_keys($this->cache)), 'no address in the key');
    }

    public function testTheStoreWideCeilingIsTenTimesTheLimit(): void
    {
        $limit = $this->limit(2);
        for ($i = 1; $i <= 20; $i++) {
            $this->assertTrue($limit->allow('203.0.113.' . $i, self::HOUR), 'registration ' . $i);
        }
        $this->assertFalse($limit->allow('203.0.113.99', self::HOUR), 'the 21st of the store in the hour');
        $this->assertTrue($limit->allow('203.0.113.99', self::HOUR + 3600));
    }

    public function testWithoutAnAddressOnlyTheStoreWideCounterCounts(): void
    {
        $limit = $this->limit(2);
        for ($i = 1; $i <= 20; $i++) {
            $this->assertTrue($limit->allow('', self::HOUR), 'registration ' . $i);
        }
        $this->assertFalse($limit->allow('', self::HOUR));
        $this->assertSame(['zero_mcp_registration_store_' . intdiv(self::HOUR, 3600)], array_keys($this->cache));
    }

    public function testARegistrationRefusedByItsAddressDoesNotUseTheStoresCount(): void
    {
        $limit = $this->limit(1);
        $this->assertTrue($limit->allow('203.0.113.7', self::HOUR));
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($limit->allow('203.0.113.7', self::HOUR));
        }
        $this->assertSame('1', $this->cache['zero_mcp_registration_store_' . intdiv(self::HOUR, 3600)]);
        $this->assertTrue($limit->allow('198.51.100.1', self::HOUR), 'one noisy address does not lock out the store');
    }

    public function testRetryAfterIsTheRestOfTheHour(): void
    {
        $limit = $this->limit(30);

        $this->assertSame(3600, $limit->retryAfter(self::HOUR));
        $this->assertSame(1, $limit->retryAfter(self::HOUR + 3599));
        $this->assertSame(2400, $limit->retryAfter(self::HOUR + 1200));
    }
}
