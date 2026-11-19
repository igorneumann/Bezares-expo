<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';
require APP_DIR . '/lib/admin_layout.php';

gate_require();
session_boot();
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT * FROM reset_tokens WHERE token_hash = ? AND used = 0 AND expires > ?');
    $st->execute([hash('sha256', $token), time()]);
    $row = $st->fetch() ?: null;
}
if (!$row) {
    admin_simple_page('Enlace no válido', '<h1>Enlace no válido</h1>'
        . flash_html('El enlace ha caducado o ya se ha usado.')
        . '<p class="auth-links"><a href="/admin/forgot.php">Solicitar otro enlace</a></p>');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } elseif ($e = password_policy_error($p1)) {
        $error = $e;
    } elseif ($p1 !== $p2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), $row['user_id']]);
        $pdo->prepare('UPDATE reset_tokens SET used = 1 WHERE id = ?')->execute([$row['id']]);
        $pdo->commit();
        invalidate_user_sessions((int)$row['user_id']); // cierra todas las sesiones abiertas
        log_event('contraseña restablecida user_id=' . $row['user_id']);
        header('Location: /admin/?reset=1', true, 303);
        exit;
    }
}

admin_simple_page('Nueva contraseña', '<h1>Nueva contraseña</h1>' . flash_html($error)
    . '<form method="post" class="auth-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . '<input type="hidden" name="t" value="' . e($token) . '">'
    . '<label for="p1">Nueva contraseña (mín. 10 caracteres)</label><input id="p1" type="password" name="password" required minlength="10" autocomplete="new-password" autofocus>'
    . '<label for="p2">Repetir contraseña</label><input id="p2" type="password" name="password2" required minlength="10" autocomplete="new-password">'
    . '<button class="btn btn-primary btn-block" type="submit">Guardar</button></form>');
