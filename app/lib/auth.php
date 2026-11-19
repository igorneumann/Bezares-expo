<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

const SESSION_NAME   = 'expo_admin';
const REMEMBER_COOKIE = 'expo_remember';
const REMEMBER_DAYS  = 30;
const SESSION_IDLE   = 2 * 3600; // sin "mantener sesión": caduca tras 2 h de inactividad

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE);
    session_save_path(DATA_DIR . '/sessions');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if (!empty($_SESSION['uid']) && (time() - (int)($_SESSION['last'] ?? 0)) > SESSION_IDLE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = time();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(32);
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    session_boot();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Rechaza peticiones con Origin de otro sitio (defensa extra frente a CSRF). */
function same_origin_or_die(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== base_url()) {
        json_error('Origen no permitido', 403);
    }
}

function current_user(): ?array
{
    session_boot();
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT id, username, email FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['uid']]);
        $u = $st->fetch();
        if ($u) {
            return $u;
        }
        unset($_SESSION['uid']);
    }
    return remember_login();
}

function login_user(int $uid, bool $remember): void
{
    session_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = random_token(32);
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $uid]);
    if ($remember) {
        remember_issue($uid);
    }
}

function logout_user(): void
{
    session_boot();
    remember_forget();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

function remember_issue(int $uid): void
{
    $selector = random_token(12);
    $validator = random_token(32);
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->prepare('INSERT INTO remember_tokens(user_id, selector, validator_hash, expires) VALUES(?,?,?,?)')
        ->execute([$uid, $selector, hash('sha256', $validator), $expires]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

function remember_login(): ?array
{
    $c = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($c) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) {
        return null;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM remember_tokens WHERE expires < ?')->execute([time()]);
    $st = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ?');
    $st->execute([$m[1]]);
    $row = $st->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        if ($row) { // posible robo de token: invalidar todos los del usuario
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$row['user_id']]);
        }
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
        return null;
    }
    // Rotación del token en cada uso.
    $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
    login_user((int)$row['user_id'], true);
    $st = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $st->execute([(int)$row['user_id']]);
    return $st->fetch() ?: null;
}

function remember_forget(): void
{
    $c = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($c) && preg_match('/^([a-f0-9]{24}):/', $c, $m)) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$m[1]]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
}

/**
 * Intenta iniciar sesión. Devuelve [ok, mensaje].
 */
function attempt_login(string $login, string $password, bool $remember): array
{
    $ipBucket = 'login-ip:' . client_ip();
    $userBucket = 'login-user:' . strtolower($login);
    if (throttle_exceeded($ipBucket, 10, 900) || throttle_exceeded($userBucket, 5, 900)) {
        log_event('login bloqueado ' . $login);
        return [false, 'Demasiados intentos. Espera 15 minutos.'];
    }
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$login, $login]);
    $u = $st->fetch();
    // Se verifica siempre un hash para no revelar si el usuario existe por tiempos de respuesta.
    $hash = $u['pass_hash'] ?? password_hash(random_token(8), PASSWORD_DEFAULT);
    $ok = password_verify($password, $hash) && $u;
    if (!$ok) {
        throttle_hit($ipBucket);
        throttle_hit($userBucket);
        log_event('login fallido ' . $login);
        return [false, 'Usuario o contraseña incorrectos.'];
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    throttle_clear($userBucket);
    login_user((int)$u['id'], $remember);
    log_event('login ok ' . $u['username']);
    return [true, ''];
}

function password_policy_error(string $p): ?string
{
    if (mb_strlen($p) < 10) {
        return 'La contraseña debe tener al menos 10 caracteres.';
    }
    if (mb_strlen($p) > 200) {
        return 'Contraseña demasiado larga.';
    }
    return null;
}

function require_admin_page(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: /admin/');
        exit;
    }
    return $u;
}

/** Para api.php / upload.php */
function require_admin_api(): array
{
    same_origin_or_die();
    $u = current_user();
    if (!$u) {
        json_error('Sesión caducada', 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_check($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_error('Token CSRF no válido', 403);
    }
    return $u;
}

function send_mail(string $to, string $subject, string $body): bool
{
    $from = (string)setting('mail_from', 'no-reply@' . host_name());
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = "From: Expo Bezares <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: expo";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subject, $body, $headers, '-f' . $from);
}
