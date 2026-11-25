<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

gate_require();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if (setting('installed') !== '1') {
    header('Location: /install.php');
    exit;
}

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = trim(rawurldecode($path), '/');

if ($path === '' || $path === 'index.php') {
    // Abierta dentro de un iframe (p. ej. cloud.bezares.com): ir directamente al panel
    // (que muestra el login si no hay sesión). El navegador envía Sec-Fetch-Dest: iframe.
    header('Vary: Sec-Fetch-Dest, Cookie');
    if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe') {
        header('Cache-Control: no-store');
        header('Location: /admin/', true, 302);
        exit;
    }
    render_index();
    exit;
}

// /e<evento>-t<tablet>[a-e]/ (se atiende igual sin la barra final: una redirección haría creer al
// service worker que el dispositivo ya no está autorizado). Modificador D: "$" no acepta un salto de línea final.
if (preg_match('/^e(\d{1,9})-t(\d{1,3})([a-e]?)$/iD', $path, $m)) {
    render_tablet((int)$m[1], (int)$m[2], $m[3] !== '' ? $m[3] : null);
    exit;
}
// Direcciones anteriores (p. ej. /bbm-t1a/): siguen funcionando para el evento que las usaba.
if (preg_match('/^(.{1,30}?)(\d{1,3})([a-e]?)$/iuD', $path, $m)) {
    $old = event_by_old_prefix($m[1]);
    if ($old) {
        render_tablet($old, (int)$m[2], $m[3] !== '' ? $m[3] : null);
        exit;
    }
}

not_found();
