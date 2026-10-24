<?php

namespace App\Support;

/**
 * Public uploads are served only from the admin host.
 * Marketing-site, API, localhost, and /storage/ paths are never returned to apps.
 */
final class PublicMedia
{
    public const ORIGIN = 'https://admin.karnaride.in';

    public static function presentMediaUrl(mixed $path, mixed $version = null): ?string
    {
        $relative = self::storagePath($path);
        if ($relative !== null) {
            $url = self::ORIGIN.$relative;
            $stamp = trim((string) $version);
            if ($stamp !== '') {
                $url .= '?v='.rawurlencode($stamp);
            }

            return $url;
        }

        return self::externalUrl($path);
    }

    /**
     * Stock or CDN photos stay as entered. Marketing, API, and localhost hosts do not.
     */
    private static function externalUrl(mixed $path): ?string
    {
        $raw = trim((string) $path);
        if (! str_starts_with(strtolower($raw), 'https://')) {
            return null;
        }
        $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
        $onlyPath = (string) parse_url($raw, PHP_URL_PATH);
        if ($host === '' || self::blockedHost($host) || str_starts_with($onlyPath, '/storage') || str_contains($onlyPath, '/api/')) {
            return null;
        }

        return $raw;
    }

    private static function blockedHost(string $host): bool
    {
        return in_array($host, [
            'karnaride.in',
            'www.karnaride.in',
            'api.karnaride.in',
            'localhost',
            '127.0.0.1',
            '10.0.2.2',
            '0.0.0.0',
        ], true);
    }

    /**
     * Keep only an /uploads/ path. Absolute hosts are stripped so nothing is stored as karnaride.in.
     */
    public static function storagePath(mixed $path): ?string
    {
        $raw = trim((string) $path);
        if ($raw === '' || strcasecmp($raw, 'null') === 0) {
            return null;
        }
        $parts = parse_url($raw);
        $onlyPath = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $raw;
        $onlyPath = '/'.ltrim(str_replace('\\', '/', $onlyPath), '/');
        if ($onlyPath === '/' || str_contains($onlyPath, '..')) {
            return null;
        }
        if (preg_match('#^/storage(/|$)#', $onlyPath)) {
            return null;
        }
        if (! str_starts_with($onlyPath, '/uploads/')) {
            return null;
        }

        return $onlyPath;
    }
}
