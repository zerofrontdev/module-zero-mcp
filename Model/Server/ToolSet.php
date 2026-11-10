<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Server;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Uploads;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Connection;
use Zero\Mcp\Model\Drafts;

/**
 * The connection's MCP tools: the assistant's own tools for this admin's
 * role (reading ones run at once; a changing one proposes and returns a
 * change id), the change tools (confirm, cancel, undo, list), and the
 * photo tools (a link for the phone, the photos read back). Every change
 * runs through the same journal as the admin chat (zero_ai_action), so
 * the Connections page and the chat show what a connection did.
 */
class ToolSet
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Conversations $conversations,
        private readonly Uploads $uploads,
        private readonly Drafts $drafts,
        private readonly Config $config,
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> as tools/list returns them
     */
    public function list(Connection $connection): array
    {
        $list = [];
        foreach ($connection->tools()->allowed() as $tool) {
            $fields = $tool->changes() ? $this->config->adminConfirmFields($tool->name()) : [];
            $list[] = [
                'name' => $tool->name(),
                'title' => ucfirst(str_replace('_', ' ', $tool->name())),
                'description' => match (true) {
                    !$tool->changes() => $tool->description() . ' Read only.',
                    $this->config->confirmsInAdmin($tool->name()) => $tool->description() . ' Proposes the change and returns a change_id with what would be done. The admin confirms this kind of change in the store admin, not in the conversation: give them the link from the answer.',
                    $fields !== [] => $tool->description() . ' Proposes the change and returns a change_id with what would be done. A change of ' . implode(', ', $fields)
                        . ' the admin confirms in the store admin, not in the conversation: give them the link from the answer. Other changes: show it to the admin, and call confirm_change only once they agree in the conversation.',
                    default => $tool->description() . ' Proposes the change and returns a change_id with what would be done: show it to the admin, and call confirm_change only once they agree in the conversation.',
                },
                'inputSchema' => $tool->schema(),
                'annotations' => ['readOnlyHint' => !$tool->changes(), 'destructiveHint' => false, 'openWorldHint' => false],
            ];
        }
        $object = static fn (array $props, array $required = []) => ['type' => 'object', 'properties' => $props, 'required' => $required];
        $list[] = [
            'name' => 'confirm_change',
            'title' => 'Confirm change',
            'description' => 'Runs a proposed change (its change_id) after the admin agreed to it in the conversation. Returns what was done. Changes the store confirms in the admin are not run: the answer gives the admin\'s link.',
            'inputSchema' => $object(['change_id' => ['type' => 'integer']], ['change_id']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        ];
        $list[] = [
            'name' => 'cancel_change',
            'title' => 'Cancel change',
            'description' => 'Drops a proposed change the admin does not want.',
            'inputSchema' => $object(['change_id' => ['type' => 'integer']], ['change_id']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
        ];
        $list[] = [
            'name' => 'undo_change',
            'title' => 'Undo change',
            'description' => 'Puts back what a confirmed product change overwrote (update_product), or queues the old texts of a whole bulk job (bulk_write_products; it runs in the background, the answer links to the job\'s page). Other changes cannot be undone: the answer says so; change it again instead.',
            'inputSchema' => $object(['change_id' => ['type' => 'integer']], ['change_id']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        ];
        $list[] = [
            'name' => 'list_changes',
            'title' => 'List changes',
            'description' => 'The changes proposed through this connection, newest first, with their status (proposed, done, cancelled, failed, undone). Read only.',
            'inputSchema' => $object(['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]]),
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        ];
        $takesPhotos = ['create_product', 'update_product', 'save_category', 'save_cms_page', 'save_cms_block', 'save_cms_components', 'save_post', 'save_slide'];
        if (array_filter($takesPhotos, static fn (string $tool) => $connection->tools()->get($tool) !== null)) {
            $list[] = [
                'name' => 'request_photos',
                'title' => 'Request photos',
                'description' => 'Gives the admin a link to open on their phone to take or pick photos (for a product, a category image, a page or a block); valid 30 minutes, up to 10 photos. Tell the admin to open it and say when the photos are in, then call get_photos with the draft_id.',
                'inputSchema' => $object(['name' => ['type' => 'string', 'description' => 'What the photos are of, shown on the page']]),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ];
            $list[] = [
                'name' => 'get_photos',
                'title' => 'Get photos',
                'description' => 'The photos the admin uploaded for a draft_id (from request_photos), as images, with their upload ids: create_product.image_upload_ids, update_product.add_image_upload_ids, save_category.image_upload_id, or {{upload:N}} in the HTML of save_cms_page / save_cms_block. Read only.',
                'inputSchema' => $object(['draft_id' => ['type' => 'integer']], ['draft_id']),
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ];
        }
        $list[] = [
            'name' => 'connection_info',
            'title' => 'Connection info',
            'description' => 'Who this connection is (the admin user), the store, and what it may do. Read only.',
            'inputSchema' => $object([]),
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        ];
        foreach ($list as $i => $tool) {
            $list[$i]['inputSchema'] = self::jsonSchema($tool['inputSchema']);
        }
        return $list;
    }

    /**
     * A tool without parameters has "properties": [] as a PHP array, which json_encode writes as a JSON
     * list; JSON Schema (and claude.ai, which then drops the whole tools/list) wants an object there.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function jsonSchema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $key === 'properties' && $value === [] ? new \stdClass() : self::jsonSchema($value);
            }
        }
        return $schema;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{content: array, isError?: bool}
     */
    public function call(Connection $connection, string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'confirm_change' => $this->change($connection, 'confirm', (int) ($arguments['change_id'] ?? 0)),
                'cancel_change' => $this->change($connection, 'cancel', (int) ($arguments['change_id'] ?? 0)),
                'undo_change' => $this->change($connection, 'undo', (int) ($arguments['change_id'] ?? 0)),
                'list_changes' => $this->listChanges($connection, (int) ($arguments['limit'] ?? 20)),
                'request_photos' => $this->requestPhotos($connection, (string) ($arguments['name'] ?? '')),
                'get_photos' => $this->getPhotos($connection, (int) ($arguments['draft_id'] ?? 0)),
                'connection_info' => $this->info($connection),
                default => $this->assistantTool($connection, $name, $arguments),
            };
        } catch (LocalizedException $e) {
            return self::error($e->getMessage());
        }
    }

    private function assistantTool(Connection $connection, string $name, array $arguments): array
    {
        $tool = $connection->tools()->get($name);
        if (!$tool instanceof ToolInterface) {
            return self::error('Unknown tool, or not allowed for this admin user\'s role: ' . $name);
        }
        $connection->tools()->authorize($tool, $arguments);
        $context = new Context($connection->conversationId(), $connection->userId(), $this->conversations);
        if (!$tool->changes()) {
            return self::json($tool->run($arguments, $context));
        }
        $preview = $tool->preview($arguments, $context);
        $changeId = $this->conversations->propose($connection->conversationId(), $connection->userId(), $tool->name(), $arguments, $preview);
        $lines = [];
        foreach ((array) ($preview['lines'] ?? []) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        return self::text(
            "Proposed change #" . $changeId . ": " . ($preview['title'] ?? $tool->name()) . "\n" . implode("\n", $lines)
            . "\n\nNothing is done yet. " . ($this->config->confirmsInAdmin($tool->name(), $arguments)
                ? $this->inAdmin($changeId)
                : "Show this to the admin; when they agree, call confirm_change with change_id " . $changeId . ".")
        );
    }

    /**
     * What Claude tells the admin about a change only the admin confirms.
     */
    private function inAdmin(int $changeId): string
    {
        return 'This kind of change is confirmed by the admin in the store admin; confirm_change cannot do it. Show it to the admin and ask them to check and confirm change #'
            . $changeId . ' at ' . $this->backendUrl->getUrl('zero_mcp/change/index', ['_nosecret' => true])
            . ' ; list_changes shows when it is done.';
    }

    private function change(Connection $connection, string $what, int $changeId): array
    {
        $action = $this->conversations->action($changeId, $connection->userId());
        if ($action === null || (int) $action['conversation_id'] !== $connection->conversationId()) {
            return self::error('No change #' . $changeId . ' of this connection.');
        }
        if ($what === 'confirm' && $this->config->confirmsInAdmin((string) $action['tool'], (array) ($action['input'] ?? []))) {
            return $action['status'] === 'proposed'
                ? self::text('Change #' . $changeId . ' is not done. ' . $this->inAdmin($changeId))
                : self::error('Change #' . $changeId . ': ' . $action['status']);
        }
        $tools = $connection->tools();
        if ($what === 'cancel') {
            $this->agent->cancel($changeId, $connection->userId(), $tools);
            return self::text('Change #' . $changeId . ' cancelled.');
        }
        return $what === 'undo'
            ? $this->undone($changeId, $this->agent->undo($changeId, $connection->userId(), $tools), $action)
            : $this->confirmed($changeId, $this->agent->confirm($changeId, $connection->userId(), $tools), (string) $action['tool']);
    }

    /**
     * confirm_change's answer: a success only when it is done; a bulk job's links to its page.
     *
     * @param array{status: string, result: array|string} $result
     */
    private function confirmed(int $changeId, array $result, string $tool): array
    {
        if ($result['status'] !== 'done') {
            return self::failure($changeId, $result);
        }
        $data = $result['result'];
        if ($tool === 'bulk_write_products' && is_array($data) && isset($data['job_id'])) {
            $data['job_url'] = $this->jobUrl((int) $data['job_id']);
        }
        return self::json(['change_id' => $changeId, 'status' => 'done', 'result' => $data]);
    }

    /**
     * undo_change's answer: undone, or a bulk job being undone (with its page); anything else is an error.
     *
     * @param array{status: string, result: array|string} $result
     * @param array<string, mixed> $action the change as it was before the undo
     */
    private function undone(int $changeId, array $result, array $action): array
    {
        $status = (string) $result['status'];
        if ($status === 'undone') {
            return self::json(['change_id' => $changeId, 'status' => 'undone', 'result' => $result['result']]);
        }
        if ($status === 'undoing' && is_array($result['result'])) {
            $jobId = (int) ($result['result']['job_id'] ?? 0);
            return self::json([
                'change_id' => $changeId,
                'status' => 'undoing',
                'result' => $result['result'] + ['job_url' => $this->jobUrl($jobId)],
                'note' => 'The products get their old texts back in the background; the job page shows when it is done.',
            ]);
        }
        // A bulk job still writing: where the admin stops it (its job id is in the confirmed change's result).
        $confirmed = (array) json_decode((string) ($action['result'] ?? ''), true);
        $jobId = (int) ($confirmed['job_id'] ?? 0);
        if ($status === 'failed' && !empty($result['still_writing']) && $jobId > 0 && is_string($result['result'])) {
            $result['result'] .= ' Stop it at ' . $this->jobUrl($jobId);
        }
        return self::failure($changeId, $result);
    }

    /**
     * @param array{status: string, result: array|string} $result
     */
    private static function failure(int $changeId, array $result): array
    {
        return self::error('Change #' . $changeId . ': ' . $result['status']
            . (is_string($result['result']) && $result['result'] !== '' ? ' (' . $result['result'] . ')' : ''));
    }

    /**
     * A bulk job's page in the admin: it opens without the URL's secret key, after the sign-in too.
     */
    private function jobUrl(int $jobId): string
    {
        return $this->backendUrl->getUrl('zero_ai/job/index', ['job_id' => $jobId, '_nosecret' => true]);
    }

    private function listChanges(Connection $connection, int $limit): array
    {
        $rows = [];
        foreach ($this->conversations->recentActions($connection->conversationId(), max(1, min(100, $limit))) as $action) {
            $rows[] = [
                'change_id' => (int) $action['action_id'],
                'tool' => $action['tool'],
                'title' => $action['preview']['title'] ?? '',
                'status' => $action['status'],
                'created_at' => $action['created_at'],
            ];
        }
        return self::json($rows);
    }

    private function requestPhotos(Connection $connection, string $name): array
    {
        $draft = $this->drafts->create($connection->tokenId(), $connection->conversationId(), $name !== '' ? $name : (string) __('Product photos'));
        $url = $this->config->url('zero_mcp/upload/index/key/' . $draft['key']);
        return self::text(
            'Photo link (valid 30 minutes, up to ' . Drafts::MAX_FILES . ' photos): ' . $url
            . "\nAsk the admin to open it on their phone, take or pick the photos, and tell you when done; then call get_photos with draft_id " . $draft['draft_id'] . '.'
        );
    }

    private function getPhotos(Connection $connection, int $draftId): array
    {
        $draft = $this->drafts->get($draftId, $connection->tokenId());
        if ($draft === null) {
            return self::error('No draft #' . $draftId . ' of this connection.');
        }
        if (!$draft['upload_ids']) {
            return self::text('No photos yet for draft #' . $draftId . '. The admin opens the link and adds them; ask again after.');
        }
        $content = [['type' => 'text', 'text' => 'Draft #' . $draftId . ' ("' . $draft['name'] . '"): upload ids ' . implode(', ', $draft['upload_ids']) . '. Each photo follows, labelled.']];
        foreach ($draft['upload_ids'] as $uploadId) {
            $upload = $this->conversations->upload($uploadId, $connection->userId());
            if ($upload === null) {
                continue;
            }
            $block = $this->uploads->contentBlock($upload);
            $content[] = ['type' => 'text', 'text' => 'upload #' . $uploadId . ' (' . $upload['name'] . ')'];
            $content[] = ['type' => 'image', 'data' => $block['source']['data'], 'mimeType' => $block['source']['media_type']];
        }
        return ['content' => $content];
    }

    private function info(Connection $connection): array
    {
        $tools = array_keys($connection->tools()->allowed());
        $inAdmin = [];
        foreach ($tools as $tool) {
            if ($this->config->confirmsInAdmin($tool)) {
                $inAdmin[] = $tool;
                continue;
            }
            foreach ($this->config->adminConfirmFields($tool) as $field) {
                $inAdmin[] = $tool . ':' . $field;
            }
        }
        return self::json([
            'admin_user' => $connection->userName(),
            'client' => $connection->clientName(),
            'store' => $this->config->origin(),
            'tools' => $tools,
            'commands' => array_keys($connection->commands()->allowed()),
            'how_changes_work' => 'A changing tool proposes; confirm_change runs it after the admin agrees; undo_change puts a product change back, or a bulk job (in the background, with a link to the job page); other changes cannot be undone. Changes of the kinds in confirmed_in_admin are confirmed by the admin in the store admin only; an entry tool:field covers the changes of that tool that give that field.',
            'confirmed_in_admin' => $inAdmin,
        ]);
    }

    /**
     * @return array{content: array}
     */
    private static function json(mixed $data): array
    {
        return self::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * @return array{content: array}
     */
    private static function text(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array{content: array, isError: bool}
     */
    private static function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
