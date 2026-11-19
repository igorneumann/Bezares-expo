<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';
require APP_DIR . '/lib/admin_layout.php';

gate_require();
if (setting('installed') !== '1') {
    header('Location: /install.php');
    exit;
}

session_boot();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } else {
        [$ok, $msg] = attempt_login(
            trim((string)($_POST['login'] ?? '')),
            (string)($_POST['password'] ?? ''),
            !empty($_POST['remember'])
        );
        if ($ok) {
            header('Location: /admin/', true, 303);
            exit;
        }
        $error = $msg;
    }
}

$user = current_user();

if (!$user) {
    $notice = isset($_GET['reset']) ? 'Contraseña actualizada. Ya puedes iniciar sesión.' : (isset($_GET['bye']) ? 'Sesión cerrada.' : null);
    admin_simple_page('Acceso', '<h1>Administración</h1>'
        . flash_html($error) . flash_html($notice, 'ok')
        . '<form method="post" class="auth-form" autocomplete="on">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label for="login">Usuario o email</label><input id="login" name="login" required autocomplete="username" autofocus value="' . e($_POST['login'] ?? '') . '">'
        . '<label for="password">Contraseña</label><input id="password" name="password" type="password" required autocomplete="current-password">'
        . '<label class="check"><input type="checkbox" name="remember" value="1"> Mantener la sesión iniciada</label>'
        . '<button type="submit" class="btn btn-primary btn-block">Entrar</button>'
        . '</form><p class="auth-links"><a href="/admin/forgot.php">¿Has olvidado la contraseña?</a></p>');
    exit;
}

// ---- Aplicación del panel ----
$importMap = '{"imports":{"three":"' . asset('assets/vendor/three/three.module.min.js') . '"}}';
$importHash = 'sha256-' . base64_encode(hash('sha256', $importMap, true));
send_security_headers(admin_csp($importHash));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>Administración – Expo Bezares</title>
<link rel="icon" href="<?= e(asset('assets/icon-250.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= e(asset('assets/vendor/model-viewer.min.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/admin.js')) ?>"></script>
</head>
<body class="admin">
<header class="topbar">
  <a class="brand" href="/" target="_blank" rel="noopener"><img src="<?= e(asset('assets/logo-bezares.svg')) ?>" alt="" width="48" height="31"> Expo</a>
  <nav class="tabs" role="tablist">
    <button role="tab" data-tab="tablets" aria-selected="true">Tablets</button>
    <button role="tab" data-tab="products">Productos</button>
    <button role="tab" data-tab="gallery">Galería</button>
    <button role="tab" data-tab="events">Eventos</button>
    <button role="tab" data-tab="settings">Configuración</button>
  </nav>
  <div class="who">
    <span><?= e($user['username']) ?></span>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-ghost btn-sm" type="submit">Salir</button></form>
  </div>
</header>
<main id="app" class="app" aria-live="polite"><p class="loading">Cargando…</p></main>
<div id="toast" class="toast" role="status" hidden></div>
<dialog id="modal" class="modal"><div class="modal-body"></div></dialog>
</body>
</html>
