<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/** Escapa para HTML. */
function e(mixed $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Plesk (nginx delante de Apache) informa del esquema original.
    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function host_name(): string
{
    $h = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Sólo caracteres válidos de host para evitar inyección de cabeceras / enlaces.
    return preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $h) ? strtolower($h) : 'localhost';
}

function base_url(): string
{
    return (is_https() ? 'https://' : 'http://') . host_name();
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_error(string $msg, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Cabeceras de seguridad comunes. $csp se pasa completo para cada tipo de página.
 */
function send_security_headers(string $csp): void
{
    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    // X-Frame-Options no admite listas de sitios: sólo se envía si nadie puede incrustar la
    // página; si hay sitios de confianza manda frame-ancestors de la CSP.
    if (str_contains($csp, "frame-ancestors 'none'")) {
        header('X-Frame-Options: DENY');
    }
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
}

function public_csp(array $extraFrameOrigins = []): string
{
    $frames = implode(' ', array_unique(array_merge(['https://www.youtube-nocookie.com'], $extraFrameOrigins)));
    return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self' blob: data:; "
        . "worker-src 'self' blob:; font-src 'self'; frame-src " . $frames . "; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors " . frame_ancestors_value();
}

/** Sitios de confianza que pueden incrustar las páginas públicas en un iframe. */
function trusted_frame_origins(): array
{
    $v = setting('frame_origins', null);
    $v = $v === null ? 'https://cloud.bezares.com' : (string)$v;
    return array_values(array_filter(explode(' ', $v), static fn($o) => $o !== ''));
}

function frame_ancestors_value(): string
{
    $list = trusted_frame_origins();
    return $list ? "'self' " . implode(' ', $list) : "'none'";
}

/**
 * Valida una lista de orígenes (uno por línea o separados por espacios/comas).
 * Acepta https://dominio[:puerto] y https://*.dominio. Devuelve [lista normalizada, errores].
 */
function parse_frame_origins(string $text): array
{
    $ok = [];
    $errors = [];
    foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $raw) {
        if ($raw === '') {
            continue;
        }
        $o = strtolower(rtrim($raw, '/'));
        if (preg_match('~^https://(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(:\d{1,5})?$~D', $o)) {
            $ok[$o] = true;
        } else {
            $errors[] = $raw;
        }
    }
    return [array_keys($ok), $errors];
}

function admin_csp(string $extraScriptHash = ''): string
{
    $h = $extraScriptHash !== '' ? " '" . $extraScriptHash . "'" : '';
    return "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'" . $h . "; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self' blob: data:; "
        . "worker-src 'self' blob:; font-src 'self'; frame-src https://www.youtube-nocookie.com; "
        // El panel también puede abrirse dentro del iframe de los sitios de confianza
        // (mismo listado que las páginas públicas); el resto de webs no pueden incrustarlo.
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors " . frame_ancestors_value();
}

/** Versión de contenido: se incrementa en cada cambio del admin (ETag de páginas). */
function content_version(): string
{
    return (string)setting('content_ver', '1');
}

function bump_content_version(): void
{
    set_setting('content_ver', (string)(time() . random_int(100, 999)));
}

/** Respuesta condicional (304) para páginas públicas. */
function conditional_page(string $key): void
{
    $assets = @filemtime(PUBLIC_ROOT . '/assets/app.css') . '.' . @filemtime(PUBLIC_ROOT . '/assets/expo.js');
    $etag = '"' . substr(hash('sha256', $key . '|' . content_version() . '|' . APP_VERSION . '|' . $assets), 0, 32) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, no-cache');
    header('Vary: Cookie');
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($inm !== '' && trim($inm) === $etag) {
        http_response_code(304);
        exit;
    }
}

function asset(string $path): string
{
    $file = PUBLIC_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? substr(base_convert((string)filemtime($file), 10, 36), -6) : APP_VERSION;
    return '/' . ltrim($path, '/') . '?v=' . $v;
}

function slugify(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
    return trim($s, '-') ?: 'item';
}

function log_event(string $msg): void
{
    @file_put_contents(
        DATA_DIR . '/logs/app.log',
        date('c') . ' ' . client_ip() . ' ' . preg_replace('/[\r\n]+/', ' ', $msg) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function not_found(): never
{
    http_response_code(404);
    send_security_headers(public_csp());
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>404</title><body style="font-family:system-ui;text-align:center;padding:4rem">'
        . '<h1>404</h1><p><a href="/">Inicio</a></p></body>';
    exit;
}
