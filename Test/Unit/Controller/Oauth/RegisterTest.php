<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Test\Unit\Controller\Oauth;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use PHPUnit\Framework\TestCase;
use Zero\Mcp\Controller\Oauth\Register;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Oauth\ClientAddress;
use Zero\Mcp\Model\Oauth\Clients;
use Zero\Mcp\Model\Oauth\OauthException;
use Zero\Mcp\Model\Oauth\RegistrationLimit;

/**
 * Registration answers 429 with Retry-After over the hourly limit; every POST counts, valid or not.
 */
class RegisterTest extends TestCase
{
    /** @var array{status: int|null, headers: array<string, string>, body: string} */
    private array $response = ['status' => null, 'headers' => [], 'body' => ''];

    private function register(bool $post, RegistrationLimit $limit, Clients $clients, bool $enabled = true): Register
    {
        $this->response = ['status' => null, 'headers' => [], 'body' => ''];
        $request = $this->createStub(Http::class);
        $request->method('isPost')->willReturn($post);
        $request->method('getContent')->willReturn('{"client_name":"Claude","redirect_uris":["https://claude.ai/api/mcp/auth_callback"]}');
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw) {
            $this->response['status'] = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function (string $name, $value) use ($raw) {
            $this->response['headers'][$name] = (string) $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function (string $body) use ($raw) {
            $this->response['body'] = $body;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $address = $this->createStub(ClientAddress::class);
        $address->method('get')->willReturn('203.0.113.7');

        return new Register($request, $rawFactory, $config, $clients, $limit, $address);
    }

    public function testOverTheLimitIs429WithRetryAfter(): void
    {
        $limit = $this->createMock(RegistrationLimit::class);
        $limit->expects($this->once())->method('allow')->with('203.0.113.7')->willReturn(false);
        $limit->method('retryAfter')->willReturn(1234);
        $clients = $this->createMock(Clients::class);
        $clients->expects($this->never())->method('register');

        $this->register(true, $limit, $clients)->execute();

        $this->assertSame(429, $this->response['status']);
        $this->assertSame('1234', $this->response['headers']['Retry-After']);
        $this->assertSame('no-store', $this->response['headers']['Cache-Control']);
        $this->assertSame([
            'error' => 'temporarily_unavailable',
            'error_description' => 'Too many client registrations from this address; try again later.',
        ], json_decode($this->response['body'], true));
    }

    public function testWithinTheLimitTheClientIsRegistered(): void
    {
        $limit = $this->createStub(RegistrationLimit::class);
        $limit->method('allow')->willReturn(true);
        $clients = $this->createMock(Clients::class);
        $clients->expects($this->once())->method('register')
            ->with(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->willReturn(['client_id' => 'zmc_1']);

        $this->register(true, $limit, $clients)->execute();

        $this->assertSame(201, $this->response['status']);
        $this->assertSame(['client_id' => 'zmc_1'], json_decode($this->response['body'], true));
        $this->assertArrayNotHasKey('Retry-After', $this->response['headers']);
    }

    public function testAnInvalidRegistrationIsCountedToo(): void
    {
        $limit = $this->createMock(RegistrationLimit::class);
        $limit->expects($this->once())->method('allow')->willReturn(true);
        $clients = $this->createStub(Clients::class);
        $clients->method('register')->willThrowException(new OauthException('invalid_redirect_uri', 'Redirect URI not allowed: https://evil.example/cb'));

        $this->register(true, $limit, $clients)->execute();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame('invalid_redirect_uri', json_decode($this->response['body'], true)['error']);
    }

    public function testAGetIsRefusedBeforeItCounts(): void
    {
        $limit = $this->createMock(RegistrationLimit::class);
        $limit->expects($this->never())->method('allow');

        $this->register(false, $limit, $this->createStub(Clients::class))->execute();

        $this->assertSame(405, $this->response['status']);
    }

    public function testOffItCountsNothing(): void
    {
        $limit = $this->createMock(RegistrationLimit::class);
        $limit->expects($this->never())->method('allow');

        $this->register(true, $limit, $this->createStub(Clients::class), false)->execute();

        $this->assertSame(404, $this->response['status']);
    }
}
