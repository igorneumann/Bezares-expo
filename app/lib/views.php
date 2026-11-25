<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------

/** Tablet activo de un evento, con los datos del evento que usa la página. */
function tablet_find(int $eventId, int $n): ?array
{
    $st = db()->prepare('SELECT t.*, e.active AS event_active, e.theme, e.theme_port
                         FROM tablets t JOIN events e ON e.id = t.event_id
                         WHERE t.event_id = ? AND t.number = ? AND t.active = 1');
    $st->execute([$eventId, $n]);
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

/** Dirección de un tablet (fila de la tabla tablets) o de uno de sus productos. */
function tablet_url(array $tablet, ?int $index = null): string
{
    return '/e' . (int)$tablet['event_id'] . '-t' . (int)$tablet['number'] . ($index !== null ? chr(97 + $index) : '') . '/';
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

function page_head(string $title, string $bodyClass, array $extraHead = [], string $bodyAttrs = ''): void
{
    // Las páginas de tablet pueden incrustar el iframe de la newsletter: se permite su dominio.
    send_security_headers(public_csp(str_contains($bodyClass, 'page-tablet') ? newsletter_frame_origins() : []));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta name="theme-color" content="#00567D">'
        . '<title>' . e($title) . '</title>'
        . favicon_links()
        . '<link rel="manifest" href="/manifest.webmanifest">'
        . '<link rel="stylesheet" href="' . e(asset('assets/app.css')) . '">'
        . '<script src="' . e(asset('assets/expo.js')) . '" defer></script>'
        . implode('', $extraHead)
        . '</head><body class="' . e($bodyClass) . '"' . $bodyAttrs . '>' // $bodyAttrs: atributos ya escapados por quien llama
        . (str_contains($bodyClass, 'hdr-none') ? '' : '<header class="site-header"><img src="' . e(header_image_url()) . '" alt="" width="1080" height="293" fetchpriority="high" decoding="async"></header>');
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
    $events = events_active();

    page_head((string)setting('site_title', 'Expo'), 'page-index');
    echo '<main class="wrap">';
    if (!$events) {
        echo '<p class="empty">No hay eventos activos.</p>';
    } elseif (count($events) === 1) {
        echo '<h1 class="event-title">' . e(event_label($events[0])) . '</h1>';
    } else {
        // Varios eventos activos: el titular es la lista de eventos (assets/expo.js muestra el elegido)
        echo '<h1 class="sr-only">' . e(setting('site_title', 'Expo')) . '</h1><nav class="event-tabs" aria-label="Eventos">';
        foreach ($events as $k => $ev) {
            echo '<button type="button" data-event-tab="' . (int)$ev['id'] . '" aria-pressed="' . ($k === 0 ? 'true' : 'false') . '">' . e(event_label($ev)) . '</button>';
        }
        echo '</nav>';
    }
    $st = db()->prepare('SELECT * FROM tablets WHERE event_id = ? AND active = 1 ORDER BY number');
    foreach ($events as $k => $ev) {
        $st->execute([(int)$ev['id']]);
        $tablets = $st->fetchAll();
        echo '<section data-event-panel="' . (int)$ev['id'] . '"' . ($k > 0 ? ' hidden' : '') . '>';
        if (!$tablets) {
            echo '<p class="empty">No hay tablets activos.</p>';
        }
        echo '<ul class="tablet-grid">';
        foreach ($tablets as $t) {
            $names = array_map(static fn($i) => $i['short_name'], tablet_items((int)$t['id']));
            echo '<li><a class="tablet-btn" href="' . e(tablet_url($t)) . '">'
                . icon_tablet()
                . '<span class="tb-text"><span class="tb-num">Tablet ' . (int)$t['number'] . '</span>'
                . ($t['label'] !== '' ? '<span class="tb-label">' . e($t['label']) . '</span>' : '')
                . '<span class="tb-products">' . e(implode(' · ', $names)) . '</span></span></a></li>';
        }
        echo '</ul></section>';
    }
    echo '</main>';
    page_foot(true);
}

// ---------------------------------------------------------------------------
// Página de tablet / producto
// ---------------------------------------------------------------------------

function render_tablet(int $eventId, int $number, ?string $letter): void
{
    $tablet = tablet_find($eventId, $number);
    if (!$tablet) {
        not_found();
    }
    // Evento desactivado: sólo lo ve quien tiene la sesión del panel abierta (para prepararlo).
    if ((int)$tablet['event_active'] !== 1
        && ((empty($_COOKIE[SESSION_NAME]) && empty($_COOKIE[REMEMBER_COOKIE])) || !current_user())) {
        not_found();
    }
    $items = tablet_items((int)$tablet['id']);
    if (!$items) {
        not_found();
    }
    $defIdx = 0;
    foreach ($items as $i => $it) {
        if ((int)$it['is_default'] === 1) {
            $defIdx = $i;
            break;
        }
    }
    if ($letter !== null) {
        $idx = ord(strtolower($letter)) - 97;
        if (!isset($items[$idx])) {
            not_found();
        }
    } else {
        $idx = $defIdx;
    }

    $item = $items[$idx];
    $p = product_full((int)$item['product_id']);
    if (!$p) {
        not_found();
    }

    // Idiomas de este producto en el tablet (Idioma 1 / Idioma 2) e idioma activo.
    $langs = array_values(array_unique(array_filter([(string)$item['lang1'], (string)$item['lang2']],
        static fn($l) => (bool)preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/D', $l))));
    if (!$langs) {
        $langs = ['en'];
    }
    $cur = showcase_lang($langs);
    conditional_page('tablet:' . $eventId . ':' . $number . ':' . $idx . ':' . $cur);

    $en = $p['i18n']['en'] ?? ['title' => '', 'description' => '', 'features' => ''];
    $text = static fn(string $l): array => $p['i18n'][$l] ?? $en;
    $langNames = array_column(languages(), 'name', 'code');

    $model = media_get($p['model_media_id'] ? (int)$p['model_media_id'] : null);
    if ($model && ($model['status'] !== 'ready' || !in_array(media_ext($model['path']), ['glb', 'gltf'], true))) {
        $model = null;
    }
    $video = media_get($p['video_media_id'] ? (int)$p['video_media_id'] : null);
    $yt = $video ? null : youtube_id($p['video_url']);
    $mainPhoto = media_get($p['photo_media_id'] ? (int)$p['photo_media_id'] : null);
    // Imagen que se ve en el hueco del vídeo mientras se guarda en el tablet
    $poster = media_photo_srcset($mainPhoto ?: media_get((int)($item['photo_media_id'] ?: $item['product_photo_id'])))[0];

    // URLs a precachear para uso sin conexión (páginas y medios de este tablet).
    $prefetch = [];
    $ytCount = 0; // vídeos de YouTube: no se pueden guardar en el tablet
    foreach ($items as $i => $it) {
        $prefetch[] = tablet_url($tablet, $i);
        $ip = product_full((int)$it['product_id']);
        if ($ip) {
            if (!media_get($ip['video_media_id'] ? (int)$ip['video_media_id'] : null) && youtube_id($ip['video_url'])) {
                $ytCount++;
            }
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
    $prefetch[] = tablet_url($tablet);
    if ($model) {
        $prefetch[] = asset('assets/vendor/model-viewer.min.js');
    }
    $prefetch[] = logo_image_url();
    $prefetch[] = asset('assets/app.css');
    $prefetch[] = asset('assets/expo.js');

    $head = [];
    if ($model) {
        $head[] = '<script type="module" src="' . e(asset('assets/vendor/model-viewer.min.js')) . '"></script>';
    }
    $head[] = '<script type="application/json" id="expo-prefetch">'
        . json_encode(array_values(array_unique(array_filter($prefetch))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
    // Sólo se ve el texto del idioma activo (los códigos están validados arriba).
    $head[] = '<style>' . implode('', array_map(
        static fn($l) => 'body[data-lang="' . $l . '"] [data-l]:not([data-l="' . $l . '"]){display:none!important}', $langs)) . '</style>';

    $n = count($items);
    $defLang = in_array(ui_default_lang(), $langs, true) ? ui_default_lang() : $langs[0];
    // data-tablet / data-product / data-event: para las estadísticas
    $attrs = ' data-yt="' . $ytCount . '" data-tablet="' . $number . '" data-product="' . (int)$p['id'] . '" data-event="' . $eventId . '"'
        . ' data-lang="' . e($cur) . '" data-default-lang="' . e($defLang) . '"'
        . ' data-home="' . e(tablet_url($tablet, $defIdx)) . '" data-is-home="' . ($idx === $defIdx ? '1' : '0') . '" data-idle="' . idle_seconds() . '"'
        . ($n > 1 ? ' data-prev="' . e(tablet_url($tablet, ($idx - 1 + $n) % $n)) . '" data-next="' . e(tablet_url($tablet, ($idx + 1) % $n)) . '"' : '');

    // Temas de la feria: uno en horizontal y otro en vertical
    $land = event_theme($tablet);
    $port = event_theme_port($tablet);
    $title = 'Tablet ' . $number . ' | ' . $p['short_name'] . ' – Bezares SA';
    $cls = ' hdr-' . tablet_header_mode() . (newsletter_enabled() ? ' has-nl' : '');
    $content = static function (string $theme) use ($tablet, $idx, $items, $p, $langs, $langNames, $text, $model, $video, $yt, $mainPhoto, $poster): void {
        if (theme_layout($theme) === 'cards') {
            render_tablet_cards($tablet, $idx, $items, $p, $langs, $langNames, $text, $model, $video, $yt, $mainPhoto, $poster);
        } else {
            render_tablet_showcase($tablet, $idx, $items, $p, $langs, $langNames, $text, $model, $video, $yt, $mainPhoto, $poster);
        }
    };

    if ($land === $port) {
        page_head($title, 'page-tablet ' . theme_classes($land) . $cls, $head, $attrs);
        $content($land);
    } else {
        // Los dos diseños van en la página sin activarse (<template>): assets/expo.js coloca el de
        // la orientación del tablet y, si se gira, el otro. Así funciona también sin conexión.
        $head[] = '<style>body.orient-wait{background:' . theme_background($land) . '}'
            . '@media (orientation:portrait){body.orient-wait{background:' . theme_background($port) . '}}</style>';
        $attrs .= ' data-theme-land="' . e(theme_classes($land)) . '" data-theme-port="' . e(theme_classes($port)) . '"';
        page_head($title, 'page-tablet orient-wait' . $cls, $head, $attrs);
        foreach (['land' => $land, 'port' => $port] as $o => $theme) {
            echo '<template data-orient="' . $o . '">';
            $content($theme);
            echo '</template>';
        }
    }

    echo idle_hint_markup($langs);
    echo newsletter_markup(); // HTML ya saneado al guardarlo en el panel
    page_foot(false);
}

/** Diseño "Escaparate 3D": contenido de la página (sin cabecera ni pie). */
function render_tablet_showcase(array $tablet, int $idx, array $items, array $p, array $langs, array $langNames, callable $text,
                                ?array $model, ?array $video, ?string $yt, ?array $mainPhoto, string $poster): void
{
    $n = count($items);
    // ---- Escenario: modelo 3D a pantalla completa ----
    echo '<main class="stage">';
    echo '<section class="model3d fs-target' . ($model ? '' : ' is-photo') . '" style="--logo:url(&quot;' . e(logo_image_url()) . '&quot;);--logo-opacity:' . logo_opacity() . '">';
    echo model_markup($model, $mainPhoto, $p['short_name'], '100vw') . '</section>';

    // Titular (en los idiomas del producto) y bandera para cambiar de idioma
    echo '<div class="sc-head">';
    echo language_flags($langs, $langNames);
    echo '<div class="sc-title"><h1>' . e($p['short_name']) . '</h1><p class="sc-sub">';
    foreach ($langs as $l) {
        echo '<span data-l="' . e($l) . '" lang="' . e($l) . '">' . e($text($l)['title']) . '</span>';
    }
    echo '</p></div></div>';

    // Vídeo en miniatura (pantalla completa al tocarlo)
    if ($video) {
        echo '<div class="pip fs-target" data-fullscreen role="button" tabindex="0" aria-label="Vídeo">'
            . video_markup($video, $poster) . '</div>';
    } elseif ($yt) {
        echo '<div class="pip fs-target"><iframe src="https://www.youtube-nocookie.com/embed/' . e($yt) . '?autoplay=1&amp;mute=1&amp;loop=1&amp;playlist=' . e($yt)
            . '&amp;controls=0&amp;fs=0&amp;disablekb=1&amp;iv_load_policy=3&amp;rel=0&amp;playsinline=1" title="Vídeo" allow="autoplay; encrypted-media" loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
            . '<span class="pip-tap" data-fullscreen role="button" tabindex="0" aria-label="Vídeo"></span></div>';
    }

    echo '<button type="button" class="more-btn" data-more aria-controls="info-panel" aria-expanded="false">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v6M12 7.5v.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>'
        . ui_text('more_info', $langs) . '</button>';

    if ($n > 1) {
        echo '<div class="sc-dots">';
        foreach ($items as $i => $it) {
            echo '<a href="' . e(tablet_url($tablet, $i)) . '"' . ($i === $idx ? ' class="on" aria-current="page"' : '') . ' aria-label="' . e($it['short_name']) . '"></a>';
        }
        echo '</div>';
    }
    echo '</main>';

    echo product_bar($tablet, $idx, $items, $langs);

    // ---- Panel "Más información": descripción, características y fotos ----
    $ipStyle = info_style_vars();
    echo '<aside class="info-panel" id="info-panel" hidden' . ($ipStyle !== '' ? ' style="' . $ipStyle . '"' : '') . '>'
        . '<button type="button" class="ip-close" data-more-close><span aria-hidden="true">×</span><span class="sr-only">' . ui_text('close', $langs) . '</span></button>';
    foreach ($langs as $l) {
        $t = $text($l);
        echo '<div class="ip-lang" data-l="' . e($l) . '" lang="' . e($l) . '">'
            . '<h2>' . e($t['title'] ?: $p['short_name']) . '</h2>'
            . text_paragraphs($t['description']);
        $f = text_features($t['features']);
        if ($f !== '') {
            echo '<h3>' . e(ui_strings($l)['features']) . '</h3>' . $f;
        }
        echo '</div>';
    }
    if ($p['photos']) {
        echo '<div class="ip-photos">' . photos_markup($p['photos'], ['small' => '20vw', 'medium' => '28vw', 'large' => '56vw']) . '</div>';
    }
    echo '</aside>';
}

/** Barra inferior de productos con flechas de ubicación (la misma en los dos diseños). */
function product_bar(array $tablet, int $idx, array $items, array $langs): string
{
    $out = '<nav class="pbar" style="--n:' . count($items) . '" aria-label="Productos">';
    foreach ($items as $i => $it) {
        $thumb = media_get((int)($it['photo_media_id'] ?: $it['product_photo_id']));
        [$src] = media_photo_srcset($thumb);
        $out .= '<a class="pb-item' . ($i === $idx ? ' on" aria-current="page' : '') . '" href="' . e(tablet_url($tablet, $i)) . '">'
            . '<span class="pb-thumb">' . ($src !== '' ? '<img src="' . e($src) . '" alt="" decoding="async">' : '') . '</span>'
            . '<span class="pb-txt"><span class="pb-name">' . e($it['short_name']) . '</span>'
            . location_arrow((string)$it['arrow'], $langs) . '</span></a>';
    }
    return $out . '</nav>';
}

/** Banderita redonda para cambiar al otro idioma del producto (la gestiona assets/expo.js). */
function language_flags(array $langs, array $langNames): string
{
    if (count($langs) < 2) {
        return '';
    }
    $out = '';
    foreach ($langs as $l) {
        $other = $langs[0] === $l ? $langs[1] : $langs[0];
        $name = $langNames[$other] ?? strtoupper($other);
        $out .= '<button type="button" class="flag-btn" data-l="' . e($l) . '" data-switch="' . e($other) . '" aria-label="' . e($name) . '" title="' . e($name) . '">'
            . flag_svg($other) . '</button>';
    }
    return $out;
}

/** Aviso "Touch to explore" del modo reposo. */
function idle_hint_markup(array $langs): string
{
    return '<div class="idle-hint" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 11V5.5a1.5 1.5 0 0 1 3 0V11m0-1.5a1.5 1.5 0 0 1 3 0V11m0-.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1a6 6 0 0 1-5-2.7L3.6 15a1.6 1.6 0 0 1 2.6-1.8L9 15.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . ui_text('touch', $langs) . '</div>';
}

/**
 * Colores del apartado de texto como variables CSS. Sólo se envían los cambiados en
 * Configuración: los de por defecto los pone el CSS según el tema (claro u oscuro).
 */
function info_style_vars(): string
{
    $ic = info_colors();
    $topCustom = $ic['top'] !== '#00567d';
    $bgCustom = $ic['bg'] !== '#6b7479';
    return ($topCustom ? '--info-top:' . $ic['top'] . ';' : '')
        . ($bgCustom ? '--info-bg:' . $ic['bg'] . ';' : '')
        . (!$ic['fg_auto'] || $bgCustom ? '--info-fg:' . $ic['fg'] . ';' : '')
        . (!$ic['fg_top_auto'] || $topCustom ? '--info-fg-top:' . $ic['fg_top'] : '');
}

/**
 * Vídeo local. Va sin src: assets/expo.js lo pone cuando el fichero está guardado en el tablet
 * (o directamente si no se puede guardar), para descargarlo una sola vez.
 */
function video_markup(array $video, string $poster): string
{
    return '<video data-src="' . e(media_url($video)) . '"' . ($poster !== '' ? ' poster="' . e($poster) . '"' : '')
        . ' muted loop playsinline preload="auto" disablepictureinpicture></video>';
}

/** Visor 3D (o foto principal si no hay modelo) con su botón de pantalla completa. */
function model_markup(?array $model, ?array $mainPhoto, string $alt, string $sizes): string
{
    $out = '';
    if ($model) {
        $s = media_settings($model);
        $radius = max(20, min(400, round(10000 / max(10, (float)$s['size']))));
        $offset = model_offset_style($s);
        $out .= '<model-viewer src="' . e(media_url($model)) . '" alt="' . e($alt) . '"'
            . ($offset !== '' ? ' style="' . e($offset) . '"' : '')
            . ' auto-rotate auto-rotate-delay="0" rotation-per-second="' . (float)$s['speed'] . 'deg"'
            . ' camera-controls disable-zoom disable-pan touch-action="pan-y" interaction-prompt="none"'
            . ' orientation="' . e(model_orientation($s)) . '"'
            . ' camera-orbit="0deg ' . (float)$s['elevation'] . 'deg ' . $radius . '%"'
            . ' exposure="' . (float)$s['exposure'] . '" shadow-intensity="' . (float)$s['shadow'] . '"'
            . ' environment-image="neutral" loading="eager" reveal="auto"></model-viewer>';
    } elseif ($mainPhoto) {
        [$src, $srcset] = media_photo_srcset($mainPhoto);
        $out .= '<img class="model-fallback" src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="' . e($sizes) . '"' : '') . ' alt="' . e($alt) . '" decoding="async">';
    }
    if ($model || $mainPhoto) {
        $out .= fullscreen_button();
    }
    return $out;
}

/** Fotos adicionales del producto con su tamaño (pequeña / mediana / grande). */
function photos_markup(array $photos, array $sizes): string
{
    $out = '';
    foreach ($photos as $ph) {
        $m = media_get((int)$ph['media_id']);
        if (!$m) {
            continue;
        }
        $size = isset($sizes[$ph['size']]) ? $ph['size'] : 'medium';
        [$src, $srcset] = media_photo_srcset($m);
        $out .= '<figure class="ph ph-' . $size . '"><img src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="' . $sizes[$size] . '"' : '')
            . ($m['width'] && $m['height'] ? ' width="' . (int)$m['width'] . '" height="' . (int)$m['height'] . '"' : '')
            . ' alt="" loading="lazy" decoding="async"></figure>';
    }
    return $out;
}

/** Flecha de ubicación del producto físico, con el texto en los idiomas del tablet. */
function location_arrow(string $dir, array $langs): string
{
    if (!in_array($dir, ['left', 'right', 'up'], true)) {
        return '';
    }
    return '<span class="pb-arrow arrow-' . $dir . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 8h-5v10H9V11H4z" fill="currentColor"/></svg>'
        . '<span class="pb-arrow-txt">' . ui_text($dir, $langs) . '</span></span>';
}

/**
 * Diseño "Tarjetas" (evolución del diseño original): vídeo y 3D en tarjetas, titular, descripción
 * y características en el idioma activo, fotos y la barra de productos del escaparate, fija abajo.
 */
function render_tablet_cards(array $tablet, int $idx, array $items, array $p, array $langs, array $langNames, callable $text,
                             ?array $model, ?array $video, ?string $yt, ?array $mainPhoto, string $poster): void
{
    echo '<main class="cwrap">';

    // 1) Vídeo y 2) modelo 3D
    $hasVideo = $video || $yt;
    echo '<div class="crow' . ($hasVideo ? '' : ' no-video') . '">';
    if ($video) {
        echo '<section class="ccard cvideo fs-target">' . video_markup($video, $poster) . fullscreen_button() . '</section>';
    } elseif ($yt) {
        echo '<section class="ccard cvideo fs-target"><iframe src="https://www.youtube-nocookie.com/embed/' . e($yt) . '?autoplay=1&amp;mute=1&amp;loop=1&amp;playlist=' . e($yt)
            . '&amp;controls=0&amp;fs=0&amp;disablekb=1&amp;iv_load_policy=3&amp;rel=0&amp;playsinline=1" title="Vídeo" allow="autoplay; encrypted-media" loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
            . fullscreen_button() . '</section>';
    }
    if ($model || $mainPhoto) {
        echo '<section class="ccard model3d fs-target' . ($model ? '' : ' is-photo') . '" style="--logo:url(&quot;' . e(logo_image_url()) . '&quot;);--logo-opacity:' . logo_opacity() . '">'
            . model_markup($model, $mainPhoto, $p['short_name'], '(orientation: landscape) 50vw, 100vw') . '</section>';
    }
    echo '</div>';

    // 3-5) Titular, descripción y características (sólo se ve el idioma activo)
    $style = info_style_vars();
    echo '<section class="ccard cinfo"' . ($style !== '' ? ' style="' . $style . '"' : '') . '>';
    echo '<div class="cinfo-head"><h1>' . e($p['short_name']) . '</h1><p>';
    foreach ($langs as $l) {
        echo '<span data-l="' . e($l) . '" lang="' . e($l) . '">' . e($text($l)['title']) . '</span>';
    }
    echo '</p></div><div class="cinfo-body">';
    foreach ($langs as $l) {
        $t = $text($l);
        $desc = text_paragraphs($t['description']);
        $f = text_features($t['features']);
        echo '<div class="cinfo-lang' . ($desc !== '' && $f !== '' ? ' two' : '') . '" data-l="' . e($l) . '" lang="' . e($l) . '">'
            . ($desc !== '' ? '<div>' . $desc . '</div>' : '')
            . ($f !== '' ? '<div><h2>' . e(ui_strings($l)['features']) . '</h2>' . $f . '</div>' : '') . '</div>';
    }
    echo '</div></section>';

    // 6) Fotos adicionales
    if ($p['photos']) {
        echo '<section class="cphotos">' . photos_markup($p['photos'], ['small' => '33vw', 'medium' => '50vw', 'large' => '100vw']) . '</section>';
    }
    echo '</main>';

    echo language_flags($langs, $langNames); // flotante, encima de la barra, a la derecha
    echo product_bar($tablet, $idx, $items, $langs);
}
