<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

send_security_headers("default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

// Una vez instalado, el instalador deja de existir a efectos prácticos.
if (setting('installed') === '1' || (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    not_found();
}

$errors = [];
$checks = [
    'PHP >= 8.1'                      => PHP_VERSION_ID >= 80100,
    'PDO SQLite'                      => extension_loaded('pdo_sqlite'),
    'GD (miniaturas)'                 => extension_loaded('gd'),
    'cURL (traducciones ChatGPT)'     => extension_loaded('curl'),
    'Carpeta de datos escribible'     => is_writable(DATA_DIR),
    'Carpeta media escribible'        => is_writable(media_root()),
    '.htaccess generado desde la plantilla' => gate_htaccess_ok(),
];
$dataLocation = security_rel_url(DATA_DIR) !== null
    ? 'httpdocs' . security_rel_url(DATA_DIR) . '/ (protegida por .htaccess)'
    : basename(dirname(DATA_DIR)) . '/' . basename(DATA_DIR) . '/ (junto a httpdocs, fuera de la web)';

// Clave de instalación: se genera en la carpeta de datos (sólo accesible por FTP)
// para que nadie más pueda ejecutar el instalador antes que el administrador.
$keyFile = DATA_DIR . '/install.key';
if (!is_file($keyFile)) {
    @file_put_contents($keyFile, random_token(8) . "\n", LOCK_EX);
    @chmod($keyFile, 0600);
}
$installKey = trim((string)@file_get_contents($keyFile));

session_boot();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión caducada, vuelve a intentarlo.';
    }
    if (throttle_exceeded('install-ip:' . client_ip(), 10, 3600)) {
        $errors[] = 'Demasiados intentos.';
    } elseif ($installKey === '' || !hash_equals($installKey, trim((string)($_POST['install_key'] ?? '')))) {
        throttle_hit('install-ip:' . client_ip());
        $errors[] = 'Clave de instalación incorrecta.';
    }
    $user = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    // Si el usuario es un email y no se ha indicado otro, se usa también como email de recuperación.
    if ($email === '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
        $email = $user;
    }
    if (!valid_username($user)) {
        $errors[] = 'Usuario: tu email o un nombre de 3-40 caracteres (letras, números, . _ -).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email no válido.';
    }
    if ($err = password_policy_error($pass)) {
        $errors[] = $err;
    }
    if ($pass !== $pass2) {
        $errors[] = 'Las contraseñas no coinciden.';
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users(username, email, pass_hash, role, created_at) VALUES(?,?,?,'admin',?)")
            ->execute([$user, $email, password_hash($pass, PASSWORD_DEFAULT), time()]);
        $uid = (int)$pdo->lastInsertId();
        set_setting('device_token', random_token(24));
        set_setting('installed', '1');
        // Tablets iniciales 1-7 (como en la web actual), sin productos.
        $st = $pdo->prepare('INSERT OR IGNORE INTO tablets(number, label, active, updated_at) VALUES(?, \'\', 0, ?)');
        for ($i = 1; $i <= 7; $i++) {
            $st->execute([$i, time()]);
        }
        $pdo->commit();
        @unlink($keyFile);
        $written = gate_write_htaccess();
        gate_set_cookie();
        login_user($uid, false);
        log_event('instalación completada por ' . $user);
        if (!$written) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Instalado</title><body style="font-family:system-ui;max-width:760px;margin:2rem auto;padding:0 1rem">'
                . '<h1>Instalación completada</h1><p><strong>No se pudo escribir /.htaccess.</strong> Da permiso de escritura en httpdocs '
                . 'al usuario del sitio y recarga cualquier página: se generará automáticamente.</p>'
                . '<p><a href="/admin/#settings">Ir al panel</a></p></body>';
            exit;
        }
        // Lleva directamente a la verificación de seguridad.
        header('Location: /admin/#settings', true, 303);
        exit;
    }
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Instalación – Expo Bezares</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;background:#f3f6f8;margin:0;padding:24px 16px;color:#1d2a33}
main{max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 8px 24px rgba(0,40,60,.1)}
h1{margin-top:0;color:#00567D} label{display:block;font-weight:600;margin:14px 0 4px;font-size:.9rem}
input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #c7d2d9;border-radius:8px;font-size:1rem}
button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:8px;background:#00567D;color:#fff;font-weight:700;font-size:1rem}
.err{background:#fde8ea;color:#8a1020;padding:10px 14px;border-radius:8px} ul.chk{padding:0;list-style:none;font-size:.9rem}
.ok{color:#11793b}.ko{color:#b3261e} small{color:#5d6b74}
</style></head><body><main>
<h1>Instalación</h1>
<ul class="chk"><?php foreach ($checks as $k => $ok): ?><li class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? '✔' : '✖' ?> <?= e($k) ?></li><?php endforeach; ?></ul>
<?php if ($errors): ?><div class="err"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label for="k">Clave de instalación</label><input id="k" name="install_key" required autocomplete="off">
<small>Está en el fichero <code>install.key</code> de la carpeta de datos: <code><?= e($dataLocation) ?></code> (accesible por FTP o el Administrador de archivos de Plesk).</small>
<label for="u">Usuario administrador (puede ser tu email)</label><input id="u" name="username" required maxlength="150" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
<label for="m">Email para recuperar la contraseña (vacío = el del usuario)</label><input id="m" type="email" name="email" maxlength="150" value="<?= e($_POST['email'] ?? '') ?>">
<label for="p">Contraseña (mín. 10 caracteres)</label><input id="p" type="password" name="password" required minlength="10" autocomplete="new-password">
<label for="p2">Repetir contraseña</label><input id="p2" type="password" name="password2" required minlength="10" autocomplete="new-password">
<small>Para autorizar cada tablet basta con abrir expo.bezares.com/cookie.html en él.</small>
<button type="submit">Instalar</button>
</form></main></body></html>
