<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';

gate_require();
send_security_headers("default-src 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex');
$user = require_admin_api();

$raw = file_get_contents('php://input', false, null, 0, 4_000_000);
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    json_error('Petición no válida');
}
$action = (string)($in['action'] ?? '');
$d = is_array($in['data'] ?? null) ? $in['data'] : [];

// ---------------------------------------------------------------------------
// Utilidades de validación
// ---------------------------------------------------------------------------
function in_str(array $d, string $k, int $max = 500): string
{
    $v = $d[$k] ?? '';
    if (!is_scalar($v)) {
        return '';
    }
    $v = trim((string)$v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

function in_int(array $d, string $k): ?int
{
    $v = $d[$k] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    return (int)$v;
}

function media_id_of_kind(?int $id, string $kind): ?int
{
    if (!$id) {
        return null;
    }
    $m = media_get($id);
    if (!$m || $m['kind'] !== $kind) {
        json_error('Medio no válido (' . $kind . ')');
    }
    return $id;
}

function valid_lang(string $code): bool
{
    $st = db()->prepare('SELECT 1 FROM languages WHERE code = ?');
    $st->execute([$code]);
    return (bool)$st->fetchColumn();
}

function tablets_payload(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM tablets ORDER BY number')->fetchAll() as $t) {
        $items = [];
        foreach (tablet_items((int)$t['id']) as $it) {
            $items[] = [
                'product_id' => (int)$it['product_id'], 'arrow' => $it['arrow'],
                'photo_media_id' => $it['photo_media_id'] !== null ? (int)$it['photo_media_id'] : null,
                'lang1' => $it['lang1'], 'lang2' => $it['lang2'], 'is_default' => (int)$it['is_default'] === 1,
            ];
        }
        $out[] = ['id' => (int)$t['id'], 'number' => (int)$t['number'], 'label' => $t['label'],
                  'active' => (int)$t['active'] === 1, 'items' => $items, 'url' => tablet_url((int)$t['number'])];
    }
    return $out;
}

function products_payload(): array
{
    $langs = [];
    foreach (db()->query("SELECT product_id, lang FROM product_i18n WHERE title <> '' OR description <> ''") as $r) {
        $langs[(int)$r['product_id']][] = $r['lang'];
    }
    $out = [];
    foreach (db()->query('SELECT * FROM products ORDER BY short_name COLLATE NOCASE')->fetchAll() as $p) {
        $out[] = ['id' => (int)$p['id'], 'short_name' => $p['short_name'],
                  'photo_media_id' => $p['photo_media_id'] !== null ? (int)$p['photo_media_id'] : null,
                  'model_media_id' => $p['model_media_id'] !== null ? (int)$p['model_media_id'] : null,
                  'langs' => $langs[(int)$p['id']] ?? []];
    }
    return $out;
}

function media_payload(): array
{
    return array_map('media_public', db()->query('SELECT * FROM media ORDER BY kind, name COLLATE NOCASE')->fetchAll());
}

function settings_payload(): array
{
    return [
        'event_title'     => setting('event_title', ''),
        'site_title'      => setting('site_title', ''),
        'url_prefix'      => setting('url_prefix', ''),
        'display_prefix'  => setting('display_prefix', ''),
        'header_media_id' => (int)setting('header_media_id', 0) ?: null,
        'logo_media_id'   => (int)setting('logo_media_id', 0) ?: null,
        'header_url'      => header_image_url(),
        'logo_url'        => logo_image_url(),
        'openai_model'    => setting('openai_model', ''),
        'openai_key_set'  => setting('openai_key', '') !== '',
        'openai_key_hint' => setting('openai_key', '') !== '' ? '…' . substr((string)setting('openai_key'), -4) : '',
        'mail_from'       => setting('mail_from', ''),
        'redirect_url'    => CFG['redirect_url'],
        'nginx_snippet'   => gate_nginx_snippet(),
        'htaccess_ok'     => gate_htaccess_ok(),
        'data_in_webroot' => security_rel_url(DATA_DIR) !== null,
        'security_last'   => json_decode((string)setting('security_last', 'null'), true),
        'max_upload_mb'   => (int)CFG['max_upload_mb'],
        'gd_webp'         => function_exists('imagewebp'),
    ];
}

function users_payload(): array
{
    return db()->query('SELECT id, username, email, role, last_login FROM users ORDER BY username')->fetchAll();
}

/** Ajustes visibles para un editor (sin datos de seguridad). */
function editor_settings_payload(): array
{
    $s = settings_payload();
    return array_intersect_key($s, array_flip(['header_url', 'logo_url', 'max_upload_mb', 'gd_webp', 'url_prefix', 'display_prefix']));
}

// Roles: los editores gestionan contenido (tablets, productos, galería);
// la configuración, la seguridad y los usuarios son sólo para administradores.
const ADMIN_ONLY_ACTIONS = ['settings.save', 'languages.save', 'openai.save', 'openai.test', 'gate.rotate', 'gate.write', 'security.check',
                            'cache.purge', 'user.add', 'user.delete', 'user.role'];
if (in_array($action, ADMIN_ONLY_ACTIONS, true) && !is_admin($user)) {
    log_event('acción denegada ' . $action . ' a ' . $user['username']);
    json_error('Sólo los administradores pueden hacer esto.', 403);
}

// ---------------------------------------------------------------------------
try {
    switch ($action) {

        case 'bootstrap':
            $admin = is_admin($user);
            json_out(['ok' => true, 'me' => (int)$user['id'], 'role' => $user['role'],
                      'settings' => $admin ? settings_payload() : editor_settings_payload(), 'languages' => languages(),
                      'tablets' => tablets_payload(), 'products' => products_payload(), 'media' => media_payload(),
                      'users' => $admin ? users_payload() : [['id' => (int)$user['id'], 'username' => $user['username'], 'email' => $user['email'], 'role' => $user['role']]]]);

        // ---------------- Productos ----------------
        case 'product.get':
            $p = product_full((int)in_int($d, 'id'));
            if (!$p) {
                json_error('Producto no encontrado', 404);
            }
            json_out(['ok' => true, 'product' => [
                'id' => (int)$p['id'], 'short_name' => $p['short_name'],
                'video_media_id' => $p['video_media_id'] !== null ? (int)$p['video_media_id'] : null,
                'video_url' => $p['video_url'] ?? '',
                'model_media_id' => $p['model_media_id'] !== null ? (int)$p['model_media_id'] : null,
                'photo_media_id' => $p['photo_media_id'] !== null ? (int)$p['photo_media_id'] : null,
                'i18n' => (object)$p['i18n'],
                'photos' => array_map(static fn($r) => ['media_id' => (int)$r['media_id'], 'size' => $r['size']], $p['photos']),
            ]]);

        case 'product.save':
            $id = in_int($d, 'id');
            $short = in_str($d, 'short_name', 60);
            if ($short === '') {
                json_error('El nombre reducido es obligatorio.');
            }
            $videoUrl = in_str($d, 'video_url', 300);
            if ($videoUrl !== '' && youtube_id($videoUrl) === null) {
                json_error('La URL de vídeo debe ser de YouTube (o deja el campo vacío).');
            }
            $video = media_id_of_kind(in_int($d, 'video_media_id'), 'video');
            $model = media_id_of_kind(in_int($d, 'model_media_id'), 'model');
            $photo = media_id_of_kind(in_int($d, 'photo_media_id'), 'photo');
            $i18n = is_array($d['i18n'] ?? null) ? $d['i18n'] : [];
            if (trim((string)($i18n['en']['title'] ?? '')) === '') {
                json_error('El título en inglés es obligatorio.');
            }
            $photos = [];
            foreach ((is_array($d['photos'] ?? null) ? $d['photos'] : []) as $ph) {
                if (!is_array($ph)) {
                    continue;
                }
                $mid = media_id_of_kind(in_int($ph, 'media_id'), 'photo');
                $size = in_array($ph['size'] ?? '', ['small', 'medium', 'large'], true) ? $ph['size'] : 'medium';
                if ($mid) {
                    $photos[] = [$mid, $size];
                }
            }
            $pdo = db();
            $pdo->beginTransaction();
            if ($id) {
                $st = $pdo->prepare('UPDATE products SET short_name=?, video_media_id=?, video_url=?, model_media_id=?, photo_media_id=?, updated_at=? WHERE id=?');
                $st->execute([$short, $video, $videoUrl, $model, $photo, time(), $id]);
                if (!$st->rowCount()) {
                    $pdo->rollBack();
                    json_error('Producto no encontrado', 404);
                }
            } else {
                $pdo->prepare('INSERT INTO products(short_name, video_media_id, video_url, model_media_id, photo_media_id, updated_at) VALUES(?,?,?,?,?,?)')
                    ->execute([$short, $video, $videoUrl, $model, $photo, time()]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM product_i18n WHERE product_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO product_i18n(product_id, lang, title, description, features) VALUES(?,?,?,?,?)');
            foreach ($i18n as $lang => $t) {
                if (!is_string($lang) || !is_array($t) || !valid_lang($lang)) {
                    continue;
                }
                $title = in_str($t, 'title', 300);
                $desc = in_str($t, 'description', 10000);
                $feat = in_str($t, 'features', 10000);
                if ($title === '' && $desc === '' && $feat === '') {
                    continue;
                }
                $ins->execute([$id, $lang, $title, $desc, $feat]);
            }
            $pdo->prepare('DELETE FROM product_photos WHERE product_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO product_photos(product_id, media_id, size, sort) VALUES(?,?,?,?)');
            foreach ($photos as $i => [$mid, $size]) {
                $ins->execute([$id, $mid, $size, $i]);
            }
            $pdo->commit();
            bump_content_version();
            json_out(['ok' => true, 'id' => $id, 'products' => products_payload()]);

        case 'product.delete':
            $id = (int)in_int($d, 'id');
            db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            bump_content_version();
            json_out(['ok' => true, 'products' => products_payload(), 'tablets' => tablets_payload()]);

        case 'product.translate':
            $lang = in_str($d, 'lang', 10);
            $st = db()->prepare('SELECT name FROM languages WHERE code = ?');
            $st->execute([$lang]);
            $name = $st->fetchColumn();
            if (!$name || $lang === 'en') {
                json_error('Idioma no válido');
            }
            if (throttle_exceeded('translate:' . $user['id'], 60, 3600)) {
                json_error('Límite de traducciones por hora alcanzado.', 429);
            }
            throttle_hit('translate:' . $user['id']);
            $fields = is_array($d['fields'] ?? null) ? $d['fields'] : [];
            $res = translate_product([
                'title' => in_str($fields, 'title', 300),
                'description' => in_str($fields, 'description', 10000),
                'features' => in_str($fields, 'features', 10000),
            ], $lang, (string)$name);
            json_out(['ok' => true, 'translation' => $res]);

        // ---------------- Tablets ----------------
        case 'tablet.save':
            $id = in_int($d, 'id');
            $number = (int)in_int($d, 'number');
            if ($number < 1 || $number > 999) {
                json_error('Número de tablet no válido (1-999).');
            }
            $active = !empty($d['active']);
            $label = in_str($d, 'label', 80);
            $items = array_values(array_filter(is_array($d['items'] ?? null) ? $d['items'] : [], 'is_array'));
            if (count($items) > 5) {
                json_error('Máximo 5 productos por tablet.');
            }
            if ($active && count($items) < 2) {
                json_error('Un tablet activo necesita entre 2 y 5 productos.');
            }
            $clean = [];
            $hasDefault = false;
            $pidCheck = db()->prepare('SELECT 1 FROM products WHERE id = ?');
            foreach ($items as $it) {
                $pid = (int)in_int($it, 'product_id');
                $pidCheck->execute([$pid]);
                if (!$pidCheck->fetchColumn()) {
                    json_error('Producto no válido en el tablet.');
                }
                $arrow = in_array($it['arrow'] ?? '', ['none', 'left', 'right', 'up'], true) ? $it['arrow'] : 'none';
                $l1 = in_str($it, 'lang1', 10) ?: 'en';
                $l2 = in_str($it, 'lang2', 10);
                if (!valid_lang($l1) || ($l2 !== '' && !valid_lang($l2))) {
                    json_error('Idioma no válido en el tablet.');
                }
                $def = !empty($it['is_default']) && !$hasDefault;
                $hasDefault = $hasDefault || $def;
                $clean[] = [$pid, $arrow, media_id_of_kind(in_int($it, 'photo_media_id'), 'photo'), $l1, $l2, $def ? 1 : 0];
            }
            if ($clean && !$hasDefault) {
                $clean[0][5] = 1;
            }
            $pdo = db();
            $dup = $pdo->prepare('SELECT id FROM tablets WHERE number = ? AND id <> ?');
            $dup->execute([$number, (int)$id]);
            if ($dup->fetchColumn()) {
                json_error('Ya existe un tablet con el número ' . $number . '.');
            }
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE tablets SET number=?, label=?, active=?, updated_at=? WHERE id=?')->execute([$number, $label, $active ? 1 : 0, time(), $id]);
            } else {
                $pdo->prepare('INSERT INTO tablets(number, label, active, updated_at) VALUES(?,?,?,?)')->execute([$number, $label, $active ? 1 : 0, time()]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM tablet_items WHERE tablet_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO tablet_items(tablet_id, product_id, sort, arrow, photo_media_id, lang1, lang2, is_default) VALUES(?,?,?,?,?,?,?,?)');
            foreach ($clean as $i => [$pid, $arrow, $ph, $l1, $l2, $def]) {
                $ins->execute([$id, $pid, $i, $arrow, $ph, $l1, $l2, $def]);
            }
            $pdo->commit();
            bump_content_version();
            json_out(['ok' => true, 'id' => $id, 'tablets' => tablets_payload()]);

        case 'tablet.delete':
            db()->prepare('DELETE FROM tablets WHERE id = ?')->execute([(int)in_int($d, 'id')]);
            bump_content_version();
            json_out(['ok' => true, 'tablets' => tablets_payload()]);

        // ---------------- Galería ----------------
        case 'media.list':
            json_out(['ok' => true, 'media' => media_payload()]);

        case 'media.scan':
            @set_time_limit(300);
            $r = media_scan();
            json_out(['ok' => true, 'result' => $r, 'media' => media_payload()]);

        case 'media.update':
            $m = media_get((int)in_int($d, 'id'));
            if (!$m) {
                json_error('Medio no encontrado', 404);
            }
            $name = in_str($d, 'name', 150) ?: $m['name'];
            $settings = media_settings($m);
            if ($m['kind'] === 'model' && is_array($d['settings'] ?? null)) {
                $lim = ['rx' => [-180, 180], 'ry' => [-180, 180], 'rz' => [-180, 180], 'size' => [10, 400], 'elevation' => [0, 180],
                        'exposure' => [0, 4], 'shadow' => [0, 2], 'speed' => [0, 180]];
                foreach ($lim as $k => [$lo, $hi]) {
                    if (isset($d['settings'][$k]) && is_numeric($d['settings'][$k])) {
                        $settings[$k] = max($lo, min($hi, round((float)$d['settings'][$k], 2)));
                    }
                }
            }
            db()->prepare('UPDATE media SET name = ?, settings = ? WHERE id = ?')->execute([$name, json_encode($settings), $m['id']]);
            bump_content_version();
            json_out(['ok' => true, 'media' => media_payload()]);

        case 'media.delete':
            media_delete((int)in_int($d, 'id'), !empty($d['files']));
            json_out(['ok' => true, 'media' => media_payload(), 'products' => products_payload()]);

        // ---------------- Configuración ----------------
        case 'settings.save':
            $prefix = strtolower(in_str($d, 'url_prefix', 30));
            if (!preg_match('/^[a-z][a-z0-9-]{0,28}[a-z-]$/', $prefix)) {
                json_error('Prefijo de URL: letras minúsculas, números y guiones; no puede terminar en número.');
            }
            $from = in_str($d, 'mail_from', 150);
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                json_error('Email remitente no válido.');
            }
            set_setting('event_title', in_str($d, 'event_title', 120));
            set_setting('site_title', in_str($d, 'site_title', 150));
            set_setting('url_prefix', $prefix);
            set_setting('display_prefix', in_str($d, 'display_prefix', 30));
            set_setting('header_media_id', (string)(media_id_of_kind(in_int($d, 'header_media_id'), 'photo') ?? ''));
            set_setting('logo_media_id', (string)(media_id_of_kind(in_int($d, 'logo_media_id'), 'photo') ?? ''));
            set_setting('openai_model', preg_replace('/[^A-Za-z0-9._:-]/', '', in_str($d, 'openai_model', 60)) ?: 'gpt-4o-mini');
            if ($from !== '') {
                set_setting('mail_from', $from);
            }
            bump_content_version();
            log_event('ajustes guardados por ' . $user['username']);
            json_out(['ok' => true, 'settings' => settings_payload()]);

        case 'openai.save':
            // Las claves actuales (sk-proj-…, sk-svcacct-…) superan los 160 caracteres: no recortar.
            $key = preg_replace('/\s+/', '', (string)($d['key'] ?? '')) ?? '';
            if ($key === '') {
                set_setting('openai_key', '');
                log_event('clave OpenAI borrada por ' . $user['username']);
                json_out(['ok' => true, 'settings' => settings_payload()]);
            }
            if (!preg_match('/^sk-[A-Za-z0-9_\-]{16,1000}$/D', $key)) {
                json_error('La clave no tiene el formato de una clave de OpenAI (empieza por "sk-").');
            }
            $model = preg_replace('/[^A-Za-z0-9._:-]/', '', in_str($d, 'model', 60));
            set_setting('openai_key', $key);
            if ($model !== '') {
                set_setting('openai_model', $model);
            }
            log_event('clave OpenAI guardada por ' . $user['username']);
            json_out(['ok' => true, 'settings' => settings_payload(), 'test' => openai_test_key()]);

        case 'openai.test':
            json_out(['ok' => true, 'test' => openai_test_key()]);

        case 'languages.save':
            $list = is_array($d['languages'] ?? null) ? $d['languages'] : [];
            $clean = [];
            foreach ($list as $l) {
                $code = is_array($l) ? strtolower(in_str($l, 'code', 10)) : '';
                $name = is_array($l) ? in_str($l, 'name', 40) : '';
                if (preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $code) && $name !== '') {
                    $clean[$code] = $name;
                }
            }
            if (!isset($clean['en'])) {
                json_error('El inglés (en) es obligatorio.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM languages');
            $st = $pdo->prepare('INSERT INTO languages(code, name, sort) VALUES(?,?,?)');
            $i = 0;
            foreach ($clean as $c => $n) {
                $st->execute([$c, $n, $i++]);
            }
            $pdo->commit();
            json_out(['ok' => true, 'languages' => languages()]);

        case 'gate.rotate':
            $ok = gate_rotate_token();
            gate_set_cookie();
            log_event('token de dispositivos regenerado por ' . $user['username']);
            // Verificación obligatoria tras la rotación: confirma que la puerta (incluidos
            // los estáticos) aplica ya el token nuevo.
            @set_time_limit(120);
            session_write_close();
            json_out(['ok' => true, 'written' => $ok, 'settings' => settings_payload(), 'report' => security_check()]);

        case 'gate.write':
            json_out(['ok' => true, 'written' => gate_write_htaccess(), 'settings' => settings_payload()]);

        case 'security.check':
            if (throttle_exceeded('seccheck:' . $user['id'], 10, 600)) {
                json_error('Espera unos minutos antes de repetir la verificación.', 429);
            }
            throttle_hit('seccheck:' . $user['id']);
            @set_time_limit(120);
            session_write_close(); // no bloquear la sesión mientras el servidor se llama a sí mismo
            json_out(['ok' => true, 'report' => security_check()]);

        case 'cache.purge':
            bump_content_version();
            json_out(['ok' => true]);

        // ---------------- Usuarios ----------------
        case 'user.password':
            $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
            $st->execute([$user['id']]);
            if (!password_verify((string)($d['current'] ?? ''), (string)$st->fetchColumn())) {
                json_error('La contraseña actual no es correcta.');
            }
            $new = (string)($d['new'] ?? '');
            if ($err = password_policy_error($new)) {
                json_error($err);
            }
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            // Cierra todas las demás sesiones del usuario; la actual sigue activa.
            invalidate_user_sessions((int)$user['id']);
            refresh_session_version((int)$user['id']);
            log_event('contraseña cambiada ' . $user['username']);
            json_out(['ok' => true]);

        case 'user.add':
            $u = in_str($d, 'username', 150);
            $em = in_str($d, 'email', 150);
            if ($em === '' && filter_var($u, FILTER_VALIDATE_EMAIL)) {
                $em = $u; // usuario = email
            }
            $pw = (string)($d['password'] ?? '');
            $role = ($d['role'] ?? '') === 'admin' ? 'admin' : 'editor';
            if (!valid_username($u) || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                json_error('Usuario o email no válidos.');
            }
            if ($err = password_policy_error($pw)) {
                json_error($err);
            }
            try {
                db()->prepare('INSERT INTO users(username, email, pass_hash, role, created_at) VALUES(?,?,?,?,?)')
                    ->execute([$u, $em, password_hash($pw, PASSWORD_DEFAULT), $role, time()]);
            } catch (PDOException) {
                json_error('Ese usuario ya existe.');
            }
            log_event('usuario creado ' . $u . ' (' . $role . ') por ' . $user['username']);
            json_out(['ok' => true, 'users' => users_payload()]);

        case 'user.role':
            $uid = (int)in_int($d, 'id');
            $role = ($d['role'] ?? '') === 'admin' ? 'admin' : 'editor';
            if ($uid === (int)$user['id']) {
                json_error('No puedes cambiar tu propio rol.');
            }
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
            invalidate_user_sessions($uid); // aplica el nuevo rol de inmediato
            log_event('rol de usuario id=' . $uid . ' cambiado a ' . $role . ' por ' . $user['username']);
            json_out(['ok' => true, 'users' => users_payload()]);

        case 'user.delete':
            $uid = (int)in_int($d, 'id');
            if ($uid === (int)$user['id']) {
                json_error('No puedes eliminar tu propio usuario.');
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            log_event('usuario eliminado id=' . $uid . ' por ' . $user['username']);
            json_out(['ok' => true, 'users' => users_payload()]);

        default:
            json_error('Acción desconocida', 404);
    }
} catch (RuntimeException $ex) {
    if ($ex instanceof PDOException) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('[api] ' . $action . ': ' . $ex);
        json_error('Error de base de datos', 500);
    }
    json_error($ex->getMessage(), 502);
} catch (Throwable $ex) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('[api] ' . $action . ': ' . $ex);
    json_error('Error interno del servidor', 500);
}
