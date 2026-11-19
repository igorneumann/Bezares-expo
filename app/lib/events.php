<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Eventos: instantáneas de la configuración de tablets (qué tablets, activos, productos,
 * orden, flechas, fotos e idiomas) más el título del evento. Se pueden restaurar más tarde.
 * Los productos y medios no se copian: se referencian por id (y por nombre como respaldo).
 */

function events_snapshot(): array
{
    $tablets = [];
    foreach (db()->query('SELECT * FROM tablets ORDER BY number')->fetchAll() as $t) {
        $items = [];
        foreach (tablet_items((int)$t['id']) as $it) {
            $items[] = [
                'product_id'     => (int)$it['product_id'],
                'product_name'   => $it['short_name'],
                'arrow'          => $it['arrow'],
                'photo_media_id' => $it['photo_media_id'] !== null ? (int)$it['photo_media_id'] : null,
                'lang1'          => $it['lang1'],
                'lang2'          => $it['lang2'],
                'is_default'     => (int)$it['is_default'],
            ];
        }
        $tablets[] = ['number' => (int)$t['number'], 'label' => $t['label'], 'active' => (int)$t['active'], 'items' => $items];
    }
    return ['version' => 1, 'event_title' => (string)setting('event_title', ''), 'tablets' => $tablets];
}

function events_summary(array $data): array
{
    $tablets = is_array($data['tablets'] ?? null) ? $data['tablets'] : [];
    $active = array_filter($tablets, static fn($t) => !empty($t['active']));
    return [
        'event_title' => (string)($data['event_title'] ?? ''),
        'tablets'     => count($tablets),
        'active'      => count($active),
        'products'    => array_sum(array_map(static fn($t) => count($t['items'] ?? []), $active)),
        'detail'      => array_values(array_map(static fn($t) => [
            'number'   => (int)($t['number'] ?? 0),
            'active'   => !empty($t['active']),
            'products' => array_map(static fn($i) => (string)($i['product_name'] ?? ''), $t['items'] ?? []),
        ], $tablets)),
    ];
}

function events_list(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM events ORDER BY updated_at DESC')->fetchAll() as $e) {
        $data = json_decode($e['data'], true);
        $out[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'created_at' => (int)$e['created_at'],
                  'updated_at' => (int)$e['updated_at'], 'created_by' => $e['created_by'],
                  'summary' => events_summary(is_array($data) ? $data : [])];
    }
    return $out;
}

function events_save(string $name, string $by, ?int $overwriteId = null): int
{
    $json = json_encode(events_snapshot(), JSON_UNESCAPED_UNICODE);
    if ($overwriteId) {
        $st = db()->prepare('UPDATE events SET data = ?, updated_at = ?, name = ? WHERE id = ?');
        $st->execute([$json, time(), $name, $overwriteId]);
        if ($st->rowCount()) {
            return $overwriteId;
        }
    }
    db()->prepare('INSERT INTO events(name, data, created_at, updated_at, created_by) VALUES(?,?,?,?,?)')
        ->execute([$name, $json, time(), time(), $by]);
    return (int)db()->lastInsertId();
}

/** Restaura un evento. Devuelve la lista de avisos (productos o fotos que ya no existen). */
function events_restore(int $id): array
{
    $st = db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) {
        throw new RuntimeException('Evento no encontrado.');
    }
    $data = json_decode($e['data'], true);
    if (!is_array($data) || !is_array($data['tablets'] ?? null)) {
        throw new RuntimeException('Los datos del evento están dañados.');
    }

    $pdo = db();
    $products = [];
    $byName = [];
    foreach ($pdo->query('SELECT id, short_name FROM products') as $p) {
        $products[(int)$p['id']] = $p['short_name'];
        $byName[mb_strtolower($p['short_name'])] = (int)$p['id'];
    }
    $photoOk = $pdo->prepare("SELECT 1 FROM media WHERE id = ? AND kind = 'photo'");
    $langs = array_column(languages(), 'code');
    $warnings = [];

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM tablets'); // tablet_items se borran en cascada
        $insT = $pdo->prepare('INSERT INTO tablets(number, label, active, updated_at) VALUES(?,?,?,?)');
        $insI = $pdo->prepare('INSERT INTO tablet_items(tablet_id, product_id, sort, arrow, photo_media_id, lang1, lang2, is_default) VALUES(?,?,?,?,?,?,?,?)');
        $seen = [];
        foreach ($data['tablets'] as $t) {
            $num = (int)($t['number'] ?? 0);
            if ($num < 1 || $num > 999 || isset($seen[$num])) {
                continue;
            }
            $seen[$num] = true;
            $items = [];
            foreach (array_slice(is_array($t['items'] ?? null) ? $t['items'] : [], 0, 5) as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $pname = (string)($it['product_name'] ?? '');
                if (!isset($products[$pid])) {
                    // Respaldo: buscar por nombre reducido (p. ej. si el producto se volvió a crear).
                    $pid = $byName[mb_strtolower($pname)] ?? 0;
                    if (!$pid) {
                        $warnings[] = "Tablet {$num}: el producto \"{$pname}\" ya no existe y se ha omitido.";
                        continue;
                    }
                }
                $photo = isset($it['photo_media_id']) ? (int)$it['photo_media_id'] : null;
                if ($photo) {
                    $photoOk->execute([$photo]);
                    if (!$photoOk->fetchColumn()) {
                        $warnings[] = "Tablet {$num}: la foto del índice de \"{$pname}\" ya no existe; se usa la del producto.";
                        $photo = null;
                    }
                }
                $l1 = in_array($it['lang1'] ?? '', $langs, true) ? $it['lang1'] : 'en';
                $l2 = in_array($it['lang2'] ?? '', $langs, true) ? $it['lang2'] : '';
                $arrow = in_array($it['arrow'] ?? '', ['none', 'left', 'right', 'up'], true) ? $it['arrow'] : 'none';
                $items[] = [$pid, $arrow, $photo, $l1, $l2, !empty($it['is_default']) ? 1 : 0];
            }
            $active = !empty($t['active']);
            if ($active && count($items) < 2) {
                $active = false;
                $warnings[] = "Tablet {$num}: queda inactivo porque tiene menos de 2 productos disponibles.";
            }
            if ($items && !array_sum(array_column($items, 5))) {
                $items[0][5] = 1;
            }
            $insT->execute([$num, mb_substr((string)($t['label'] ?? ''), 0, 80), $active ? 1 : 0, time()]);
            $tid = (int)$pdo->lastInsertId();
            $hasDefault = false;
            foreach ($items as $k => [$pid, $arrow, $photo, $l1, $l2, $def]) {
                $def = $def && !$hasDefault ? 1 : 0;
                $hasDefault = $hasDefault || $def;
                $insI->execute([$tid, $pid, $k, $arrow, $photo, $l1, $l2, $def]);
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    if (isset($data['event_title']) && is_string($data['event_title'])) {
        set_setting('event_title', mb_substr($data['event_title'], 0, 120));
    }
    bump_content_version();
    return $warnings;
}
