<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Eventos (ferias). Cada uno tiene sus propios tablets, su título y sus temas, y se activa o
 * desactiva por separado: puede haber varios activos a la vez. Un evento desactivado conserva
 * toda su configuración, pero sus páginas sólo se ven con la sesión del panel abierta.
 */

const EVENT_COLUMNS = 'id, name, title, active, theme, theme_port, created_at, updated_at, created_by';

function event_find(int $id): ?array
{
    $st = db()->prepare('SELECT ' . EVENT_COLUMNS . ' FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Eventos activos, en el orden en que se crearon (página principal). */
function events_active(): array
{
    return db()->query('SELECT ' . EVENT_COLUMNS . ' FROM events WHERE active = 1 ORDER BY id')->fetchAll();
}

/** Texto del evento en la página principal. */
function event_label(array $ev): string
{
    return (string)($ev['title'] !== '' ? $ev['title'] : $ev['name']);
}

/** Evento activo que usaba un prefijo de las direcciones anteriores (p. ej. "bbm-t"). */
function event_by_old_prefix(string $prefix): ?int
{
    $st = db()->prepare("SELECT id FROM events WHERE old_prefix <> '' AND old_prefix = ? COLLATE NOCASE AND active = 1 ORDER BY id LIMIT 1");
    $st->execute([$prefix]);
    return (int)$st->fetchColumn() ?: null;
}

/** Todos los eventos para el panel (los más nuevos primero). */
function events_list(): array
{
    return array_map(static fn($e) => [
        'id' => (int)$e['id'], 'name' => $e['name'], 'title' => $e['title'], 'active' => (int)$e['active'] === 1,
        'theme' => event_theme($e), 'theme_port' => event_theme_port($e),
        'created_at' => (int)$e['created_at'], 'updated_at' => (int)$e['updated_at'], 'created_by' => $e['created_by'],
    ], db()->query('SELECT ' . EVENT_COLUMNS . ' FROM events ORDER BY id DESC')->fetchAll());
}

/** Crea un evento desactivado, vacío o con los tablets y temas de otro. Devuelve su id. */
function event_create(string $name, string $by, ?int $copyId = null): int
{
    $src = $copyId ? event_find($copyId) : null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO events(name, data, created_at, updated_at, created_by, active, title, theme, theme_port) VALUES(?,?,?,?,?,0,?,?,?)')
            ->execute([$name, '{}', time(), time(), $by, $name, $src ? event_theme($src) : 'light', $src ? event_theme_port($src) : 'light']);
        $id = (int)$pdo->lastInsertId();
        if ($src) {
            $st = $pdo->prepare('SELECT * FROM tablets WHERE event_id = ? ORDER BY number');
            $st->execute([(int)$src['id']]);
            $insT = $pdo->prepare('INSERT INTO tablets(event_id, number, label, active, updated_at) VALUES(?,?,?,?,?)');
            $insI = $pdo->prepare('INSERT INTO tablet_items(tablet_id, product_id, sort, arrow, photo_media_id, lang1, lang2, is_default)
                                   VALUES(?,?,?,?,?,?,?,?)');
            foreach ($st->fetchAll() as $t) {
                $insT->execute([$id, (int)$t['number'], $t['label'], (int)$t['active'], time()]);
                $tid = (int)$pdo->lastInsertId();
                foreach (tablet_items((int)$t['id']) as $it) {
                    $insI->execute([$tid, $it['product_id'], $it['sort'], $it['arrow'], $it['photo_media_id'], $it['lang1'], $it['lang2'], $it['is_default']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** Guarda el título y los temas de un evento. */
function event_update(int $id, string $title, array $themes): void
{
    db()->prepare('UPDATE events SET title = ?, theme = ?, theme_port = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, event_theme($themes), event_theme_port($themes), time(), $id]);
}
