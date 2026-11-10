<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Zero\AiAssistant\Model\Commands;
use Zero\AiAssistant\Model\CommandsFactory;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\Model\ToolsFactory;
use Zero\Mcp\Model\Acl\UserAuthorizationFactory;

/**
 * One connection's call: the token's admin user, their role's tools and
 * commands, and the connection's conversation, where its changes are
 * journaled as the admin chat's are (System > AI Assistant shows them).
 */
class Connection
{
    private ?Tools $tools = null;
    private ?Commands $commands = null;
    private ?int $conversationId = null;

    /**
     * @param array<string, mixed> $token the row of zero_mcp_token
     */
    public function __construct(
        private readonly array $token,
        private readonly UserAuthorizationFactory $authorizationFactory,
        private readonly ToolsFactory $toolsFactory,
        private readonly CommandsFactory $commandsFactory,
        private readonly Conversations $conversations,
        private readonly Oauth\Tokens $tokens,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
    }

    public function tokenId(): int
    {
        return (int) $this->token['token_id'];
    }

    public function userId(): int
    {
        return (int) $this->token['user_id'];
    }

    public function clientName(): string
    {
        return (string) $this->token['client_name'];
    }

    public function userName(): string
    {
        $user = $this->userCollectionFactory->create()->addFieldToFilter('user_id', $this->userId())->getFirstItem();
        return trim($user->getFirstname() . ' ' . $user->getLastname()) ?: (string) $user->getUsername();
    }

    public function tools(): Tools
    {
        return $this->tools ??= $this->toolsFactory->create(['authorization' => $this->authorization()]);
    }

    public function commands(): Commands
    {
        return $this->commands ??= $this->commandsFactory->create(['authorization' => $this->authorization()]);
    }

    public function authorization(): Acl\UserAuthorization
    {
        return $this->authorizationFactory->create(['userId' => $this->userId()]);
    }

    /**
     * The connection's conversation, made on first use.
     */
    public function conversationId(): int
    {
        if ($this->conversationId !== null) {
            return $this->conversationId;
        }
        $id = $this->token['conversation_id'] !== null ? (int) $this->token['conversation_id'] : 0;
        if ($id === 0 || !$this->conversations->get($id, $this->userId())) {
            $id = $this->conversations->create($this->userId(), (string) __('Claude connector · %1 · %2', $this->clientName(), date('Y-m-d')));
            $this->tokens->setConversation($this->tokenId(), $id);
        }
        return $this->conversationId = $id;
    }
}
