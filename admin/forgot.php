<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';
require APP_DIR . '/lib/admin_layout.php';

gate_require();
session_boot();

$done = false;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } elseif (throttle_exceeded('forgot-ip:' . client_ip(), 5, 3600)) {
        $error = 'Demasiadas solicitudes. Inténtalo más tarde.';
    } else {
        throttle_hit('forgot-ip:' . client_ip());
        $login = trim((string)($_POST['login'] ?? ''));
        $st = db()->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if ($u) {
            $token = random_token(32);
            $pdo = db();
            $pdo->prepare('DELETE FROM reset_tokens WHERE user_id = ? OR expires < ?')->execute([$u['id'], time()]);
            $pdo->prepare('INSERT INTO reset_tokens(user_id, token_hash, expires) VALUES(?,?,?)')
                ->execute([$u['id'], hash('sha256', $token), time() + 3600]);
            // URL canónica de configuración, nunca la cabecera Host de la petición.
            $link = rtrim((string)CFG['public_url'], '/') . '/admin/reset.php?t=' . $token;
            send_mail($u['email'], 'Recuperar contraseña – Expo Bezares',
                "Hola {$u['username']},\n\nPara establecer una nueva contraseña abre este enlace (válido 1 hora) "
                . "desde un dispositivo autorizado:\n\n{$link}\n\nSi no lo has solicitado, ignora este mensaje.\n");
            log_event('reset solicitado ' . $u['username']);
        }
        $done = true; // Mismo mensaje exista o no el usuario.
    }
}

$body = '<h1>Recuperar contraseña</h1>';
if ($done) {
    $body .= flash_html('Si el usuario existe, recibirás un email con un enlace para restablecer la contraseña.', 'ok')
        . '<p class="auth-links"><a href="/admin/">Volver</a></p>';
} else {
    $body .= flash_html($error)
        . '<form method="post" class="auth-form"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label for="login">Usuario o email</label><input id="login" name="login" required autocomplete="username" autofocus>'
        . '<button class="btn btn-primary btn-block" type="submit">Enviar enlace</button></form>'
        . '<p class="auth-links"><a href="/admin/">Volver al acceso</a></p>';
}
admin_simple_page('Recuperar contraseña', $body);
