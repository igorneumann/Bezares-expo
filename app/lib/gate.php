<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * "Puerta" de acceso por cookie de dispositivo.
 * Sin la cookie correcta cualquier petición se redirige a CFG['redirect_url'],
 * excepto /.well-known/ (renovación SSL) y /cookie.html|/cookie.php.
 *
 * Se aplica en dos capas:
 *  1) .htaccess (bloque EXPO-GATE generado aquí) -> protege también los ficheros estáticos/medios.
 *  2) PHP (gate_require) -> defensa en profundidad para todas las entradas PHP.
 */

const GATE_COOKIE = 'expo_device';
const GATE_MAX_AGE = 400 * 86400; // máximo que permiten los navegadores actuales

function gate_valid(): bool
{
    $token = (string)setting('device_token', '');
    $c = $_COOKIE[GATE_COOKIE] ?? '';
    return $token !== '' && is_string($c) && hash_equals($token, $c);
}

function gate_require(): void
{
    if (gate_valid()) {
        return;
    }
    header('Cache-Control: no-store');
    header('Location: ' . CFG['redirect_url'], true, 302);
    exit;
}

function gate_set_cookie(): void
{
    setcookie(GATE_COOKIE, (string)setting('device_token'), [
        'expires'  => time() + GATE_MAX_AGE,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function gate_htaccess_block(bool $allowInstaller = false): string
{
    $token = preg_replace('/[^a-f0-9]/', '', (string)setting('device_token'));
    $redirect = str_replace(['"', ' ', "\n", "\r"], '', CFG['redirect_url']);
    $inst = $allowInstaller ? "RewriteCond %{REQUEST_URI} !^/install\\.php$\n" : '';
    return "# BEGIN EXPO-GATE (generado por el panel de administración; no editar a mano)\n"
        . "RewriteCond %{REQUEST_URI} !^/\\.well-known/\n"
        . "RewriteCond %{REQUEST_URI} !^/cookie\\.(html|php)$\n"
        . $inst
        . "RewriteCond %{HTTP_COOKIE} !(^|;\\s*)" . GATE_COOKIE . "=" . $token . "(;|$)\n"
        . "RewriteRule ^ " . $redirect . " [R=302,L]\n"
        . "# END EXPO-GATE";
}

/**
 * Directivas para "Directivas nginx adicionales" de Plesk.
 * NO contienen el token: la puerta de cookie la aplica sólo Apache (.htaccess generado
 * automáticamente), así no hay secretos que sincronizar a mano entre Apache y nginx.
 * nginx sólo bloquea rutas internas, que no cambian nunca.
 * Requisito: "Servir ficheros estáticos directamente mediante nginx" desactivado
 * (lo comprueba la verificación de seguridad).
 */
function gate_nginx_snippet(): string
{
    // "if" a nivel de servidor (fase rewrite): se aplica antes que las "location" de PHP de Plesk.
    return "if (\$uri ~ \"^/(app/|data/|_boot\\.php)\") { return 403; }\n"
        . "if (\$uri ~ \"/\\.(?!well-known/)\") { return 403; }\n"
        . "if (\$uri ~* \"\\.(md|sqlite|sqlite3|log|key|template|lock|part|tmp)$\") { return 403; }";
}

/*
 * El /.htaccess raíz NO está en git: se genera desde app/htaccess.template.
 * Así un despliegue automático (Plesk Git) nunca sobrescribe el token de la puerta,
 * y cualquier cambio en la plantilla se aplica solo en la siguiente petición PHP.
 */
function gate_expected_htaccess(): ?string
{
    $tpl = @file_get_contents(APP_DIR . '/htaccess.template');
    if ($tpl === false || !str_contains($tpl, '{{GATE}}')) {
        return null;
    }
    $media = preg_replace('~[^A-Za-z0-9_/-]~', '', trim((string)CFG['media_dir'], '/')) ?: 'media';
    return str_replace(['{{GATE}}', '{{MEDIA}}'], [gate_htaccess_block(setting('installed') !== '1'), $media], $tpl);
}

/** Escribe /.htaccess desde la plantilla. */
function gate_write_htaccess(): bool
{
    $content = gate_expected_htaccess();
    if ($content === null) {
        return false;
    }
    $file = PUBLIC_ROOT . '/.htaccess';
    $tmp = $file . '.' . random_token(4) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** ¿Coincide el /.htaccess actual con el esperado? */
function gate_htaccess_ok(): bool
{
    $expected = gate_expected_htaccess();
    $file = PUBLIC_ROOT . '/.htaccess';
    return $expected !== null && is_file($file) && hash_equals(md5($expected), (string)md5_file($file));
}

/** Llamado en cada petición: regenera /.htaccess si falta o está desactualizado. */
function gate_sync_htaccess(): void
{
    if (!gate_htaccess_ok()) {
        if (gate_write_htaccess()) {
            log_event('.htaccess regenerado desde la plantilla');
        } else {
            error_log('[expo] No se pudo escribir /.htaccess');
        }
    }
}

function gate_rotate_token(): bool
{
    set_setting('device_token', random_token(24));
    // El enlace de autorización cambia también: el antiguo deja de servir.
    set_setting('gate_key', random_token(16));
    return gate_write_htaccess();
}

/**
 * Clave secreta del enlace de autorización (/cookie.html?k=…). No está en el repositorio:
 * se genera en el servidor la primera vez y cambia al revocar los dispositivos.
 */
function gate_key(): string
{
    $k = (string)setting('gate_key', '');
    if (!preg_match('/^[a-f0-9]{32,}$/D', $k)) {
        $k = random_token(16);
        set_setting('gate_key', $k);
    }
    return $k;
}

/** Ruta del enlace de autorización (sólo se muestra a los administradores). */
function gate_link_path(): string
{
    return '/cookie.html?k=' . gate_key();
}

/** ¿Trae la petición la clave correcta del enlace de autorización? */
function gate_key_valid(): bool
{
    $k = $_GET['k'] ?? '';
    return is_string($k) && $k !== '' && hash_equals(gate_key(), $k);
}
