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

$uid = (string)($_GET['id'] ?? '');
$i = (int)($_GET['i'] ?? -1);
$n = (int)($_GET['n'] ?? 0);
$name = (string)($_GET['name'] ?? '');
$kind = (string)($_GET['kind'] ?? '');
$convertOf = (int)($_GET['convert_of'] ?? 0);

if (!preg_match('/^[a-f0-9]{32}$/', $uid) || $i < 0 || $n < 1 || $n > 20000 || $i >= $n || $name === '' || mb_strlen($name) > 200) {
    json_error('Parámetros de subida no válidos');
}
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
// Limpieza de subidas abandonadas (> 24 h)
if (random_int(1, 20) === 1) {
    foreach (glob($tmpDir . '/*.{part,json}', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
}
$part = "$tmpDir/$uid.part";
$meta = "$tmpDir/$uid.json";

if ($i === 0) {
    file_put_contents($meta, json_encode(['user' => (int)$user['id'], 'next' => 0, 'name' => $name, 'kind' => $kind, 'n' => $n]));
    file_put_contents($part, '');
}
$state = is_file($meta) ? json_decode((string)file_get_contents($meta), true) : null;
if (!is_array($state) || $state['user'] !== (int)$user['id'] || $state['next'] !== $i || $state['name'] !== $name || $state['n'] !== $n) {
    json_error('Secuencia de subida no válida; vuelve a intentarlo', 409);
}

$in = fopen('php://input', 'rb');
$out = fopen($part, 'ab');
if (!$in || !$out) {
    json_error('No se puede escribir el fichero temporal', 500);
}
$copied = stream_copy_to_stream($in, $out, 64 * 1024 * 1024);
fclose($in);
fclose($out);
if ($copied === false || $copied === 0) {
    json_error('Trozo vacío');
}
clearstatcache(true, $part);
if (filesize($part) > CFG['max_upload_mb'] * 1024 * 1024) {
    @unlink($part);
    @unlink($meta);
    json_error('El fichero supera el tamaño máximo permitido');
}
$state['next'] = $i + 1;
file_put_contents($meta, json_encode($state));

if ($i < $n - 1) {
    json_out(['ok' => true, 'next' => $i + 1]);
}

// ---- Último trozo: validar y mover a /media ----
@unlink($meta);
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
