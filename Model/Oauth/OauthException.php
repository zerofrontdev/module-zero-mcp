<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Oauth;

/**
 * An OAuth error as RFC 6749 names it (invalid_request, invalid_grant,
 * invalid_client…), with the HTTP status it goes out with.
 */
class OauthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
