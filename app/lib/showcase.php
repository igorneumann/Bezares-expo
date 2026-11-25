<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Página de tablet "escaparate 3D": textos de la interfaz por idioma, banderas e idioma inicial.
 *  - Idioma por defecto de la interfaz: ajuste "ui_default_lang" (inglés salvo que se cambie).
 *  - Cada producto del tablet ofrece sus dos idiomas (Idioma 1 / Idioma 2); la bandera cambia entre ellos.
 *  - Textos propios o traducidos con ChatGPT: ajuste "ui_strings" (JSON {lang: {clave: texto}}).
 */

const UI_KEYS = ['more_info', 'close', 'touch', 'right', 'left', 'up', 'features'];

const UI_BUILTIN = [
    'en' => ['more_info' => 'More information', 'close' => 'Close', 'touch' => 'Touch to explore',
             'right' => 'On your right', 'left' => 'On your left', 'up' => 'Above', 'features' => 'Main features'],
    'es' => ['more_info' => 'Más información', 'close' => 'Cerrar', 'touch' => 'Toca para explorar',
             'right' => 'A tu derecha', 'left' => 'A tu izquierda', 'up' => 'Encima', 'features' => 'Características principales'],
    'de' => ['more_info' => 'Mehr Informationen', 'close' => 'Schließen', 'touch' => 'Zum Entdecken berühren',
             'right' => 'Rechts von Ihnen', 'left' => 'Links von Ihnen', 'up' => 'Oben', 'features' => 'Hauptmerkmale'],
    'fr' => ['more_info' => "Plus d'informations", 'close' => 'Fermer', 'touch' => 'Touchez pour découvrir',
             'right' => 'À votre droite', 'left' => 'À votre gauche', 'up' => 'Au-dessus', 'features' => 'Caractéristiques principales'],
    'it' => ['more_info' => 'Maggiori informazioni', 'close' => 'Chiudi', 'touch' => 'Tocca per esplorare',
             'right' => 'Alla tua destra', 'left' => 'Alla tua sinistra', 'up' => 'Sopra', 'features' => 'Caratteristiche principali'],
    'pt' => ['more_info' => 'Mais informações', 'close' => 'Fechar', 'touch' => 'Toque para explorar',
             'right' => 'À sua direita', 'left' => 'À sua esquerda', 'up' => 'Acima', 'features' => 'Características principais'],
];

function ui_custom_strings(): array
{
    $s = json_decode((string)setting('ui_strings', '{}'), true);
    return is_array($s) ? $s : [];
}

/** Textos de la interfaz para un idioma (propios > integrados > inglés). */
function ui_strings(string $lang): array
{
    $custom = ui_custom_strings();
    $out = UI_BUILTIN['en'];
    foreach ([UI_BUILTIN[$lang] ?? [], $custom[$lang] ?? []] as $layer) {
        foreach (UI_KEYS as $k) {
            if (isset($layer[$k]) && is_string($layer[$k]) && trim($layer[$k]) !== '') {
                $out[$k] = $layer[$k];
            }
        }
    }
    return $out;
}

/** ¿Tiene el idioma textos propios de interfaz (integrados o guardados)? */
function ui_has_strings(string $lang): bool
{
    return isset(UI_BUILTIN[$lang]) || !empty(ui_custom_strings()[$lang]);
}

function ui_default_lang(): string
{
    $l = (string)setting('ui_default_lang', 'en');
    return $l !== '' ? $l : 'en';
}

function idle_seconds(): int
{
    return max(0, min(3600, (int)setting('idle_seconds', '120')));
}

/** Bandera circular (SVG) de un idioma. Sin bandera conocida: el código del idioma. */
function flag_svg(string $lang): string
{
    $f = [
        'en' => '<rect width="60" height="60" fill="#012169"/><path d="M0 0L60 60M60 0L0 60" stroke="#fff" stroke-width="12"/>'
              . '<path d="M0 0L60 60M60 0L0 60" stroke="#C8102E" stroke-width="5"/><path d="M30 0V60M0 30H60" stroke="#fff" stroke-width="18"/>'
              . '<path d="M30 0V60M0 30H60" stroke="#C8102E" stroke-width="10"/>',
        'es' => '<rect width="60" height="60" fill="#c60b1e"/><rect y="15" width="60" height="30" fill="#ffc400"/>',
        'de' => '<rect width="60" height="20" fill="#000"/><rect y="20" width="60" height="20" fill="#dd0000"/><rect y="40" width="60" height="20" fill="#ffce00"/>',
        'fr' => '<rect width="20" height="60" fill="#002654"/><rect x="20" width="20" height="60" fill="#fff"/><rect x="40" width="20" height="60" fill="#ce1126"/>',
        'it' => '<rect width="20" height="60" fill="#009246"/><rect x="20" width="20" height="60" fill="#fff"/><rect x="40" width="20" height="60" fill="#ce2b37"/>',
        'pt' => '<rect width="60" height="60" fill="#ff0000"/><rect width="24" height="60" fill="#006600"/><circle cx="24" cy="30" r="9" fill="#ffcc00"/>',
        'nl' => '<rect width="60" height="20" fill="#ae1c28"/><rect y="20" width="60" height="20" fill="#fff"/><rect y="40" width="60" height="20" fill="#21468b"/>',
        'pl' => '<rect width="60" height="30" fill="#fff"/><rect y="30" width="60" height="30" fill="#dc143c"/>',
    ];
    $base = strtolower(substr($lang, 0, 2));
    $inner = $f[$base] ?? '<rect width="60" height="60" fill="#00567D"/><text x="30" y="38" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" font-weight="700" fill="#fff">'
        . e(strtoupper(substr($lang, 0, 2))) . '</text>';
    return '<svg viewBox="0 0 60 60" aria-hidden="true">' . $inner . '</svg>';
}

/** Idioma elegido en el tablet (cookie que guarda la bandera), si es uno de los disponibles. */
function showcase_lang(array $available): string
{
    $c = (string)($_COOKIE['expo_lang'] ?? '');
    if ($c !== '' && in_array($c, $available, true)) {
        return $c;
    }
    $def = ui_default_lang();
    return in_array($def, $available, true) ? $def : $available[0];
}

/** Texto de la interfaz en todos los idiomas de la página (se muestra el del idioma activo). */
function ui_text(string $key, array $langs): string
{
    $out = '';
    foreach ($langs as $l) {
        $out .= '<span data-l="' . e($l) . '" lang="' . e($l) . '">' . e(ui_strings($l)[$key]) . '</span>';
    }
    return $out;
}

/** Traduce los textos de la interfaz (desde inglés) con ChatGPT. */
function ui_translate(string $lang, string $langName): array
{
    $fields = [
        'title'       => '',
        'description' => '',
        'features'    => implode("\n", array_map(static fn($k) => UI_BUILTIN['en'][$k], UI_KEYS)),
    ];
    $res = translate_product($fields, $lang, $langName);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $res['features'])), static fn($l) => $l !== ''));
    if (count($lines) !== count(UI_KEYS)) {
        throw new RuntimeException('La traducción no devolvió todos los textos; inténtalo de nuevo.');
    }
    return array_combine(UI_KEYS, $lines);
}

/** Tamaño del encabezado en las páginas de tablet. */
const HEADER_MODES = ['full', 'large', 'medium', 'small', 'none'];

function tablet_header_mode(): string
{
    $m = (string)setting('tablet_header', 'full');
    return in_array($m, HEADER_MODES, true) ? $m : 'full';
}

/** Claridad del logo de fondo del 3D: opacidad de 0,05 (muy clarito) a 1 (color total). */
function logo_opacity(): float
{
    $v = (int)setting('logo_opacity', '13');
    return max(5, min(100, $v)) / 100;
}

/**
 * Temas de las páginas de tablet, propios de cada evento: uno para el tablet en horizontal
 * ("theme") y otro en vertical ("theme_port"):
 *  light / dark           → diseño "Escaparate 3D" claro u oscuro
 *  cards / cards-dark     → diseño "Tarjetas" (evolución del diseño original) claro u oscuro
 */
const SHOWCASE_THEMES = ['light', 'dark', 'cards', 'cards-dark'];

/** Tema en horizontal de un evento. */
function event_theme(array $ev): string
{
    return in_array($ev['theme'] ?? null, SHOWCASE_THEMES, true) ? $ev['theme'] : 'light';
}

/** Tema en vertical de un evento. */
function event_theme_port(array $ev): string
{
    return in_array($ev['theme_port'] ?? null, SHOWCASE_THEMES, true) ? $ev['theme_port'] : event_theme($ev);
}

/** Diseño de un tema: 'showcase' (escaparate 3D) o 'cards' (tarjetas). */
function theme_layout(string $theme): string
{
    return str_starts_with($theme, 'cards') ? 'cards' : 'showcase';
}

/** Clases de <body> de un tema. */
function theme_classes(string $theme): string
{
    return theme_layout($theme) . ' theme-' . (in_array($theme, ['dark', 'cards-dark'], true) ? 'dark' : 'light');
}

/** Color de fondo de la página de un tema (el mismo que en assets/app.css). */
function theme_background(string $theme): string
{
    return ['light' => '#fff', 'cards' => '#f2f5f7'][$theme] ?? '#0e171d';
}
