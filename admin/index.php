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
<?= favicon_links() ?>
<link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= e(asset('assets/vendor/model-viewer.min.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/admin.js')) ?>"></script>
</head>
<?php
// Iconos del menú (trazos simples, heredan el color del texto)
$ico = static fn(string $d): string => '<svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$menu = [
    'tablets'  => ['Tablets', 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM11 18h2'],
    'products' => ['Productos', 'M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM12 12l8-4.5M12 12v9M12 12L4 7.5'],
    'gallery'  => ['Galería', 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15.5 8.5h.01'],
    'events'   => ['Eventos', 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4'],
    'stats'    => ['Estadísticas', 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
    'settings' => ['Configuración', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
];
$roleLabel = ($user['role'] ?? '') === 'admin' ? 'Administrador' : 'Editor de contenido';
?>
<body class="admin">
<div class="layout">
  <aside class="side">
    <a class="brand" href="/" target="_blank" rel="noopener" title="Abrir la web en otra pestaña">
      <img src="<?= e(asset('assets/logo-expo-full-dark.svg')) ?>" alt="Bezares Expo" width="160" height="44">
    </a>
    <nav class="nav" role="tablist" aria-label="Secciones">
      <?php foreach ($menu as $key => [$label, $path]): ?>
      <button role="tab" data-tab="<?= $key ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>" aria-selected="<?= $key === 'tablets' ? 'true' : 'false' ?>"><?= $ico($path) ?><span><?= e($label) ?></span></button>
      <?php if ($key === 'tablets'): ?><div class="nav-sub" id="nav-events" role="group" aria-label="Eventos"></div><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="who-name" title="<?= e($user['email']) ?>"><?= e($user['username']) ?></div>
      <div class="who-role"><?= e($roleLabel) ?></div>
      <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-sm side-btn" type="submit">Cerrar sesión</button></form>
    </div>
  </aside>
  <main id="app" class="app" aria-live="polite"><p class="loading">Cargando…</p></main>
</div>
<div id="toast" class="toast" role="status" hidden></div>
<dialog id="modal" class="modal"><div class="modal-body"></div></dialog>
</body>
</html>
