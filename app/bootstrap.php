<?php
declare(strict_types=1);

const APP_VERSION = '1.0.0';
define('APP_DIR', __DIR__);

if (!defined('PUBLIC_ROOT')) {
    http_response_code(500);
    exit;
}

// ---- Configuración -------------------------------------------------------
// Datos (SQLite, sesiones, logs): si existe la carpeta "private" de Plesk junto al
// webroot se usa esa (fuera de la web); si no, app/data (protegida por .htaccess).
$__private = dirname(PUBLIC_ROOT) . '/private';
$__cfg = [
    'data_dir'      => (@is_dir($__private) && @is_writable($__private)) ? $__private . '/expo-data' : APP_DIR . '/data',
    'redirect_url'  => 'https://bezares.com/',     // destino para visitantes sin cookie
    'public_url'    => 'https://expo.bezares.com', // URL canónica (enlaces de los emails; evita "Host header injection")
    'media_dir'     => 'media',                    // carpeta (relativa al webroot) para medios / FTP
    // Límites de subida (protección frente a agotamiento del almacenamiento)
    'max_upload_mb'        => 2048,   // por fichero
    'media_quota_mb'       => 30720,  // total de la galería
    'user_daily_upload_mb' => 10240,  // por usuario y 24 h
    'tmp_quota_mb'         => 4096,   // subidas a medias en curso
    'max_parallel_uploads' => 3,      // simultáneas por usuario
    'min_free_disk_mb'     => 2048,   // espacio libre mínimo que se respeta en disco
    'debug'         => false,
];
if (is_file(APP_DIR . '/config.php')) {
    $__local = require APP_DIR . '/config.php';
    if (is_array($__local)) {
        $__cfg = array_replace($__cfg, $__local);
    }
}
define('CFG', $__cfg);
define('DATA_DIR', rtrim(CFG['data_dir'], '/\\'));

ini_set('display_errors', CFG['debug'] ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Madrid');

foreach ([DATA_DIR, DATA_DIR . '/sessions', DATA_DIR . '/tmp', DATA_DIR . '/logs'] as $__d) {
    if (!is_dir($__d)) {
        @mkdir($__d, 0750, true);
    }
}
// Protección por si la carpeta data acaba dentro del webroot.
if (!is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
}
ini_set('error_log', DATA_DIR . '/logs/php-error.log');

require APP_DIR . '/lib/helpers.php';
require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/gate.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/media.php';
require APP_DIR . '/lib/translate.php';
require APP_DIR . '/lib/views.php';
require APP_DIR . '/lib/security.php';

// Mantener /.htaccess sincronizado con la plantilla (necesario tras cada despliegue Git).
gate_sync_htaccess();
