<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

const MEDIA_EXT = [
    'mp4' => 'video', 'm4v' => 'video', 'webm' => 'video',
    'glb' => 'model', 'gltf' => 'model', 'obj' => 'model', 'stp' => 'model', 'step' => 'model', 'stl' => 'model',
    'jpg' => 'photo', 'jpeg' => 'photo', 'png' => 'photo', 'webp' => 'photo', 'gif' => 'photo', 'avif' => 'photo', 'svg' => 'photo',
];
const MEDIA_FOLDERS = ['video' => 'videos', 'model' => 'models', 'photo' => 'photos'];
const PHOTO_WIDTHS = [480, 1024, 1920];
// offset_x / offset_y: desplazamiento del modelo en pantalla (% del recuadro; + = derecha / arriba).
// Se aplica al visor completo, así el producto sigue girando sobre su propio eje.
const MODEL_DEFAULTS = ['down' => '-y', 'rx' => 0, 'ry' => 0, 'rz' => 0, 'offset_x' => 0, 'offset_y' => 0,
                        'size' => 100, 'elevation' => 75, 'exposure' => 1.0, 'shadow' => 0.6, 'speed' => 30];

/** Estilo CSS del desplazamiento en pantalla del visor 3D. */
function model_offset_style(array $s): string
{
    $x = max(-50, min(50, (float)$s['offset_x']));
    $y = max(-50, min(50, (float)$s['offset_y']));
    return ($x == 0.0 && $y == 0.0) ? '' : 'transform:translate(' . $x . '%,' . (-$y) . '%)';
}

/*
 * Eje del modelo que queda hacia abajo (el producto gira alrededor de ese eje).
 * Valor => giro base [roll (Z), pitch (X)] en grados que lleva ese eje a apuntar hacia abajo.
 * glTF usa Y arriba (-Y abajo); muchos CAD (STEP) usan Z arriba (-Z abajo).
 */
const MODEL_DOWN_AXES = [
    '-y' => [0, 0], '+y' => [0, 180],
    '-z' => [0, -90], '+z' => [0, 90],
    '-x' => [90, 0], '+x' => [-90, 0],
];

/** Atributo "orientation" de model-viewer (roll pitch yaw) combinando el eje abajo y los giros manuales. */
function model_orientation(array $s): string
{
    [$roll, $pitch] = MODEL_DOWN_AXES[$s['down'] ?? '-y'] ?? [0, 0];
    return ((float)$s['rz'] + $roll) . 'deg ' . ((float)$s['rx'] + $pitch) . 'deg ' . (float)$s['ry'] . 'deg';
}

function media_root(): string
{
    return PUBLIC_ROOT . '/' . trim(CFG['media_dir'], '/');
}

function media_rel_prefix(): string
{
    return trim(CFG['media_dir'], '/') . '/';
}

/** Ruta absoluta segura de un path relativo almacenado (impide salir de /media). */
function media_abs(string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || !str_starts_with($rel, media_rel_prefix())) {
        return null;
    }
    return PUBLIC_ROOT . '/' . $rel;
}

function media_ext(string $name): string
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function media_url(?array $m): string
{
    if (!$m) {
        return '';
    }
    $parts = array_map('rawurlencode', explode('/', $m['path']));
    return '/' . implode('/', $parts) . '?v=' . base_convert((string)$m['mtime'], 10, 36);
}

function media_get(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $st = db()->prepare('SELECT * FROM media WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = $st->fetch() ?: null;
    }
    return $cache[$id];
}

function media_get_fresh(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function media_settings(array $m): array
{
    $s = json_decode($m['settings'] ?: '{}', true);
    $s = is_array($s) ? $s : [];
    return $m['kind'] === 'model' ? array_replace(MODEL_DEFAULTS, array_intersect_key($s, MODEL_DEFAULTS)) : $s;
}

function sanitize_filename(string $name): string
{
    $ext = media_ext($name);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
    // Sin puntos en el nombre base: evita dobles extensiones tipo "x.php.jpg".
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?? 'file';
    $base = trim($base, '-_') ?: 'file';
    return substr($base, 0, 100) . ($ext !== '' ? '.' . $ext : '');
}

/** Comprueba el contenido real del fichero según su extensión. */
function media_validate_content(string $file, string $ext): ?string
{
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return 'No se puede leer el fichero';
    }
    $head = (string)fread($fh, 512);
    fclose($fh);
    switch ($ext) {
        case 'mp4': case 'm4v':
            return substr($head, 4, 4) === 'ftyp' ? null : 'No es un MP4 válido';
        case 'webm':
            return str_starts_with($head, "\x1A\x45\xDF\xA3") ? null : 'No es un WebM válido';
        case 'glb':
            return str_starts_with($head, 'glTF') ? null : 'No es un GLB válido';
        case 'gltf':
            return str_starts_with(ltrim($head, "\xEF\xBB\xBF \r\n\t"), '{') ? null : 'No es un glTF válido';
        case 'stp': case 'step':
            return str_contains($head, 'ISO-10303-21') ? null : 'No es un fichero STEP válido';
        case 'stl':
            // ASCII ("solid …") o binario (cabecera de 80 bytes + nº de triángulos coherente con el tamaño)
            if (stripos(ltrim($head), 'solid') === 0) {
                return null;
            }
            $size = (int)filesize($file);
            if ($size >= 84 && strlen($head) >= 84) {
                $tri = unpack('V', substr($head, 80, 4))[1];
                return (84 + $tri * 50 === $size) ? null : 'No es un STL válido';
            }
            return 'No es un STL válido';
        case 'obj':
            return (preg_match('/[\x00-\x08\x0E-\x1F]/', $head) || stripos($head, '<?php') !== false) ? 'No es un OBJ de texto válido' : null;
        case 'svg':
            return svg_is_safe((string)file_get_contents($file, false, null, 0, 5_000_000)) ? null : 'El SVG contiene contenido activo no permitido';
        case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': case 'avif':
            $info = @getimagesize($file);
            return ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, defined('IMAGETYPE_AVIF') ? IMAGETYPE_AVIF : -1], true))
                ? null : 'No es una imagen válida';
    }
    return 'Tipo de fichero no permitido';
}

function svg_is_safe(string $svg): bool
{
    $s = strtolower($svg);
    foreach (['<script', 'javascript:', '<foreignobject', '<iframe', '<embed', '<object', '<!entity', 'data:text/html'] as $bad) {
        if (str_contains($s, $bad)) {
            return false;
        }
    }
    if (preg_match('/\son[a-z]+\s*=/i', $svg)) {
        return false;
    }
    // Referencias externas (http/https) en href
    if (preg_match('/(?:xlink:)?href\s*=\s*["\']\s*(?:https?:|\/\/)/i', $svg)) {
        return false;
    }
    return true;
}

/** Registra (o actualiza) un fichero ya presente dentro de /media. Devuelve id o null. */
function media_register(string $rel, ?string $displayName = null): ?int
{
    $abs = media_abs($rel);
    if (!$abs || !is_file($abs)) {
        return null;
    }
    $ext = media_ext($rel);
    $kind = MEDIA_EXT[$ext] ?? null;
    if (!$kind) {
        return null;
    }
    if (media_validate_content($abs, $ext) !== null) {
        return null;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM media WHERE path = ? OR source_path = ?');
    $st->execute([$rel, $rel]);
    $existing = $st->fetch();
    $size = (int)filesize($abs);
    $mtime = (int)filemtime($abs);

    if ($existing) {
        // Si es el original (STEP/OBJ) de un modelo ya convertido, no tocar.
        if ($existing['source_path'] === $rel && $existing['path'] !== $rel) {
            if ((int)$existing['mtime'] < $mtime && $existing['status'] === 'ready') {
                // El original cambió por FTP: requiere reconversión.
                $pdo->prepare("UPDATE media SET status = 'needs_conversion' WHERE id = ?")->execute([$existing['id']]);
            }
            return (int)$existing['id'];
        }
        if ((int)$existing['size'] === $size && (int)$existing['mtime'] === $mtime) {
            return (int)$existing['id'];
        }
        [$w, $h] = media_dimensions($abs, $kind, $ext);
        $pdo->prepare('UPDATE media SET size = ?, mtime = ?, width = ?, height = ? WHERE id = ?')
            ->execute([$size, $mtime, $w, $h, $existing['id']]);
        if ($kind === 'photo') {
            media_make_variants((int)$existing['id'], true);
        }
        bump_content_version();
        return (int)$existing['id'];
    }

    [$w, $h] = media_dimensions($abs, $kind, $ext);
    $status = ($kind === 'model' && $ext !== 'glb') ? 'needs_conversion' : 'ready';
    $name = $displayName ?: pathinfo($rel, PATHINFO_FILENAME);
    $settings = $kind === 'model' ? json_encode(MODEL_DEFAULTS) : '{}';
    $pdo->prepare('INSERT INTO media(kind, path, name, size, width, height, mtime, status, settings, created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kind, $rel, mb_substr($name, 0, 150), $size, $w, $h, $mtime, $status, $settings, time()]);
    $id = (int)$pdo->lastInsertId();
    if ($kind === 'photo') {
        media_make_variants($id);
    }
    return $id;
}

function media_dimensions(string $abs, string $kind, string $ext): array
{
    if ($kind !== 'photo') {
        return [null, null];
    }
    if ($ext === 'svg') {
        $s = (string)file_get_contents($abs, false, null, 0, 20000);
        if (preg_match('/viewBox\s*=\s*["\']\s*[\d.\-]+[\s,]+[\d.\-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $s, $m)) {
            return [(int)round((float)$m[1]), (int)round((float)$m[2])];
        }
        return [null, null];
    }
    $i = @getimagesize($abs);
    return $i ? [(int)$i[0], (int)$i[1]] : [null, null];
}

function media_cache_dir(): string
{
    $d = media_root() . '/_cache';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

/** Genera versiones redimensionadas WebP (o JPEG si el servidor no tiene WebP). */
function media_make_variants(int $id, bool $force = false): void
{
    $m = media_get_fresh($id);
    if (!$m || $m['kind'] !== 'photo' || media_ext($m['path']) === 'svg' || !function_exists('imagecreatefromstring')) {
        return;
    }
    $abs = media_abs($m['path']);
    if (!$abs || !is_file($abs)) {
        return;
    }
    $webp = function_exists('imagewebp');
    $outExt = $webp ? 'webp' : 'jpg';
    $needed = [];
    foreach (PHOTO_WIDTHS as $w) {
        if ($m['width'] && $w >= (int)$m['width']) {
            continue;
        }
        $out = media_cache_dir() . "/p{$id}-{$w}.{$outExt}";
        if ($force || !is_file($out)) {
            $needed[$w] = $out;
        }
    }
    if (!$needed) {
        return;
    }
    @ini_set('memory_limit', '768M');
    $src = @imagecreatefromstring((string)file_get_contents($abs));
    if (!$src) {
        return;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    foreach ($needed as $w => $out) {
        $h = (int)round($sh * $w / $sw);
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        if ($webp) {
            imagewebp($dst, $out, 80);
        } else {
            imagejpeg($dst, $out, 82);
        }
        imagedestroy($dst);
    }
    imagedestroy($src);
}

/** srcset de una foto. Devuelve [src, srcset]. */
function media_photo_srcset(?array $m): array
{
    if (!$m) {
        return ['', ''];
    }
    $orig = media_url($m);
    if (media_ext($m['path']) === 'svg') {
        return [$orig, ''];
    }
    $v = base_convert((string)$m['mtime'], 10, 36);
    $set = [];
    $smallest = $orig;
    foreach (PHOTO_WIDTHS as $w) {
        foreach (['webp', 'jpg'] as $ext) {
            $f = media_cache_dir() . "/p{$m['id']}-{$w}.{$ext}";
            if (is_file($f)) {
                $u = '/' . media_rel_prefix() . "_cache/p{$m['id']}-{$w}.{$ext}?v={$v}";
                $set[] = $u . ' ' . $w . 'w';
                if ($smallest === $orig) {
                    $smallest = $u;
                }
                break;
            }
        }
    }
    if ($m['width']) {
        $set[] = $orig . ' ' . (int)$m['width'] . 'w';
    }
    return [$smallest === $orig ? $orig : $smallest, implode(', ', $set)];
}

function media_scan(): array
{
    $root = media_root();
    foreach (MEDIA_FOLDERS as $f) {
        if (!is_dir("$root/$f")) {
            @mkdir("$root/$f", 0755, true);
        }
    }
    $added = 0;
    $updated = 0;
    $skipped = [];
    $before = (int)db()->query('SELECT COUNT(*) FROM media')->fetchColumn();
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                $n = $f->getFilename();
                return !str_starts_with($n, '.') && !($f->isDir() && $n === '_cache');
            }
        )
    );
    $files = [];
    $byPath = [];
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) {
            continue;
        }
        $files[] = $f;
        $byPath[str_replace('\\', '/', $f->getPathname())] = true;
    }
    // Texturas: sólo las imágenes que un .gltf o un .mtl referencian de verdad
    // (no todas las imágenes de su carpeta, que en WordPress lo mezcla todo).
    $textures = media_referenced_textures($files);
    $wpVariants = [];
    foreach ($files as $f) {
        $ext = media_ext($f->getFilename());
        if (!isset(MEDIA_EXT[$ext])) {
            continue;
        }
        $pathN = str_replace('\\', '/', $f->getPathname());
        if (MEDIA_EXT[$ext] === 'photo') {
            if (isset($textures[$pathN])) {
                continue;
            }
            // Copias redimensionadas de WordPress (foto-1024x768.jpg) cuando existe el original: no se importan.
            if (preg_match('/^(.+)-\d{2,5}x\d{2,5}\.[a-z0-9]+$/i', $f->getFilename(), $wm)) {
                foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $oe) {
                    if (isset($byPath[str_replace('\\', '/', $f->getPath()) . '/' . $wm[1] . '.' . $oe])) {
                        $wpVariants[] = media_rel_prefix() . ltrim(substr($pathN, strlen(str_replace('\\', '/', $root))), '/');
                        continue 2;
                    }
                }
            }
        }
        $rel = media_rel_prefix() . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        $st = db()->prepare('SELECT mtime, size FROM media WHERE path = ?');
        $st->execute([$rel]);
        $prev = $st->fetch();
        $id = media_register($rel);
        if ($id === null) {
            $skipped[] = $rel;
        } elseif ($prev && ((int)$prev['mtime'] !== $f->getMTime() || (int)$prev['size'] !== $f->getSize())) {
            $updated++;
        }
    }
    $added = (int)db()->query('SELECT COUNT(*) FROM media')->fetchColumn() - $before;

    // Mover cada elemento (también los ya importados) a su carpeta correcta.
    $org = media_organize();

    // Marcar medios cuyo fichero ya no existe (y recuperar los que vuelven a estar).
    $missing = 0;
    foreach (db()->query('SELECT id, path, kind, status FROM media')->fetchAll() as $m) {
        $abs = media_abs($m['path']);
        if (!$abs || !is_file($abs)) {
            db()->prepare("UPDATE media SET status = 'missing' WHERE id = ?")->execute([$m['id']]);
            $missing++;
        } elseif ($m['status'] === 'missing') {
            $st = ($m['kind'] === 'model' && media_ext($m['path']) !== 'glb') ? 'needs_conversion' : 'ready';
            db()->prepare('UPDATE media SET status = ? WHERE id = ?')->execute([$st, $m['id']]);
        }
    }
    if ($added || $updated || $org['moved'] || $org['bundled']) {
        bump_content_version();
    }
    // Las copias de WordPress no se cuentan como "sobrantes" desconocidos: van en su propia lista.
    $org['leftover'] = array_values(array_diff($org['leftover'], $wpVariants));
    return ['added' => $added, 'updated' => $updated, 'missing' => $missing, 'skipped' => array_slice($skipped, 0, 50),
            'wp_variants' => $wpVariants] + $org;
}

/** Rutas absolutas (normalizadas con "/") de las imágenes referenciadas por ficheros .gltf o .mtl. */
function media_referenced_textures(array $files): array
{
    $out = [];
    $add = static function (string $dir, string $ref) use (&$out): void {
        $abs = media_companion_abs($dir, $ref);
        if ($abs) {
            $out[str_replace('\\', '/', $abs)] = true;
        }
    };
    foreach ($files as $f) {
        /** @var SplFileInfo $f */
        $ext = media_ext($f->getFilename());
        if ($ext === 'gltf' && $f->getSize() < 50_000_000) {
            $json = json_decode((string)@file_get_contents($f->getPathname()), true);
            foreach (is_array($json) ? ($json['images'] ?? []) : [] as $img) {
                if (is_array($img) && is_string($img['uri'] ?? null) && !str_starts_with($img['uri'], 'data:')) {
                    $add($f->getPath(), $img['uri']);
                }
            }
        } elseif ($ext === 'mtl' && $f->getSize() < 5_000_000) {
            preg_match_all('/^\s*(?:map_\w+|bump|disp|decal|refl|norm)\s+(?:.*\s)?(\S+)\s*$/mi', (string)@file_get_contents($f->getPathname()), $m);
            foreach ($m[1] as $ref) {
                $add($f->getPath(), $ref);
            }
        }
    }
    return $out;
}

/**
 * Borra ficheros sobrantes de las carpetas de importación (nunca de videos/photos/models/_cache
 * ni ficheros registrados en la galería). Devuelve [borrados, carpetas vacías eliminadas].
 */
function media_delete_leftovers(array $paths): array
{
    $root = realpath(media_root());
    $registered = [];
    foreach (db()->query('SELECT path, source_path FROM media') as $r) {
        foreach ([$r['path'], $r['source_path']] as $p) {
            if ($p && ($a = media_abs($p)) && ($rp = realpath($a))) {
                $registered[$rp] = true;
            }
        }
    }
    $deleted = 0;
    foreach (array_slice($paths, 0, 5000) as $rel) {
        if (!is_string($rel) || !($abs = media_abs($rel)) || !($real = realpath($abs)) || !is_file($real)) {
            continue;
        }
        $inside = substr(str_replace('\\', '/', $real), strlen(str_replace('\\', '/', (string)$root)) + 1);
        $top = explode('/', $inside)[0];
        if (!$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || isset($registered[$real])
            || in_array($top, ['videos', 'photos', 'models', '_cache'], true) || basename($real) === '.htaccess') {
            continue;
        }
        if (@unlink($real)) {
            $deleted++;
        }
    }
    [$removedDirs] = media_cleanup_dirs();
    return [$deleted, $removedDirs];
}

// ---------------------------------------------------------------------------
// Organización de carpetas
//   media/videos/            vídeos
//   media/photos/            fotos (se admiten subcarpetas)
//   media/models/            modelos GLB (y models/_converted/ para los convertidos)
//   media/models/_sources/   originales: STEP sueltos; glTF/OBJ en su propia carpeta con
//                            sus .bin, .mtl y texturas (referencias reescritas)
// Tras organizar se borran las carpetas de importación que hayan quedado vacías.
// ---------------------------------------------------------------------------

const MEDIA_SOURCES = 'models/_sources';

function media_under(string $rel, string $folder): bool
{
    return str_starts_with($rel, media_rel_prefix() . trim($folder, '/') . '/');
}

/** Mueve (o copia) un fichero de /media a una carpeta de /media con nombre libre. Devuelve la ruta relativa nueva. */
function media_move_file(string $fromAbs, string $toFolderRel, ?string $name = null, bool $copy = false): ?string
{
    $dir = PUBLIC_ROOT . '/' . rtrim($toFolderRel, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    $rel = media_unique_path($toFolderRel, $name ?? basename($fromAbs));
    $ok = $copy ? @copy($fromAbs, PUBLIC_ROOT . '/' . $rel) : @rename($fromAbs, PUBLIC_ROOT . '/' . $rel);
    if (!$ok) {
        return null;
    }
    if ($copy) {
        @touch(PUBLIC_ROOT . '/' . $rel, (int)filemtime($fromAbs));
    }
    @chmod(PUBLIC_ROOT . '/' . $rel, 0644);
    return $rel;
}

/** Ruta absoluta de un recurso referenciado por un modelo, sólo si está dentro de /media. */
function media_companion_abs(string $baseDirAbs, string $ref): ?string
{
    $ref = trim(rawurldecode(str_replace('\\', '/', $ref)));
    if ($ref === '' || preg_match('~^(data:|[a-z][a-z0-9+.-]*://|/)~i', $ref)) {
        return null;
    }
    $abs = realpath($baseDirAbs . '/' . $ref);
    $root = realpath(media_root());
    return ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs)) ? $abs : null;
}

/**
 * Copia un modelo glTF/OBJ y sus ficheros asociados a una carpeta propia en _sources,
 * reescribiendo las referencias. Devuelve la nueva ruta del modelo o null.
 * $copied recibe los ficheros originales copiados (para borrarlos al final).
 */
function media_bundle_model(string $srcRel, array &$copied, array &$errors): ?string
{
    $abs = media_abs($srcRel);
    if (!$abs || !is_file($abs)) {
        return null;
    }
    $ext = media_ext($srcRel);
    $baseDir = dirname($abs);
    $bundleBase = media_rel_prefix() . MEDIA_SOURCES . '/' . slugify(pathinfo($srcRel, PATHINFO_FILENAME));
    $bundle = $bundleBase;
    for ($i = 1; is_dir(PUBLIC_ROOT . '/' . $bundle); $i++) {
        $bundle = $bundleBase . '-' . $i;
    }
    if (!@mkdir(PUBLIC_ROOT . '/' . $bundle, 0755, true)) {
        $errors[] = 'No se pudo crear ' . $bundle;
        return null;
    }
    $names = []; // origen absoluto => nombre nuevo dentro del paquete
    $place = static function (string $compAbs) use (&$names, &$copied, $bundle): ?string {
        if (isset($names[$compAbs])) {
            return $names[$compAbs];
        }
        $rel = media_move_file($compAbs, $bundle, sanitize_filename(basename($compAbs)), true);
        if ($rel === null) {
            return null;
        }
        $copied[$compAbs] = true;
        return $names[$compAbs] = basename($rel);
    };

    if ($ext === 'gltf') {
        $json = json_decode((string)file_get_contents($abs), true);
        if (!is_array($json)) {
            $errors[] = $srcRel . ': glTF no válido';
            return null;
        }
        foreach (['buffers', 'images'] as $k) {
            foreach ($json[$k] ?? [] as $i => $item) {
                $uri = is_array($item) ? (string)($item['uri'] ?? '') : '';
                if ($uri === '' || str_starts_with($uri, 'data:')) {
                    continue;
                }
                $c = media_companion_abs($baseDir, $uri);
                $new = $c ? $place($c) : null;
                if ($new === null) {
                    $errors[] = $srcRel . ': falta ' . $uri;
                    continue;
                }
                $json[$k][$i]['uri'] = rawurlencode($new);
            }
        }
        $out = PUBLIC_ROOT . '/' . media_unique_path($bundle, sanitize_filename(basename($abs)));
        file_put_contents($out, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } else { // obj
        $obj = (string)file_get_contents($abs);
        $mtlMap = []; // referencia original en el OBJ => nombre nuevo del .mtl
        preg_match_all('/^\s*mtllib\s+(.+?)\s*$/mi', $obj, $libs);
        foreach (array_unique($libs[1]) as $ref) {
            $c = media_companion_abs($baseDir, $ref);
            if (!$c) {
                $errors[] = $srcRel . ': falta ' . $ref;
                continue;
            }
            // Texturas del .mtl: se copian al paquete y se reescribe la ruta.
            $mtlDir = dirname($c);
            $mtl = preg_replace_callback('/^(\s*(?:map_\w+|bump|disp|decal|refl|norm)\s+(?:.*\s)?)(\S+)\s*$/mi',
                static function ($t) use ($mtlDir, $place, &$errors, $srcRel) {
                    $tc = media_companion_abs($mtlDir, $t[2]);
                    $new = $tc ? $place($tc) : null;
                    if ($new === null) {
                        $errors[] = $srcRel . ': falta la textura ' . $t[2];
                        return $t[0];
                    }
                    return $t[1] . $new;
                }, (string)file_get_contents($c)) ?? '';
            $mtlRel = media_unique_path($bundle, sanitize_filename(basename($c)));
            file_put_contents(PUBLIC_ROOT . '/' . $mtlRel, $mtl);
            $copied[$c] = true;
            $mtlMap[$ref] = basename($mtlRel);
        }
        $obj = preg_replace_callback('/^(\s*mtllib\s+)(.+?)\s*$/mi',
            static fn($m) => isset($mtlMap[$m[2]]) ? $m[1] . $mtlMap[$m[2]] : $m[0], $obj) ?? $obj;
        $out = PUBLIC_ROOT . '/' . media_unique_path($bundle, sanitize_filename(basename($abs)));
        file_put_contents($out, $obj);
    }
    @touch($out, (int)filemtime($abs));
    @chmod($out, 0644);
    $copied[$abs] = true;
    return media_rel_prefix() . ltrim(substr(str_replace('\\', '/', $out), strlen(str_replace('\\', '/', media_root()))), '/');
}

function media_organize(): array
{
    $pdo = db();
    $moved = 0;
    $bundled = 0;
    $errors = [];
    $copied = [];
    $upd = static function (int $id, string $col, string $rel) use ($pdo): void {
        $pdo->prepare("UPDATE media SET {$col} = ? WHERE id = ?")->execute([$rel, $id]);
    };

    foreach ($pdo->query('SELECT * FROM media ORDER BY id')->fetchAll() as $m) {
        $id = (int)$m['id'];
        // 1) Fichero principal
        $abs = media_abs($m['path']);
        if ($abs && is_file($abs)) {
            $ext = media_ext($m['path']);
            if ($m['kind'] === 'video' && !media_under($m['path'], 'videos')) {
                $target = 'videos';
            } elseif ($m['kind'] === 'photo' && !media_under($m['path'], 'photos')) {
                $target = 'photos';
            } elseif ($m['kind'] === 'model' && $ext === 'glb' && (!media_under($m['path'], 'models') || media_under($m['path'], MEDIA_SOURCES))) {
                $target = 'models';
            } elseif ($m['kind'] === 'model' && $ext !== 'glb' && !media_under($m['path'], MEDIA_SOURCES)) {
                $target = MEDIA_SOURCES; // original aún sin convertir
            } else {
                $target = null;
            }
            if ($target === MEDIA_SOURCES && in_array($ext, ['gltf', 'obj'], true)) {
                $new = media_bundle_model($m['path'], $copied, $errors);
                if ($new) {
                    $upd($id, 'path', $new);
                    $bundled++;
                }
            } elseif ($target !== null) {
                $new = media_move_file($abs, media_rel_prefix() . $target);
                if ($new) {
                    $upd($id, 'path', $new);
                    $moved++;
                } else {
                    $errors[] = 'No se pudo mover ' . $m['path'];
                }
            }
        }
        // 2) Original de un modelo convertido (STEP/OBJ/glTF)
        if ($m['source_path'] && !media_under($m['source_path'], MEDIA_SOURCES)) {
            $sabs = media_abs($m['source_path']);
            if ($sabs && is_file($sabs)) {
                if (in_array(media_ext($m['source_path']), ['gltf', 'obj'], true)) {
                    $new = media_bundle_model($m['source_path'], $copied, $errors);
                    $bundled += $new ? 1 : 0;
                } else {
                    $new = media_move_file($sabs, media_rel_prefix() . MEDIA_SOURCES);
                    $moved += $new ? 1 : 0;
                }
                if ($new) {
                    $upd($id, 'source_path', $new);
                } else {
                    $errors[] = 'No se pudo mover ' . $m['source_path'];
                }
            }
        }
    }

    // Borrar los originales copiados a los paquetes (salvo los que sigan registrados en la galería).
    $inDb = [];
    foreach ($pdo->query('SELECT path, source_path FROM media') as $r) {
        foreach ([$r['path'], $r['source_path']] as $p) {
            if ($p && ($a = media_abs($p))) {
                $inDb[realpath($a) ?: $a] = true;
            }
        }
    }
    foreach (array_keys($copied) as $c) {
        if (!isset($inDb[realpath($c) ?: $c])) {
            @unlink($c);
        }
    }

    [$removedDirs, $leftover] = media_cleanup_dirs();
    if ($errors) {
        log_event('organizar medios: ' . implode(' | ', array_slice($errors, 0, 20)));
    }
    return ['moved' => $moved, 'bundled' => $bundled, 'removed_dirs' => $removedDirs,
            'leftover' => array_slice($leftover, 0, 50), 'organize_errors' => array_slice(array_values(array_unique($errors)), 0, 30)];
}

/** Borra carpetas de importación vacías y lista los ficheros que quedan fuera de las carpetas correctas. */
function media_cleanup_dirs(): array
{
    $root = media_root();
    $keep = array_map(static fn($f) => str_replace('\\', '/', $root . '/' . $f),
        ['videos', 'photos', 'models', 'models/_converted', MEDIA_SOURCES, '_cache']);
    $junk = ['.ds_store', 'thumbs.db', 'desktop.ini', '._.ds_store'];
    $removed = 0;
    $leftover = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $path = str_replace('\\', '/', $f->getPathname());
        $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        $top = explode('/', $rel)[0];
        $canonical = in_array($top, ['videos', 'photos', 'models', '_cache'], true);
        if ($f->isFile()) {
            if (!$canonical && $f->getFilename() !== '.htaccess') {
                if (in_array(strtolower($f->getFilename()), $junk, true)) {
                    @unlink($path);
                } else {
                    $leftover[] = media_rel_prefix() . $rel;
                }
            }
            continue;
        }
        if (in_array($path, $keep, true)) {
            continue;
        }
        // Carpeta vacía (fuera o dentro de las carpetas correctas): se elimina.
        if (count(scandir($path) ?: []) <= 2 && @rmdir($path)) {
            $removed++;
        }
    }
    return [$removed, $leftover];
}

function media_delete(int $id, bool $deleteFiles): void
{
    $m = media_get($id);
    if (!$m) {
        return;
    }
    if ($deleteFiles) {
        foreach ([$m['path'], $m['source_path']] as $p) {
            if (!$p || !($abs = media_abs($p)) || !is_file($abs)) {
                continue;
            }
            // Original glTF/OBJ empaquetado en su carpeta de _sources: borrar la carpeta entera.
            $dirRel = dirname($p);
            if (in_array(media_ext($p), ['gltf', 'obj'], true) && media_under($p, MEDIA_SOURCES)
                && dirname($dirRel) === media_rel_prefix() . MEDIA_SOURCES) {
                foreach (glob(PUBLIC_ROOT . '/' . $dirRel . '/*') ?: [] as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                @rmdir(PUBLIC_ROOT . '/' . $dirRel);
                continue;
            }
            @unlink($abs);
        }
        foreach (glob(media_cache_dir() . "/p{$id}-*") ?: [] as $f) {
            @unlink($f);
        }
    }
    db()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    bump_content_version();
}

function media_public(array $m): array
{
    [$src, $srcset] = $m['kind'] === 'photo' ? media_photo_srcset($m) : ['', ''];
    return [
        'id'       => (int)$m['id'],
        'kind'     => $m['kind'],
        'name'     => $m['name'],
        'path'     => $m['path'],
        'source'   => $m['source_path'],
        'url'      => media_url($m),
        'sourceUrl'=> $m['source_path'] ? media_url(['path' => $m['source_path'], 'mtime' => $m['mtime']]) : '',
        'thumb'    => $src,
        'size'     => (int)$m['size'],
        'width'    => $m['width'] !== null ? (int)$m['width'] : null,
        'height'   => $m['height'] !== null ? (int)$m['height'] : null,
        'status'   => $m['status'],
        'ext'      => media_ext($m['source_path'] ?: $m['path']),
        'settings' => media_settings($m),
    ];
}

/** Siguiente nombre libre dentro de una carpeta. */
function media_unique_path(string $folderRel, string $filename): string
{
    $filename = sanitize_filename($filename);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = media_ext($filename);
    $i = 0;
    do {
        $name = $i === 0 ? $filename : "{$base}-{$i}.{$ext}";
        $rel = rtrim($folderRel, '/') . '/' . $name;
        $i++;
    } while (is_file(PUBLIC_ROOT . '/' . $rel));
    return $rel;
}
