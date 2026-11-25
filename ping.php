<?php
declare(strict_types=1);

/*
 * Comprobación de autorización del tablet (la usa el service worker): 204 si la cookie de
 * dispositivo sigue siendo válida; si no, la puerta redirige. No devuelve ningún dato.
 */
require __DIR__ . '/_boot.php';

gate_require();
send_security_headers("default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
http_response_code(204);
