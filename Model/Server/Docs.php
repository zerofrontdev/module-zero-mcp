<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mcp\Model\Server;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\ModuleListInterface;

/**
 * The store's documentation as MCP resources: every enabled Zero module's
 * README (zero://docs/<Module>) and the theme's docs
 * (zero://docs/theme/<file>). Claude reads them to answer "how do I…"
 * from what the modules actually say.
 */
class Docs
{
    private const THEME = 'frontend/Zero/default';

    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ComponentRegistrarInterface $registrar
    ) {
    }

    /**
     * @return array<int, array{uri: string, name: string, title: string, description: string, mimeType: string}>
     */
    public function list(): array
    {
        $resources = [];
        foreach ($this->moduleList->getNames() as $module) {
            if (!str_starts_with($module, 'Zero_')) {
                continue;
            }
            $path = $this->registrar->getPath(ComponentRegistrar::MODULE, $module);
            if ($path && is_file($path . '/README.md')) {
                $resources[] = [
                    'uri' => 'zero://docs/' . substr($module, 5),
                    'name' => $module,
                    'title' => $module . ' README',
                    'description' => self::firstLine($path . '/README.md'),
                    'mimeType' => 'text/markdown',
                ];
            }
        }
        $theme = $this->registrar->getPath(ComponentRegistrar::THEME, self::THEME);
        foreach ($theme && is_dir($theme . '/docs') ? glob($theme . '/docs/*.md') ?: [] : [] as $file) {
            $resources[] = [
                'uri' => 'zero://docs/theme/' . basename($file, '.md'),
                'name' => 'theme/' . basename($file, '.md'),
                'title' => 'Zero theme: ' . basename($file, '.md'),
                'description' => self::firstLine($file),
                'mimeType' => 'text/markdown',
            ];
        }
        return $resources;
    }

    /**
     * @return string|null the Markdown, or null for an unknown URI
     */
    public function read(string $uri): ?string
    {
        if (preg_match('#^zero://docs/theme/([A-Za-z0-9_-]+)$#', $uri, $m)) {
            $theme = $this->registrar->getPath(ComponentRegistrar::THEME, self::THEME);
            $file = $theme ? $theme . '/docs/' . $m[1] . '.md' : '';
            return $file && is_file($file) ? (string) file_get_contents($file) : null;
        }
        if (preg_match('#^zero://docs/([A-Za-z0-9]+)$#', $uri, $m) && $this->moduleList->has('Zero_' . $m[1])) {
            $path = $this->registrar->getPath(ComponentRegistrar::MODULE, 'Zero_' . $m[1]);
            return $path && is_file($path . '/README.md') ? (string) file_get_contents($path . '/README.md') : null;
        }
        return null;
    }

    private static function firstLine(string $file): string
    {
        $handle = fopen($file, 'r');
        $title = $handle ? trim((string) fgets($handle), "# \n\r") : '';
        if ($handle) {
            fclose($handle);
        }
        return mb_substr($title, 0, 200);
    }
}
