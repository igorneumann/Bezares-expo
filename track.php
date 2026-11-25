<?php
declare(strict_types=1);

/*
 * Recepción de estadísticas desde los tablets (POST JSON {"events":[...]}).
 * Sólo dispositivos autorizados (cookie), mismo origen, tamaño y frecuencia limitados.
 * Responde con los ids aceptados para que el tablet los borre de su cola local.
 */
require __DIR__ . '/_boot.php';

gate_require();
send_security_headers("default-src 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Método no permitido', 405);
}
same_origin_or_die();
if (throttle_exceeded('track:' . client_ip(), 120, 60)) {
    json_error('Demasiadas peticiones', 429);
}
throttle_hit('track:' . client_ip());

$raw = file_get_contents('php://input', false, null, 0, 300_000);
$in = json_decode((string)$raw, true);
if (!is_array($in) || !is_array($in['events'] ?? null)) {
    json_error('Petición no válida');
}
json_out(['ok' => true, 'ids' => stats_record($in['events'])]);
