<?php
/**
 * Punto de arranque común a todas las entradas PHP públicas.
 * Todo el proyecto vive en httpdocs (despliegue con Plesk Git); el código interno
 * está en /app, que nunca se sirve por web (app/.htaccess, .htaccess raíz y nginx).
 */
declare(strict_types=1);

// Nunca ejecutable directamente (por si el servidor no aplica el .htaccess a los .php).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

define('PUBLIC_ROOT', __DIR__);

if (!is_file(__DIR__ . '/app/bootstrap.php')) {
    http_response_code(500);
    exit('Application files not found.');
}
require __DIR__ . '/app/bootstrap.php';
