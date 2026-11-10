<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model;

use Magento\Framework\Escaper;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The two pages a person sees: the connect page Claude opens (it sends
 * the admin to the admin to allow the connection), and the photo page for
 * the phone. Standalone HTML (no theme, no
 * scripts), small enough for any phone.
 */
class Pages
{
    public function __construct(
        private readonly Escaper $escaper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The page Claude opens: no password here, the admin allows the
     * connection in the admin.
     *
     * @param array{client_name: string, redirect_host: string, code: string, minutes: int, admin_url: string|null} $data
     */
    public function authorize(array $data): string
    {
        $e = $this->escaper;
        $link = $data['admin_url'] !== null
            ? '<p><a class="button" href="' . $e->escapeUrl($data['admin_url']) . '">' . $e->escapeHtml(__('Open the admin')) . '</a></p>'
            : '';
        $body = <<<HTML
<h1>{$e->escapeHtml(__('Connect %1 to %2', $data['client_name'], $this->storeName()))}</h1>
<p>{$e->escapeHtml(__('Allow it in the store\'s admin: sign in to the admin in this browser within %1 minutes, and the request opens there. The admin\'s own sign-in, two-step verification and lockout apply.', $data['minutes']))}</p>
{$link}
<p class="muted">{$e->escapeHtml(__('In another browser or on another device: in the admin, open System > Other Settings > Claude Connections and enter this code.'))}</p>
<p class="code">{$e->escapeHtml($data['code'])}</p>
<p class="muted small">{$e->escapeHtml(__('The connection gets your admin role\'s permissions. After you allow it you go back to %1.', $data['redirect_host']))}</p>
HTML;
        return $this->page((string) __('Connect to %1', $this->storeName()), $body);
    }

    public function error(string $title, string $message): string
    {
        $e = $this->escaper;
        return $this->page($title, '<h1>' . $e->escapeHtml($title) . '</h1><p class="error" role="alert">' . $e->escapeHtml($message) . '</p>');
    }

    /**
     * @param array{name: string, action: string, uploads: array<int, array>, expires_in: int, error: string|null, done: int, max: int} $data
     */
    public function upload(array $data): string
    {
        $e = $this->escaper;
        $items = '';
        foreach ($data['uploads'] as $upload) {
            $items .= '<li>#' . (int) $upload['upload_id'] . ' ' . $e->escapeHtml($upload['name']) . '</li>';
        }
        $list = $items !== '' ? '<p><strong>' . $e->escapeHtml(__('Photos in: %1', count($data['uploads']))) . '</strong></p><ul>' . $items . '</ul>' : '';
        $error = $data['error'] !== null ? '<p class="error" role="alert">' . $e->escapeHtml($data['error']) . '</p>' : '';
        $done = $data['done'] > 0 ? '<p class="ok">' . $e->escapeHtml(__('%1 photo(s) added. Go back to Claude and say the photos are in.', $data['done'])) . '</p>' : '';
        $left = max(0, $data['max'] - count($data['uploads']));
        $form = $left > 0 ? <<<HTML
<form method="post" action="{$e->escapeUrl($data['action'])}" enctype="multipart/form-data">
    <label class="pick"><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <span>{$e->escapeHtml(__('Take or choose photos'))}</span></label>
    <button type="submit">{$e->escapeHtml(__('Upload'))}</button>
    <p class="muted small">{$e->escapeHtml(__('JPEG, PNG or WebP, up to 10 MB each; %1 more can be added. This link works for %2 more minutes.', $left, (int) ceil($data['expires_in'] / 60)))}</p>
</form>
HTML : '<p class="muted">' . $e->escapeHtml(__('That is the most photos this link takes.')) . '</p>';
        $body = '<h1>' . $e->escapeHtml($data['name']) . '</h1>' . $done . $error . $list . $form;
        return $this->page((string) __('Photos for %1', $this->storeName()), $body);
    }

    private function page(string $title, string $body): string
    {
        $e = $this->escaper;
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$e->escapeHtml($title)}</title>
<style>
body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d1d1b;background:#f6f5f2}
main{max-width:30rem;margin:0 auto;padding:2rem 1.25rem 4rem}
h1{font-size:1.4rem;margin:0 0 .75rem}
label{display:block;margin:1rem 0 .3rem;font-weight:600}
input[type=text]{width:100%;box-sizing:border-box;padding:.7rem .8rem;font:inherit;border:1px solid #bbb;border-radius:8px;background:#fff}
button,.button{display:block;box-sizing:border-box;width:100%;margin-top:1.25rem;padding:.9rem;font:inherit;font-weight:600;text-align:center;text-decoration:none;color:#fff;background:#1d1d1b;border:0;border-radius:8px}
.code{margin:.5rem 0 1.5rem;font:600 2rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;text-align:center}
.pick{display:block;padding:1.5rem;margin-top:1rem;text-align:center;border:2px dashed #999;border-radius:12px;background:#fff;cursor:pointer}
.pick input{display:block;margin:0 auto .5rem}
.muted{color:#5e5e5a}.small{font-size:.9rem}.error{color:#b3261e;font-weight:600}.ok{color:#1a6b2b;font-weight:600}
ul{padding-left:1.2rem}
</style>
</head>
<body><main>{$body}<p class="muted small">Zero for Magento</p></main></body>
</html>
HTML;
    }

    private function storeName(): string
    {
        return (string) ($this->storeManager->getDefaultStoreView()?->getFrontendName() ?: $this->storeManager->getDefaultStoreView()?->getName());
    }
}
