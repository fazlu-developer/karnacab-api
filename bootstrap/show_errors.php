<?php

/**
 * Production 500s: when APP_DEBUG=true in .env, print the real PHP/Laravel error
 * in the browser (cPanel often hides Laravel's default error page).
 * Set APP_DEBUG=false after you finish debugging.
 */

function karnacab_debug_wanted(): bool
{
    static $wanted = null;
    if ($wanted !== null) {
        return $wanted;
    }

    $envFile = dirname(__DIR__).'/.env';
    $wanted = false;
    if (is_readable($envFile)) {
        $raw = (string) file_get_contents($envFile);
        if (preg_match('/^\s*APP_DEBUG\s*=\s*(true|1|"true")/mi', $raw) === 1) {
            $wanted = true;
        }
    }

    return $wanted;
}

function karnacab_error_html(string $title, string $message, string $file = '', int $line = 0, string $trace = ''): string
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $meta = '';
    if ($file !== '') {
        $meta = '<p style="color:#444;font-size:14px"><code>'.$h($file).($line ? ':'.$line : '').'</code></p>';
    }

    $traceBlock = '';
    if ($trace !== '') {
        $traceBlock = '<h2 style="font-size:16px;margin-top:28px">Stack</h2><pre style="white-space:pre-wrap;background:#111;color:#eee;padding:16px;border-radius:8px;overflow:auto;font-size:12px">'.$h($trace).'</pre>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($title).'</title></head>'
        .'<body style="font-family:Segoe UI,system-ui,sans-serif;max-width:920px;margin:40px auto;padding:0 20px;color:#111">'
        .'<p style="color:#b45309;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:12px">KarnaCab website debug</p>'
        .'<h1 style="font-size:22px;line-height:1.3">'.$h($title).'</h1>'
        .'<p style="font-size:16px;background:#fff7ed;border:1px solid #fdba74;padding:12px 14px;border-radius:8px">'.$h($message).'</p>'
        .$meta
        .$traceBlock
        .'<p style="margin-top:32px;color:#666;font-size:13px">Turn this off: set <code>APP_DEBUG=false</code> in <code>.env</code>, then run <code>php artisan config:clear</code>.</p>'
        .'</body></html>';
}

if (karnacab_debug_wanted()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (! in_array($error['type'], $fatal, true)) {
            return;
        }
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo karnacab_error_html(
            'PHP fatal error',
            (string) $error['message'],
            (string) ($error['file'] ?? ''),
            (int) ($error['line'] ?? 0),
        );
    });
}
