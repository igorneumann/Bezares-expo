<?php
declare(strict_types=1);

/*
 * Subida por trozos (evita los límites upload_max_filesize/post_max_size del hosting).
 * Cada petición POST envía el trozo como cuerpo binario (application/octet-stream)
 * y los metadatos en la query string:
 *   id=<32 hex> & i=<índice> & n=<total> & name=<nombre> & kind=video|model|photo [& convert_of=<media id>]
 */
require dirname(__DIR__) . '/_boot.php';

gate_require();
send_security_headers("default-src 'none'; frame-ancestors 'none'");
$user = require_admin_api();
@set_time_limit(300);

const MB = 1048576;
const MAX_CHUNK = 8 * MB;

$uid = (string)($_GET['id'] ?? '');
$i = (int)($_GET['i'] ?? -1);
$n = (int)($_GET['n'] ?? 0);
$size = (int)($_GET['size'] ?? 0);           // tamaño total declarado del fichero
$name = (string)($_GET['name'] ?? '');
$kind = (string)($_GET['kind'] ?? '');
$convertOf = (int)($_GET['convert_of'] ?? 0);
$maxFile = (int)CFG['max_upload_mb'] * MB;

// El cliente trocea en bloques de 4 MB: el número de trozos debe cuadrar con el tamaño.
if (!preg_match('/^[a-f0-9]{32}$/', $uid) || $i < 0 || $n < 1 || $i >= $n || $name === '' || mb_strlen($name) > 200
    || $size < 1 || $n !== max(1, (int)ceil($size / (4 * MB)))) {
    json_error('Parámetros de subida no válidos');
}
if ($size > $maxFile) {
    json_error('El fichero supera el tamaño máximo permitido (' . CFG['max_upload_mb'] . ' MB)', 413);
}
// Liberar el bloqueo de sesión: las subidas no deben bloquear el resto del panel.
session_write_close();
$ext = media_ext($name);
if ($convertOf) {
    if ($ext !== 'glb') {
        json_error('La conversión debe enviar un GLB');
    }
    $kind = 'model';
}
if (!isset(MEDIA_FOLDERS[$kind]) || (MEDIA_EXT[$ext] ?? null) !== $kind) {
    json_error('Tipo de fichero no permitido para ' . $kind);
}

$tmpDir = DATA_DIR . '/tmp';
// Limpieza de subidas abandonadas (> 6 h) y del histórico de uso (> 2 días)
if (random_int(1, 10) === 1) {
    foreach (glob($tmpDir . '/*.{part,json,lock}', GLOB_BRACE) ?: [] as $f) {
        if (@filemtime($f) < time() - 6 * 3600) {
            @unlink($f);
        }
    }
    db()->prepare('DELETE FROM upload_usage WHERE ts < ?')->execute([time() - 2 * 86400]);
}
$part = "$tmpDir/$uid.part";
$meta = "$tmpDir/$uid.json";

$abort = static function (string $msg, int $code) use ($part, $meta): never {
    @unlink($part);
    @unlink($meta);
    json_error($msg, $code);
};

// Bloqueo exclusivo por identificador: impide escrituras concurrentes sobre la misma subida.
$lockFh = fopen("$tmpDir/$uid.lock", 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    json_error('Esta subida ya se está procesando', 409);
}
register_shutdown_function(static function () use ($lockFh, $tmpDir, $uid): void {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    if (!is_file("$tmpDir/$uid.json")) {
        @unlink("$tmpDir/$uid.lock");
    }
});

if ($i === 0) {
    if (is_file($meta)) {
        json_error('Identificador de subida duplicado', 409);
    }
    $me = (int)$user['id'];

    // 1) Subidas simultáneas por usuario
    $active = 0;
    foreach (glob($tmpDir . '/*.json') ?: [] as $f) {
        $s = json_decode((string)@file_get_contents($f), true);
        if (is_array($s) && ($s['user'] ?? 0) === $me && @filemtime($f) > time() - 1800) {
            $active++;
        }
    }
    if ($active >= (int)CFG['max_parallel_uploads']) {
        json_error('Demasiadas subidas simultáneas; espera a que terminen las actuales.', 429);
    }

    // 2) Cuota de temporales
    $tmpUsed = 0;
    foreach (glob($tmpDir . '/*.part') ?: [] as $f) {
        $tmpUsed += (int)@filesize($f);
    }
    if ($tmpUsed + $size > (int)CFG['tmp_quota_mb'] * MB) {
        json_error('Espacio temporal de subidas agotado; inténtalo más tarde.', 507);
    }

    // 3) Cuota global de medios
    $mediaUsed = (int)db()->query('SELECT COALESCE(SUM(size), 0) FROM media')->fetchColumn();
    if ($mediaUsed + $size > (int)CFG['media_quota_mb'] * MB) {
        json_error('Se ha alcanzado la cuota total de medios (' . CFG['media_quota_mb'] . ' MB). Elimina ficheros de la galería.', 507);
    }

    // 4) Cuota diaria por usuario (se reserva al empezar, aunque la subida se cancele)
    $st = db()->prepare('SELECT COALESCE(SUM(bytes), 0) FROM upload_usage WHERE user_id = ? AND ts > ?');
    $st->execute([$me, time() - 86400]);
    if ((int)$st->fetchColumn() + $size > (int)CFG['user_daily_upload_mb'] * MB) {
        json_error('Has alcanzado tu cuota diaria de subida (' . CFG['user_daily_upload_mb'] . ' MB).', 429);
    }

    // 5) Espacio libre en disco (temporal y destino), con margen de seguridad
    $minFree = (int)CFG['min_free_disk_mb'] * MB;
    foreach ([$tmpDir, media_root()] as $dir) {
        $free = @disk_free_space($dir);
        if ($free !== false && $free - $size < $minFree) {
            log_event('subida rechazada por falta de espacio en disco');
            json_error('No hay espacio suficiente en el servidor.', 507);
        }
    }

    db()->prepare('INSERT INTO upload_usage(user_id, bytes, ts) VALUES(?,?,?)')->execute([$me, $size, time()]);
    file_put_contents($meta, json_encode(['user' => $me, 'next' => 0, 'name' => $name, 'kind' => $kind, 'n' => $n, 'size' => $size]), LOCK_EX);
    file_put_contents($part, '');
}
$state = is_file($meta) ? json_decode((string)file_get_contents($meta), true) : null;
if (!is_array($state) || $state['user'] !== (int)$user['id'] || $state['next'] !== $i || $state['name'] !== $name
    || $state['n'] !== $n || $state['size'] !== $size) {
    json_error('Secuencia de subida no válida; vuelve a intentarlo', 409);
}

$in = fopen('php://input', 'rb');
$out = fopen($part, 'ab');
if (!$in || !$out) {
    json_error('No se puede escribir el fichero temporal', 500);
}
// Nunca más de MAX_CHUNK por petición ni más de lo declarado.
$copied = stream_copy_to_stream($in, $out, MAX_CHUNK + 1);
fclose($in);
fclose($out);
clearstatcache(true, $part);
if ($copied === false || $copied === 0) {
    json_error('Trozo vacío');
}
if ($copied > MAX_CHUNK || filesize($part) > $size) {
    $abort('El trozo o el fichero superan el tamaño declarado', 413);
}
$state['next'] = $i + 1;
file_put_contents($meta, json_encode($state), LOCK_EX);
touch($meta);

if ($i < $n - 1) {
    json_out(['ok' => true, 'next' => $i + 1]);
}

// ---- Último trozo: validar y mover a /media ----
@unlink($meta);
if (filesize($part) !== $size) {
    $abort('Tamaño final distinto del declarado', 400);
}
if ($err = media_validate_content($part, $ext)) {
    @unlink($part);
    json_error($err);
}

if ($convertOf) {
    $m = media_get($convertOf);
    if (!$m || $m['kind'] !== 'model') {
        @unlink($part);
        json_error('Modelo original no encontrado', 404);
    }
    $source = $m['source_path'] ?: $m['path'];
    $base = pathinfo($source, PATHINFO_FILENAME);
    $folder = media_rel_prefix() . 'models/_converted';
    if (!is_dir(PUBLIC_ROOT . '/' . $folder)) {
        mkdir(PUBLIC_ROOT . '/' . $folder, 0755, true);
    }
    // Sustituye la conversión anterior si existía.
    if ($m['source_path'] && $m['path'] !== $source && ($old = media_abs($m['path'])) && is_file($old)) {
        @unlink($old);
    }
    $rel = media_unique_path($folder, $base . '.glb');
    if (!rename($part, PUBLIC_ROOT . '/' . $rel)) {
        json_error('No se pudo guardar el fichero', 500);
    }
    @chmod(PUBLIC_ROOT . '/' . $rel, 0644);
    db()->prepare("UPDATE media SET path = ?, source_path = ?, status = 'ready', size = ?, mtime = ? WHERE id = ?")
        ->execute([$rel, $source, filesize(PUBLIC_ROOT . '/' . $rel), filemtime(PUBLIC_ROOT . '/' . $rel), $m['id']]);
    bump_content_version();
    json_out(['ok' => true, 'done' => true, 'id' => (int)$m['id'], 'media' => media_public(media_get_fresh((int)$m['id']))]);
}

$rel = media_unique_path(media_rel_prefix() . MEDIA_FOLDERS[$kind], $name);
if (!rename($part, PUBLIC_ROOT . '/' . $rel)) {
    json_error('No se pudo guardar el fichero', 500);
}
@chmod(PUBLIC_ROOT . '/' . $rel, 0644);
$id = media_register($rel, pathinfo($name, PATHINFO_FILENAME));
if (!$id) {
    @unlink(PUBLIC_ROOT . '/' . $rel);
    json_error('No se pudo registrar el fichero');
}
log_event('subida ' . $rel . ' por ' . $user['username']);
json_out(['ok' => true, 'done' => true, 'id' => $id, 'media' => media_public(media_get_fresh($id))]);
