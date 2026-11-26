<?php
declare(strict_types=1);

/*
 * Autorización de dispositivos. Se accede como /cookie.html?k=<clave> (el .htaccess la
 * reescribe aquí): añade la cookie de autorización y redirige a la página principal sin
 * intervención del usuario. La clave está sólo en el servidor (el enlace completo aparece
 * en el panel, Configuración); sin ella, la petición se trata como cualquier visita sin cookie.
 */
require __DIR__ . '/_boot.php';

// Puede abrirse dentro del iframe de un sitio de confianza (p. ej. cloud.bezares.com).
send_security_headers("default-src 'none'; base-uri 'none'; frame-ancestors " . frame_ancestors_value());
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    exit;
}

// Antes de instalar no hay token válido que entregar: llevar al instalador
// (protegido por su clave de un solo uso) en lugar de a bezares.com.
if (setting('installed') !== '1') {
    header('Location: /install.php', true, 302);
    exit;
}

// Con la clave del enlace se autoriza; un dispositivo ya autorizado sólo renueva su cookie.
$withKey = gate_key_valid();
if (!$withKey && !gate_valid()) {
    header('Location: ' . CFG['redirect_url'], true, 302);
    exit;
}
gate_set_cookie();
if ($withKey) {
    log_event('cookie: dispositivo autorizado (' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) . ')');
}
// Dentro de un iframe se entra directamente en el panel de administración.
$framed = ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe' || ($_GET['to'] ?? '') === 'admin';
header('Location: ' . ($framed ? '/admin/' : '/'), true, 302);
