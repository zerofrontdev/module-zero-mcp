<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Controller\Upload;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Uploads;
use Zero\Mcp\Controller\Endpoint;
use Zero\Mcp\Model\Config;
use Zero\Mcp\Model\Drafts;
use Zero\Mcp\Model\Pages;

/**
 * The photo page (zero_mcp/upload/index/key/…): opened on the phone from
 * the link request_photos gave. The key is the only credential: it names
 * one draft, for 30 minutes, up to 10 photos, which become uploads of the
 * connection's conversation.
 */
class Index extends Endpoint
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Config $config,
        private readonly Drafts $drafts,
        private readonly Uploads $uploads,
        private readonly Conversations $conversations,
        private readonly Pages $pages
    ) {
        parent::__construct($request, $rawFactory, $config);
    }

    public function execute()
    {
        $key = $this->request->getParam('key', '');
        $key = is_scalar($key) ? (string) $key : '';
        $draft = $this->config->isEnabled() && preg_match('/^[A-Za-z0-9_-]{20,64}$/', $key) ? $this->drafts->byKey($key) : null;
        if ($draft === null) {
            return $this->raw($this->pages->error((string) __('This link has expired'), (string) __('Ask Claude for a new photo link.')), 404, 'text/html; charset=utf-8');
        }
        $error = null;
        $done = 0;
        if ($this->http()->isPost()) {
            $files = array_filter(
                (array) ($this->http()->getFiles('photos') ?? []),
                // One file per entry: a nested field name (photos[a][b]) gives arrays, not text, and is skipped.
                static fn ($file) => is_array($file) && is_string($file['name'] ?? null) && is_string($file['tmp_name'] ?? null)
                    && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            );
            $room = Drafts::MAX_FILES - count($draft['upload_ids']);
            $ids = [];
            try {
                foreach (array_slice(array_values($files), 0, max(0, $room)) as $file) {
                    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                        throw new LocalizedException(__('%1 didn\'t upload (too large?).', (string) ($file['name'] ?? '')));
                    }
                    $ids[] = $this->uploads->store($draft['conversation_id'], (string) $file['tmp_name'], (string) $file['name']);
                }
            } catch (LocalizedException $e) {
                $error = $e->getMessage();
            }
            if ($ids) {
                $this->drafts->addUploads($draft['draft_id'], $ids);
                $draft['upload_ids'] = array_merge($draft['upload_ids'], $ids);
                $done = count($ids);
            }
        }
        $uploads = [];
        foreach ($draft['upload_ids'] as $uploadId) {
            $upload = $this->conversations->uploadOfConversation($uploadId, $draft['conversation_id']);
            if ($upload) {
                $uploads[] = $upload;
            }
        }
        $html = $this->pages->upload([
            'name' => (string) $draft['name'],
            'action' => $this->config->url('zero_mcp/upload/index/key/' . $key),
            'uploads' => $uploads,
            'expires_in' => max(0, strtotime($draft['expires_at'] . ' UTC') - time()),
            'error' => $error,
            'done' => $done,
            'max' => Drafts::MAX_FILES,
        ]);
        return $this->raw($html, 200, 'text/html; charset=utf-8');
    }
}
