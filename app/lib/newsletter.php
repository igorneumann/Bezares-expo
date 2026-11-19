<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Botón flotante "Newsletter" de las páginas de tablet.
 * El contenido del popup es HTML configurable desde el panel, pero se sanea con una
 * lista blanca (sin scripts ni eventos; iframes y enlaces sólo https) y los dominios de
 * los iframes se añaden a la CSP (frame-src) de forma automática.
 */

const NEWSLETTER_DEFAULT_HTML = '<iframe src="https://mailing.bezares.com/subscribe.php?lang=both" title="Newsletter" '
    . 'style="width:100%;max-width:580px;height:470px;border:0;" loading="lazy"></iframe>';

const NEWSLETTER_TAGS = [
    'iframe' => ['src', 'title', 'style', 'loading', 'width', 'height', 'referrerpolicy'],
    'a'      => ['href', 'target', 'rel', 'style', 'class'],
    'img'    => ['src', 'alt', 'style', 'width', 'height', 'class'],
    'div'    => ['style', 'class'], 'span' => ['style', 'class'], 'p' => ['style', 'class'],
    'h2'     => ['style', 'class'], 'h3' => ['style', 'class'], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
    'br'     => [], 'ul' => ['style'], 'ol' => ['style'], 'li' => ['style'],
];

function newsletter_enabled(): bool
{
    return setting('newsletter_enabled', '1') === '1';
}

function newsletter_label(): string
{
    $l = (string)setting('newsletter_label', '');
    return $l !== '' ? $l : 'Newsletter';
}

function newsletter_html(): string
{
    $h = setting('newsletter_html', null);
    // El valor guardado ya está saneado; el de por defecto se sanea igual (añade sandbox).
    return $h === null ? newsletter_sanitize(NEWSLETTER_DEFAULT_HTML) : (string)$h;
}

function newsletter_safe_url(string $url, bool $allowRelative): bool
{
    $url = trim($url);
    if ($allowRelative && preg_match('~^/(?!/)~', $url)) {
        return true;
    }
    if (str_starts_with(strtolower($url), 'mailto:')) {
        return !$allowRelative; // sólo para enlaces
    }
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']);
}

/** Sanea el HTML del popup con una lista blanca de etiquetas y atributos. */
function newsletter_sanitize(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="nl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('nl-root') ?? $doc->documentElement;
    if (!$root) {
        return '';
    }
    newsletter_clean_node($root);
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function newsletter_clean_node(DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) {
            continue;
        }
        if (!$child instanceof DOMElement) {
            $node->removeChild($child); // comentarios, instrucciones de proceso…
            continue;
        }
        $tag = strtolower($child->tagName);
        if (!isset(NEWSLETTER_TAGS[$tag])) {
            // Etiqueta no permitida: se elimina con su contenido si es activa, si no se conserva el texto.
            if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template', 'link', 'meta', 'base'], true)) {
                $node->removeChild($child);
            } else {
                newsletter_clean_node($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
            }
            continue;
        }
        $allowed = NEWSLETTER_TAGS[$tag];
        foreach (iterator_to_array($child->attributes) as $attr) {
            $name = strtolower($attr->name);
            $val = $attr->value;
            $keep = in_array($name, $allowed, true);
            if ($keep && in_array($name, ['src', 'href'], true)) {
                $keep = newsletter_safe_url($val, $tag === 'img');
            }
            if ($keep && $name === 'style') {
                // Nada de url(), expression() ni @import en estilos en línea.
                $keep = !preg_match('/url\s*\(|expression|@import|javascript:|behavior/i', $val);
            }
            if (!$keep) {
                $child->removeAttribute($attr->name);
            }
        }
        if ($tag === 'iframe') {
            if (!$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            // El iframe no puede navegar la página principal ni abrir ventanas sin permiso.
            $child->setAttribute('sandbox', 'allow-forms allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox');
            if (!$child->hasAttribute('title')) {
                $child->setAttribute('title', 'Newsletter');
            }
        }
        if ($tag === 'a') {
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener noreferrer');
        }
        newsletter_clean_node($child);
    }
}

/** Orígenes https de los iframes del popup, para añadirlos a frame-src de la CSP. */
function newsletter_frame_origins(): array
{
    if (!newsletter_enabled()) {
        return [];
    }
    preg_match_all('/<iframe[^>]+src="([^"]+)"/i', newsletter_html(), $m);
    $out = [];
    foreach ($m[1] as $src) {
        $p = parse_url(html_entity_decode($src));
        if (($p['scheme'] ?? '') === 'https' && !empty($p['host']) && preg_match('/^[a-z0-9.\-]+$/i', $p['host'])) {
            $out['https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '')] = true;
        }
    }
    return array_keys($out);
}

/** Botón flotante + plantilla del popup (el contenido se inserta al abrir: no consume datos hasta entonces). */
function newsletter_markup(): string
{
    if (!newsletter_enabled() || newsletter_html() === '') {
        return '';
    }
    return '<button type="button" class="nl-btn" data-newsletter-open>' . e(newsletter_label()) . '</button>'
        . '<template id="nl-content">' . newsletter_html() . '</template>'
        . '<dialog class="nl-dialog" id="nl-dialog" aria-label="' . e(newsletter_label()) . '">'
        . '<button type="button" class="nl-close" data-newsletter-close aria-label="Cerrar">×</button>'
        . '<div class="nl-body"></div></dialog>';
}
