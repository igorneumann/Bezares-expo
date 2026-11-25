<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/** Página simple (login, recuperar contraseña…) */
function admin_simple_page(string $title, string $bodyHtml): void
{
    send_security_headers(admin_csp());
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' – Expo Bezares</title>'
        . favicon_links()
        . '<link rel="stylesheet" href="' . e(asset('assets/admin.css')) . '"></head>'
        . '<body class="auth"><main class="auth-card"><img class="auth-logo" src="' . e(asset('assets/logo-expo-full-light.svg')) . '" alt="Bezares Expo" width="210" height="58">'
        . $bodyHtml . '</main></body></html>';
}

function flash_html(?string $msg, string $type = 'error'): string
{
    return $msg ? '<div class="flash flash-' . e($type) . '" role="alert">' . e($msg) . '</div>' : '';
}
