<?php

namespace App\Support;

class ViteManifest
{
    /**
     * @return array{js: string, css: array<int, string>}
     */
    public static function entry(string $manifestPath, string $entryKey, string $basePath = ''): array
    {
        if (!is_file($manifestPath)) {
            throw new \RuntimeException('Vite manifest not found: '.$manifestPath);
        }

        $raw = file_get_contents($manifestPath);
        $manifest = json_decode((string) $raw, true);

        if (!is_array($manifest) || !isset($manifest[$entryKey]) || !is_array($manifest[$entryKey])) {
            throw new \RuntimeException('Vite entry not found in manifest: '.$entryKey);
        }

        $entry = $manifest[$entryKey];
        $file = $entry['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new \RuntimeException('Invalid Vite manifest entry for: '.$entryKey);
        }

        $css = [];
        if (isset($entry['css']) && is_array($entry['css'])) {
            foreach ($entry['css'] as $c) {
                if (is_string($c) && $c !== '') {
                    $css[] = rtrim($basePath, '/').'/'.$c;
                }
            }
        }

        return [
            'js' => rtrim($basePath, '/').'/'.$file,
            'css' => $css,
        ];
    }
}
