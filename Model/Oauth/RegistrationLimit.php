<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Magento\Framework\App\CacheInterface;
use Zero\Mcp\Model\Config;

/**
 * Client registrations (anyone may register) limited per fixed clock hour:
 * - per address (ClientAddress): Registrations per Hour per Address (Config::registrationsPerHour);
 * - for the whole store: STORE_FACTOR times that, the backstop against many addresses (and the only limit for a
 *   request without a usable address). A request refused by its address's limit doesn't use the store's.
 * Counted in the cache, keyed by a hash of the address: a soft limit (load and save are not atomic), reset by a
 * cache flush.
 */
class RegistrationLimit
{
    public const STORE_FACTOR = 10;
    private const WINDOW = 3600;
    private const LIFETIME = 3660;
    private const KEY = 'zero_mcp_registration_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Config $config
    ) {
    }

    /**
     * Counts this registration; whether it is within the limits.
     *
     * @param string $ip the client's address, '' when there is none usable
     * @param int|null $now a Unix time (tests), else the current one
     */
    public function allow(string $ip, ?int $now = null): bool
    {
        $window = intdiv($now ?? time(), self::WINDOW);
        $limit = $this->config->registrationsPerHour();
        if ($ip !== '' && $this->count(self::KEY . hash('sha256', $ip) . '_' . $window) > $limit) {
            return false;
        }
        return $this->count(self::KEY . 'store_' . $window) <= $limit * self::STORE_FACTOR;
    }

    /**
     * Seconds until the current window ends (Retry-After).
     */
    public function retryAfter(?int $now = null): int
    {
        $now ??= time();
        return self::WINDOW - $now % self::WINDOW;
    }

    /**
     * One more in this counter; the new count.
     */
    private function count(string $key): int
    {
        $count = (int) $this->cache->load($key) + 1;
        $this->cache->save((string) $count, $key, [], self::LIFETIME);
        return $count;
    }
}
