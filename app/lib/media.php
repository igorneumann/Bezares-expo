<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

const MEDIA_EXT = [
    'mp4' => 'video', 'm4v' => 'video', 'webm' => 'video',
    'glb' => 'model', 'gltf' => 'model', 'obj' => 'model', 'stp' => 'model', 'step' => 'model',
    'jpg' => 'photo', 'jpeg' => 'photo', 'png' => 'photo', 'webp' => 'photo', 'gif' => 'photo', 'avif' => 'photo', 'svg' => 'photo',
];
const MEDIA_FOLDERS = ['video' => 'videos', 'model' => 'models', 'photo' => 'photos'];
const PHOTO_WIDTHS = [480, 1024, 1920];
const MODEL_DEFAULTS = ['rx' => 0, 'ry' => 0, 'rz' => 0, 'size' => 100, 'elevation' => 75, 'exposure' => 1.0, 'shadow' => 0.6, 'speed' => 30];

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
    $gltfDirs = [];
    $files = [];
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) {
            continue;
        }
        $files[] = $f;
        if (media_ext($f->getFilename()) === 'gltf') {
            $gltfDirs[$f->getPath()] = true;
        }
    }
    foreach ($files as $f) {
        $ext = media_ext($f->getFilename());
        if (!isset(MEDIA_EXT[$ext])) {
            continue;
        }
        // Texturas de modelos glTF: no son fotos de la galería.
        if (MEDIA_EXT[$ext] === 'photo' && isset($gltfDirs[$f->getPath()])) {
            continue;
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
    // Marcar medios cuyo fichero ya no existe.
    $missing = 0;
    foreach (db()->query('SELECT id, path FROM media')->fetchAll() as $m) {
        $abs = media_abs($m['path']);
        if (!$abs || !is_file($abs)) {
            db()->prepare("UPDATE media SET status = 'missing' WHERE id = ?")->execute([$m['id']]);
            $missing++;
        }
    }
    if ($added || $updated) {
        bump_content_version();
    }
    return ['added' => $added, 'updated' => $updated, 'missing' => $missing, 'skipped' => array_slice($skipped, 0, 50)];
}

function media_delete(int $id, bool $deleteFiles): void
{
    $m = media_get($id);
    if (!$m) {
        return;
    }
    if ($deleteFiles) {
        foreach ([$m['path'], $m['source_path']] as $p) {
            if ($p && ($abs = media_abs($p)) && is_file($abs)) {
                @unlink($abs);
            }
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
