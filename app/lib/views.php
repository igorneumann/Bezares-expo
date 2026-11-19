<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------

function tablet_by_number(int $n, bool $activeOnly = true): ?array
{
    $st = db()->prepare('SELECT * FROM tablets WHERE number = ?' . ($activeOnly ? ' AND active = 1' : ''));
    $st->execute([$n]);
    return $st->fetch() ?: null;
}

function tablet_items(int $tabletId): array
{
    $st = db()->prepare('SELECT ti.*, p.short_name, p.photo_media_id AS product_photo_id
                         FROM tablet_items ti JOIN products p ON p.id = ti.product_id
                         WHERE ti.tablet_id = ? ORDER BY ti.sort, ti.id');
    $st->execute([$tabletId]);
    return $st->fetchAll();
}

function product_full(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $st = db()->prepare('SELECT lang, title, description, features FROM product_i18n WHERE product_id = ?');
    $st->execute([$id]);
    $p['i18n'] = [];
    foreach ($st->fetchAll() as $r) {
        $p['i18n'][$r['lang']] = $r;
    }
    $st = db()->prepare('SELECT media_id, size FROM product_photos WHERE product_id = ? ORDER BY sort, id');
    $st->execute([$id]);
    $p['photos'] = $st->fetchAll();
    return $p;
}

function tablet_url(int $n, ?int $index = null): string
{
    $prefix = (string)setting('url_prefix', 'bbm-t');
    return '/' . rawurlencode($prefix . $n . ($index !== null ? chr(97 + $index) : '')) . '/';
}

function tablet_code(int $n, ?int $index = null): string
{
    return (string)setting('display_prefix', 'BBM-t') . $n . ($index !== null ? chr(97 + $index) : '');
}

function header_image_url(): string
{
    $m = media_get((int)setting('header_media_id', 0));
    return $m ? media_url($m) : asset('assets/header-default.svg');
}

function logo_image_url(): string
{
    $m = media_get((int)setting('logo_media_id', 0));
    return $m ? media_url($m) : asset('assets/logo-bezares.svg');
}

/** Colores del apartado de texto (franja del titular y fondo) y color de letra según el contraste. */
function info_colors(): array
{
    $hex = static fn(string $k, string $def): string => preg_match('/^#[0-9a-f]{6}$/iD', (string)setting($k, '')) ? strtolower((string)setting($k)) : $def;
    $top = $hex('info_bg_top', '#00567d');
    $bg = $hex('info_bg', '#6b7479');
    // Luminancia relativa (WCAG) del fondo principal para elegir texto blanco u oscuro.
    $lum = static function (string $h): float {
        $c = array_map(static function ($v) {
            $v = hexdec($v) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($h, 1), 2));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $fg = static fn(string $h): string => $lum($h) > 0.4 ? '#1d2a33' : '#ffffff';
    // Color del texto: el configurado, o automático según el fondo si está vacío.
    $fgBody = $hex('info_fg', $fg($bg));
    $fgTop = $hex('info_fg_top', $fg($top));
    return ['top' => $top, 'bg' => $bg, 'fg' => $fgBody, 'fg_top' => $fgTop,
            'fg_auto' => setting('info_fg', '') === '', 'fg_top_auto' => setting('info_fg_top', '') === ''];
}

/** Botón de pantalla completa (lo gestiona assets/expo.js). */
function fullscreen_button(): string
{
    return '<button type="button" class="fs-btn" data-fullscreen aria-label="Pantalla completa" title="Pantalla completa">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</button>';
}

function youtube_id(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return null;
}

function text_paragraphs(string $text): string
{
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($text === '') {
        return '';
    }
    $out = '';
    foreach (preg_split("/\n{2,}/", $text) as $p) {
        $out .= '<p>' . nl2br(e(trim($p)), false) . '</p>';
    }
    return $out;
}

function text_features(string $text): string
{
    $lines = array_filter(array_map(
        static fn($l) => trim(preg_replace('/^\s*[-•*·–]\s*/u', '', $l) ?? ''),
        explode("\n", str_replace("\r\n", "\n", $text))
    ), static fn($l) => $l !== '');
    if (!$lines) {
        return '';
    }
    return '<ul>' . implode('', array_map(static fn($l) => '<li>' . e($l) . '</li>', $lines)) . '</ul>';
}

// ---------------------------------------------------------------------------
// Plantilla común
// ---------------------------------------------------------------------------

function page_head(string $title, string $bodyClass, array $extraHead = []): void
{
    // Las páginas de tablet pueden incrustar el iframe de la newsletter: se permite su dominio.
    send_security_headers(public_csp(str_contains($bodyClass, 'page-tablet') ? newsletter_frame_origins() : []));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta name="theme-color" content="#00567D">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="icon" href="' . e(asset('assets/icon-250.png')) . '">'
        . '<link rel="manifest" href="/manifest.webmanifest">'
        . '<link rel="stylesheet" href="' . e(asset('assets/app.css')) . '">'
        . '<script src="' . e(asset('assets/expo.js')) . '" defer></script>'
        . implode('', $extraHead)
        . '</head><body class="' . e($bodyClass) . '">'
        . '<header class="site-header"><img src="' . e(header_image_url()) . '" alt="" width="1080" height="293" fetchpriority="high" decoding="async"></header>';
}

function page_foot(bool $withAdminLink = false): void
{
    if ($withAdminLink) {
        echo '<footer class="site-footer"><a href="/admin/" rel="nofollow">Administración</a></footer>';
    }
    echo '</body></html>';
}

function icon_tablet(): string
{
    return '<svg class="ico-tablet" viewBox="0 0 48 64" aria-hidden="true"><rect x="3" y="2" width="42" height="60" rx="6" fill="none" stroke="currentColor" stroke-width="3.5"/>'
        . '<rect x="9" y="9" width="30" height="42" rx="1.5" fill="currentColor" opacity=".18"/><circle cx="24" cy="56" r="2.6" fill="currentColor"/></svg>';
}

function icon_arrow(string $dir): string
{
    if (!in_array($dir, ['left', 'right', 'up'], true)) {
        return '';
    }
    $label = ['left' => 'A la izquierda', 'right' => 'A la derecha', 'up' => 'Arriba'][$dir];
    return '<span class="arrow arrow-' . $dir . '" role="img" aria-label="' . $label . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 8h-5v10H9V11H4z" fill="currentColor"/></svg></span>';
}

// ---------------------------------------------------------------------------
// Página principal
// ---------------------------------------------------------------------------

function render_index(): void
{
    conditional_page('index');
    $tablets = db()->query('SELECT * FROM tablets WHERE active = 1 ORDER BY number')->fetchAll();

    page_head((string)setting('site_title', 'Expo'), 'page-index');
    echo '<main class="wrap"><h1 class="event-title">' . e(setting('event_title', '')) . '</h1>';
    if (!$tablets) {
        echo '<p class="empty">No hay tablets activos.</p>';
    }
    echo '<ul class="tablet-grid">';
    foreach ($tablets as $t) {
        $names = array_map(static fn($i) => $i['short_name'], tablet_items((int)$t['id']));
        echo '<li><a class="tablet-btn" href="' . e(tablet_url((int)$t['number'])) . '">'
            . icon_tablet()
            . '<span class="tb-text"><span class="tb-num">Tablet ' . (int)$t['number'] . '</span>'
            . ($t['label'] !== '' ? '<span class="tb-label">' . e($t['label']) . '</span>' : '')
            . '<span class="tb-products">' . e(implode(' · ', $names)) . '</span></span></a></li>';
    }
    echo '</ul></main>';
    page_foot(true);
}

// ---------------------------------------------------------------------------
// Página de tablet / producto
// ---------------------------------------------------------------------------

function render_tablet(int $number, ?string $letter): void
{
    $tablet = tablet_by_number($number);
    if (!$tablet) {
        not_found();
    }
    $items = tablet_items((int)$tablet['id']);
    if (!$items) {
        not_found();
    }
    if ($letter !== null) {
        $idx = ord(strtolower($letter)) - 97;
        if (!isset($items[$idx])) {
            not_found();
        }
    } else {
        $idx = 0;
        foreach ($items as $i => $it) {
            if ((int)$it['is_default'] === 1) {
                $idx = $i;
                break;
            }
        }
    }
    conditional_page('tablet:' . $number . ':' . $idx);

    $item = $items[$idx];
    $p = product_full((int)$item['product_id']);
    if (!$p) {
        not_found();
    }
    $l1 = $item['lang1'];
    $l2 = $item['lang2'];
    $en = $p['i18n']['en'] ?? ['title' => '', 'description' => '', 'features' => ''];
    $t1 = $p['i18n'][$l1] ?? $en;
    $t2 = ($l2 !== '' && $l2 !== $l1) ? ($p['i18n'][$l2] ?? null) : null;

    $model = media_get($p['model_media_id'] ? (int)$p['model_media_id'] : null);
    if ($model && ($model['status'] !== 'ready' || !in_array(media_ext($model['path']), ['glb', 'gltf'], true))) {
        $model = null;
    }
    $video = media_get($p['video_media_id'] ? (int)$p['video_media_id'] : null);
    $yt = $video ? null : youtube_id($p['video_url']);
    $mainPhoto = media_get($p['photo_media_id'] ? (int)$p['photo_media_id'] : null);

    // URLs a precachear para uso sin conexión (páginas y medios de este tablet).
    $prefetch = [];
    foreach ($items as $i => $it) {
        $prefetch[] = tablet_url($number, $i);
        $ip = product_full((int)$it['product_id']);
        if ($ip) {
            foreach (['video_media_id', 'model_media_id', 'photo_media_id'] as $col) {
                $m = media_get($ip[$col] ? (int)$ip[$col] : null);
                if ($m && $m['status'] === 'ready') {
                    $prefetch[] = $m['kind'] === 'photo' ? media_photo_srcset($m)[0] : media_url($m);
                }
            }
        }
        $thumb = media_get((int)($it['photo_media_id'] ?: $it['product_photo_id']));
        if ($thumb) {
            $prefetch[] = media_photo_srcset($thumb)[0];
        }
    }
    $prefetch[] = tablet_url($number);
    if ($model) {
        $prefetch[] = asset('assets/vendor/model-viewer.min.js');
    }
    $prefetch[] = logo_image_url();

    $head = [];
    if ($model) {
        $head[] = '<script type="module" src="' . e(asset('assets/vendor/model-viewer.min.js')) . '"></script>';
    }
    $head[] = '<script type="application/json" id="expo-prefetch">'
        . json_encode(array_values(array_unique(array_filter($prefetch))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';

    page_head(tablet_code($number, $idx) . ' | ' . $p['short_name'] . ' – Bezares SA', 'page-tablet' . (newsletter_enabled() ? ' has-nl' : ''), $head);

    // Sin titular visible ("BBM-t1b | FRV"): tras el encabezado va directamente el índice.
    // 1) Índice de productos del tablet
    echo '<nav class="pidx" style="--n:' . count($items) . '" aria-label="Productos">';
    foreach ($items as $i => $it) {
        $thumb = media_get((int)($it['photo_media_id'] ?: $it['product_photo_id']));
        [$src, $srcset] = media_photo_srcset($thumb);
        echo '<a class="pidx-item' . ($i === $idx ? ' is-current" aria-current="page' : '') . '" href="' . e(tablet_url($number, $i)) . '">'
            . '<span class="pidx-photo">' . ($src !== '' ? '<img src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="25vw"' : '') . ' alt="" loading="eager" decoding="async">' : '') . '</span>'
            . '<span class="pidx-name">' . e($it['short_name']) . icon_arrow($it['arrow']) . '</span></a>';
    }
    echo '</nav>';

    // 2) Vídeo y 3) modelo 3D
    $hasVideo = $video || $yt;
    echo '<div class="media-row' . ($hasVideo ? '' : ' no-video') . '">';
    if ($video) {
        echo '<section class="video fs-target"><video src="' . e(media_url($video)) . '" autoplay muted loop playsinline preload="auto" disablepictureinpicture></video>'
            . fullscreen_button() . '</section>';
    } elseif ($yt) {
        echo '<section class="video fs-target"><iframe src="https://www.youtube-nocookie.com/embed/' . e($yt) . '?autoplay=1&amp;mute=1&amp;loop=1&amp;playlist=' . e($yt)
            . '&amp;controls=0&amp;fs=0&amp;disablekb=1&amp;iv_load_policy=3&amp;rel=0&amp;playsinline=1" title="Vídeo" allow="autoplay; encrypted-media" loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
            . fullscreen_button() . '</section>';
    }
    echo '<section class="model3d fs-target' . ($model ? '' : ' is-photo') . '" style="--logo:url(&quot;' . e(logo_image_url()) . '&quot;)">';
    if ($model) {
        $s = media_settings($model);
        $radius = max(20, min(400, round(10000 / max(10, (float)$s['size']))));
        $offset = model_offset_style($s);
        echo '<model-viewer src="' . e(media_url($model)) . '" alt="' . e($p['short_name']) . '"'
            . ($offset !== '' ? ' style="' . e($offset) . '"' : '')
            . ' auto-rotate auto-rotate-delay="0" rotation-per-second="' . (float)$s['speed'] . 'deg"'
            . ' camera-controls disable-zoom disable-pan touch-action="pan-y" interaction-prompt="none"'
            . ' orientation="' . e(model_orientation($s)) . '"'
            . ' camera-orbit="0deg ' . (float)$s['elevation'] . 'deg ' . $radius . '%"'
            . ' exposure="' . (float)$s['exposure'] . '" shadow-intensity="' . (float)$s['shadow'] . '"'
            . ' environment-image="neutral" loading="eager" reveal="auto"></model-viewer>';
    } elseif ($mainPhoto) {
        [$src, $srcset] = media_photo_srcset($mainPhoto);
        echo '<img class="model-fallback" src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="(orientation: landscape) 50vw, 100vw"' : '') . ' alt="' . e($p['short_name']) . '" decoding="async">';
    }
    if ($model || $mainPhoto) {
        echo fullscreen_button();
    }
    echo '</section></div>';

    // 4-6) Titular, descripción y características en 2 idiomas
    $two = $t2 !== null;
    $ic = info_colors(); // valores validados (#rrggbb)
    echo '<section class="info' . ($two ? ' two' : '') . '" style="--info-top:' . $ic['top'] . ';--info-bg:' . $ic['bg']
        . ';--info-fg:' . $ic['fg'] . ';--info-fg-top:' . $ic['fg_top'] . '">';
    echo '<div class="titles"><h2 lang="' . e($l1) . '">' . e($t1['title'] ?: $p['short_name']) . '</h2>'
        . ($two && $t2['title'] !== '' ? '<p class="title2" lang="' . e($l2) . '">' . e($t2['title']) . '</p>' : '') . '</div>';
    $d1 = text_paragraphs($t1['description']);
    $d2 = $two ? text_paragraphs($t2['description']) : '';
    if ($d1 !== '' || $d2 !== '') {
        echo '<div class="cols desc"><div lang="' . e($l1) . '">' . $d1 . '</div>' . ($two ? '<div lang="' . e($l2) . '">' . $d2 . '</div>' : '') . '</div>';
    }
    $f1 = text_features($t1['features']);
    $f2 = $two ? text_features($t2['features']) : '';
    if ($f1 !== '' || $f2 !== '') {
        echo '<div class="cols feats"><div lang="' . e($l1) . '">' . $f1 . '</div>' . ($two ? '<div lang="' . e($l2) . '">' . $f2 . '</div>' : '') . '</div>';
    }
    echo '</section>';

    // 7) Fotos adicionales
    if ($p['photos']) {
        echo '<section class="photos">';
        $sizes = ['small' => '33vw', 'medium' => '50vw', 'large' => '100vw'];
        foreach ($p['photos'] as $ph) {
            $m = media_get((int)$ph['media_id']);
            if (!$m) {
                continue;
            }
            $size = isset($sizes[$ph['size']]) ? $ph['size'] : 'medium';
            [$src, $srcset] = media_photo_srcset($m);
            echo '<figure class="ph ph-' . $size . '"><img src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="' . $sizes[$size] . '"' : '')
                . ($m['width'] && $m['height'] ? ' width="' . (int)$m['width'] . '" height="' . (int)$m['height'] . '"' : '')
                . ' alt="" loading="lazy" decoding="async"></figure>';
        }
        echo '</section>';
    }
    echo newsletter_markup(); // HTML ya saneado al guardarlo en el panel
    page_foot(false);
}
