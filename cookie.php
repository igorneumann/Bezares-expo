<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

send_security_headers("default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || setting('installed') !== '1') {
    header('Location: /cookie.html', true, 303);
    exit;
}

$bucket = 'cookie-ip:' . client_ip();
$fail = static function (string $msg): never {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Acceso</title><body style="font-family:system-ui;text-align:center;padding:3rem 1rem">'
        . '<p>' . e($msg) . '</p><p><a href="/cookie.html">Volver</a></p></body>';
    exit;
};

if (throttle_exceeded($bucket, 10, 900)) {
    $fail('Demasiados intentos. Espera 15 minutos.');
}

$code = (string)($_POST['code'] ?? '');
$hash = (string)setting('access_code_hash', '');
if ($hash !== '' && !password_verify($code, $hash)) {
    throttle_hit($bucket);
    log_event('cookie: código incorrecto');
    $fail('Código de acceso incorrecto.');
}

throttle_clear($bucket);
gate_set_cookie();
log_event('cookie: dispositivo autorizado');
header('Location: /', true, 303);
