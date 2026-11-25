<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

/*
 * Estadísticas de uso de los tablets.
 * Cada tablet guarda sus interacciones en local (funciona sin conexión) y las envía por lotes
 * a /track.php. Cada registro lleva un id único generado en el tablet, así un reenvío no duplica.
 * Se asignan al evento del tablet que las envía.
 */

// Tipos registrados
const STAT_TYPES = ['view', 'wake', 'product', 'swipe', 'more_info', 'newsletter', 'video_fs', 'model_fs', 'lang', 'model_touch'];
// Lo que cuenta como "interacción" (acciones del visitante; las vistas y el despertar se muestran aparte)
const STAT_INTERACTIONS = ['product', 'swipe', 'more_info', 'newsletter', 'video_fs', 'model_fs', 'lang', 'model_touch'];

/** Guarda un lote de registros validados. Devuelve los ids aceptados (nuevos o ya existentes). */
function stats_record(array $events): array
{
    $pdo = db();
    $validEvents = array_flip(array_map('intval', $pdo->query('SELECT id FROM events')->fetchAll(PDO::FETCH_COLUMN)));
    $validTablets = array_flip(array_map('intval', $pdo->query('SELECT number FROM tablets')->fetchAll(PDO::FETCH_COLUMN)));
    $ins = $pdo->prepare('INSERT OR IGNORE INTO stats(id, event_id, tablet, product_id, type, lang, ts, day) VALUES(?,?,?,?,?,?,?,?)');
    $now = time();
    $accepted = [];
    $pdo->beginTransaction();
    foreach (array_slice($events, 0, 500) as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = (string)($e['id'] ?? '');
        $type = (string)($e['t'] ?? '');
        $tablet = (int)($e['tab'] ?? 0);
        if (!preg_match('/^[a-f0-9]{16,40}$/D', $id) || !in_array($type, STAT_TYPES, true) || !isset($validTablets[$tablet])) {
            continue;
        }
        $ev = isset($e['ev']) && isset($validEvents[(int)$e['ev']]) ? (int)$e['ev'] : null;
        $pid = (int)($e['p'] ?? 0) ?: null;
        $lang = preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/D', (string)($e['l'] ?? '')) ? (string)$e['l'] : '';
        // Hora del tablet (puede llegar tarde si estuvo sin conexión); si es absurda, la del servidor.
        $ts = (int)round(((float)($e['ts'] ?? 0)) / 1000);
        if ($ts < $now - 60 * 86400 || $ts > $now + 600) {
            $ts = $now;
        }
        $ins->execute([$id, $ev, $tablet, $pid, $type, $lang, $ts, date('Y-m-d', $ts)]);
        $accepted[] = $id;
    }
    $pdo->commit();
    return $accepted;
}

/**
 * Resumen para el panel.
 * $event: id de feria, 'none' (sin feria asignada) o 'all'. $from / $to: 'YYYY-MM-DD' opcionales.
 */
function stats_summary(int|string $event, ?string $from, ?string $to): array
{
    $where = [];
    $args = [];
    if ($event === 'none') {
        $where[] = 'event_id IS NULL';
    } elseif ($event !== 'all') {
        $where[] = 'event_id = ?';
        $args[] = (int)$event;
    }
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from)) {
        $where[] = 'day >= ?';
        $args[] = $from;
    }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)) {
        $where[] = 'day <= ?';
        $args[] = $to;
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $q = static function (string $sql) use ($args): array {
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    };
    $inList = "'" . implode("','", STAT_INTERACTIONS) . "'"; // constantes internas, no datos del usuario

    $totals = array_fill_keys(STAT_TYPES, 0);
    foreach ($q("SELECT type, COUNT(*) c FROM stats$w GROUP BY type") as $r) {
        $totals[$r['type']] = (int)$r['c'];
    }
    $byTablet = [];
    foreach ($q("SELECT event_id, tablet, type, COUNT(*) c FROM stats$w GROUP BY event_id, tablet, type ORDER BY event_id, tablet") as $r) {
        $k = (int)$r['event_id'] . ':' . (int)$r['tablet'];
        $byTablet[$k] ??= ['event' => $r['event_id'] !== null ? (int)$r['event_id'] : null, 'tablet' => (int)$r['tablet']] + array_fill_keys(STAT_TYPES, 0);
        $byTablet[$k][$r['type']] = (int)$r['c'];
    }
    $byProduct = [];
    // Mismo filtro con las columnas de la tabla "s" (la consulta une stats con products)
    $sWhere = array_map(static fn($c) => 's.' . $c, $where);
    $sWhere[] = 's.product_id IS NOT NULL';
    foreach ($q("SELECT s.product_id, COALESCE(p.short_name, '¿?') name,
                    SUM(s.type = 'view') views, SUM(s.type IN ($inList)) inter,
                    SUM(s.type = 'more_info') more_info, SUM(s.type = 'newsletter') newsletter
                 FROM stats s LEFT JOIN products p ON p.id = s.product_id
                 WHERE " . implode(' AND ', $sWhere) . "
                 GROUP BY s.product_id ORDER BY inter DESC, views DESC LIMIT 50") as $r) {
        $byProduct[] = ['id' => (int)$r['product_id'], 'name' => $r['name'], 'views' => (int)$r['views'], 'interactions' => (int)$r['inter'],
                        'more_info' => (int)$r['more_info'], 'newsletter' => (int)$r['newsletter']];
    }
    $byDay = [];
    foreach ($q("SELECT day, SUM(type IN ($inList)) inter, SUM(type = 'view') views, SUM(type = 'newsletter') nl, SUM(type = 'wake') wake
                 FROM stats$w GROUP BY day ORDER BY day") as $r) {
        $byDay[] = ['day' => $r['day'], 'interactions' => (int)$r['inter'], 'views' => (int)$r['views'], 'newsletter' => (int)$r['nl'], 'wake' => (int)$r['wake']];
    }
    $byHour = array_fill(0, 24, 0);
    foreach ($q("SELECT ts FROM stats$w" . ($w ? ' AND' : ' WHERE') . " type IN ($inList)") as $r) {
        $byHour[(int)date('G', (int)$r['ts'])]++;
    }
    $byLang = [];
    foreach ($q("SELECT lang, COUNT(*) c FROM stats$w" . ($w ? ' AND' : ' WHERE') . " type = 'view' AND lang <> '' GROUP BY lang ORDER BY c DESC") as $r) {
        $byLang[$r['lang']] = (int)$r['c'];
    }
    $range = $q("SELECT MIN(ts) a, MAX(ts) b FROM stats$w")[0] ?? ['a' => null, 'b' => null];
    $interactions = array_sum(array_intersect_key($totals, array_flip(STAT_INTERACTIONS)));

    return [
        'totals' => $totals, 'interactions' => $interactions,
        'by_tablet' => array_values($byTablet), 'by_product' => $byProduct, 'by_day' => $byDay, 'by_hour' => $byHour, 'by_lang' => $byLang,
        'first' => $range['a'] !== null ? (int)$range['a'] : null, 'last' => $range['b'] !== null ? (int)$range['b'] : null,
    ];
}
