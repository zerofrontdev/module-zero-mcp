<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

use Magento\Framework\App\Request\Http;

/**
 * The address a request came from, as far as no client can set it, for the registration limit. Not Magento's
 * RemoteAddress: app/etc/di.xml makes that read X-Forwarded-For's first entry, which the client writes.
 * - The peer (REMOTE_ADDR) when it is not this box: PHP-FPM reached directly by the visitor.
 * - When the peer is loopback, the store's own proxy chain (for example public nginx :443 -> Varnish -> nginx 127.0.0.1:8080 ->
 *   PHP-FPM): X-Real-IP, which the public nginx block overwrites with its own peer
 *   (proxy_set_header X-Real-IP $remote_addr), and which nothing outside can reach past it (Varnish and :8080
 *   listen on loopback only). Behind CloudFront that is the CloudFront edge's address, not the viewer's.
 * - Nothing usable (loopback without X-Real-IP, a value that isn't an IP address): '' (the store-wide ceiling only).
 */
class ClientAddress
{
    public function __construct(
        private readonly Http $request
    ) {
    }

    public function get(): string
    {
        $peer = trim((string) $this->request->getServer('REMOTE_ADDR'));
        if (!self::isLoopback($peer)) {
            return self::isIp($peer) ? $peer : '';
        }
        $real = trim((string) $this->request->getServer('HTTP_X_REAL_IP'));
        return self::isIp($real) && !self::isLoopback($real) ? $real : '';
    }

    private static function isIp(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false;
    }

    private static function isLoopback(string $address): bool
    {
        return $address === '::1' || str_starts_with($address, '127.') || str_starts_with($address, '::ffff:127.');
    }
}
