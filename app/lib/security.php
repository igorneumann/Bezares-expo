<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Autodiagnóstico de seguridad: el servidor se hace peticiones HTTP a sí mismo
 * para comprobar que las carpetas internas no se sirven por web y que la puerta
 * de cookie también protege los ficheros estáticos (nginx).
 *
 * Las pruebas de "carpeta protegida" se hacen CON la cookie de dispositivo, para
 * que la redirección de la puerta no oculte una exposición real.
 */

function security_http(string $url, bool $withCookie): array
{
    $ch = curl_init($url);
    $headers = ['Cache-Control: no-cache', 'User-Agent: expo-security-check'];
    if ($withCookie) {
        $headers[] = 'Cookie: ' . GATE_COOKIE . '=' . setting('device_token');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RANGE          => '0-65535',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string)(curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'location' => $location, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

function security_rel_url(string $absPath): ?string
{
    $root = realpath(PUBLIC_ROOT);
    $abs = realpath($absPath);
    if (!$root || !$abs || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return '/' . implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', substr($abs, strlen($root) + 1)))));
}

/**
 * Devuelve ['base'=>..., 'summary'=>ok|warn|fail, 'checks'=>[[label, url, status, result, detail], ...]]
 */
function security_check(): array
{
    if (!function_exists('curl_init')) {
        return ['base' => '', 'summary' => 'fail', 'checks' => [[
            'label' => 'cURL', 'url' => '', 'status' => 0, 'result' => 'fail', 'detail' => 'La extensión cURL de PHP no está disponible.',
        ]]];
    }
    $base = base_url();

    // Fichero testigo con contenido aleatorio dentro de app/ (y en la carpeta de datos si está en el webroot).
    $canary = 'expo-canary-' . random_token(8);
    $canaryFiles = [APP_DIR . '/security-probe.txt'];
    if (security_rel_url(DATA_DIR) !== null) {
        $canaryFiles[] = DATA_DIR . '/security-probe.txt';
    }
    foreach ($canaryFiles as $f) {
        @file_put_contents($f, $canary);
    }

    // [etiqueta, ruta, con cookie, tipo de prueba]
    $tests = [
        ['Fichero testigo en app/', security_rel_url(APP_DIR . '/security-probe.txt'), true, 'deny'],
        ['Código PHP interno (app/bootstrap.php)', '/app/bootstrap.php', true, 'deny'],
        ['Librerías (app/lib/db.php)', '/app/lib/db.php', true, 'deny'],
        ['Plantilla .htaccess (app/htaccess.template)', '/app/htaccess.template', true, 'deny'],
        ['Configuración de ejemplo (app/config.sample.php)', '/app/config.sample.php', true, 'deny'],
        ['.htaccess raíz', '/.htaccess', true, 'deny'],
        ['Carpeta .git', '/.git/config', true, 'deny'],
        ['.gitignore', '/.gitignore', true, 'deny'],
        ['README.md', '/README.md', true, 'deny'],
        ['Arranque interno (_boot.php)', '/_boot.php', true, 'deny'],
        ['Reglas de /media (media/.htaccess)', '/media/.htaccess', true, 'deny'],
    ];
    if (count($canaryFiles) > 1) {
        $dataRel = security_rel_url(DATA_DIR);
        $tests[] = ['Fichero testigo en la carpeta de datos', $dataRel . '/security-probe.txt', true, 'deny'];
        $tests[] = ['Base de datos SQLite', $dataRel . '/expo.sqlite', true, 'deny'];
        $tests[] = ['Registro de la aplicación', $dataRel . '/logs/app.log', true, 'deny'];
    }
    $tests[] = ['Puerta: página principal sin cookie', '/', false, 'gate'];
    $tests[] = ['Puerta: fichero estático sin cookie (nginx)', parse_url(asset('assets/app.css'), PHP_URL_PATH), false, 'gate'];
    $tests[] = ['Puerta: panel sin cookie', '/admin/', false, 'gate'];
    $tests[] = ['/.well-known accesible sin cookie (SSL)', '/.well-known/expo-check-' . random_token(4), false, 'open'];
    $tests[] = ['/cookie.html accesible sin cookie', '/cookie.html', false, 'open'];

    $checks = [];
    foreach ($tests as [$label, $path, $cookie, $type]) {
        if (!$path) {
            continue;
        }
        $r = security_http($base . $path, $cookie);
        $s = $r['status'];
        $toRedirect = $s >= 300 && $s < 400 && str_starts_with($r['location'], rtrim(CFG['redirect_url'], '/'));
        if ($r['error'] !== '' && $s === 0) {
            $res = 'warn';
            $detail = 'Sin respuesta: ' . $r['error'];
        } elseif ($type === 'deny') {
            if (str_contains($r['body'], $canary) || (($s === 200 || $s === 206) && strlen($r['body']) > 0)) {
                $res = 'fail';
                $detail = 'EXPUESTO: el servidor entrega el contenido.';
            } elseif ($s === 403 || $s === 404) {
                $res = 'ok';
                $detail = 'Bloqueado (' . $s . ').';
            } elseif ($s >= 500) {
                $res = 'warn';
                $detail = 'Error ' . $s . ': no se muestra, pero el .htaccess no parece aplicarse aquí.';
            } else {
                $res = 'warn';
                $detail = 'Respuesta inesperada (' . $s . ').';
            }
        } elseif ($type === 'gate') {
            if ($toRedirect) {
                $res = 'ok';
                $detail = 'Redirige a ' . CFG['redirect_url'] . '.';
            } elseif ($s === 200 || $s === 206) {
                $res = 'fail';
                $detail = 'Se sirve sin cookie: la puerta no se aplica'
                    . ($path === '/' || $path === '/admin/' ? '.' : ' (nginx sirve los estáticos directamente; ver README).');
            } else {
                $res = 'warn';
                $detail = 'Respuesta inesperada (' . $s . ($r['location'] ? ' → ' . $r['location'] : '') . ').';
            }
        } else { // open
            $res = $toRedirect ? 'fail' : 'ok';
            $detail = $toRedirect ? 'Redirige a bezares.com: no debería.' : 'Accesible (' . $s . ').';
        }
        $checks[] = ['label' => $label, 'url' => $path, 'status' => $s, 'result' => $res, 'detail' => $detail];
    }

    foreach ($canaryFiles as $f) {
        @unlink($f);
    }

    $results = array_column($checks, 'result');
    $summary = in_array('fail', $results, true) ? 'fail' : (in_array('warn', $results, true) ? 'warn' : 'ok');
    set_setting('security_last', json_encode(['at' => time(), 'summary' => $summary]));
    log_event('verificación de seguridad: ' . $summary);
    return ['base' => $base, 'summary' => $summary, 'checks' => $checks,
            'data_dir_public' => count($canaryFiles) > 1, 'htaccess_ok' => gate_htaccess_ok()];
}
