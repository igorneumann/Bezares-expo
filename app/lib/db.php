<?php
declare(strict_types=1);

defined('APP_DIR') || exit; // no ejecutable directamente

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $file = DATA_DIR . '/expo.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    @chmod($file, 0640);
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 5) {
        return;
    }
    if ($ver > 0) {
        // Copia de la base de datos antes de cambiar su estructura (no se puede dentro de la transacción).
        $copy = DATA_DIR . '/expo-antes-v5.sqlite';
        try {
            if (!is_file($copy)) {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
                @chmod($copy, 0640);
            }
        } catch (Throwable $e) {
            error_log('Copia previa a la migración: ' . $e->getMessage());
        }
    }
    // Bloqueo de escritura: evita que dos peticiones simultáneas migren a la vez.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        db_migrate_steps($pdo);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function db_migrate_steps(PDO $pdo): void
{
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver < 1) {
        db_migrate_v1($pdo);
    }
    if ($ver < 2) {
        // v2: roles, versión de sesión (invalidar sesiones al cambiar contraseña), uso de subidas.
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
        $pdo->exec('ALTER TABLE users ADD COLUMN session_ver INTEGER NOT NULL DEFAULT 1');
        $pdo->exec('CREATE TABLE IF NOT EXISTS upload_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            bytes INTEGER NOT NULL,
            ts INTEGER NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS upload_usage_user ON upload_usage(user_id, ts)');
        $pdo->exec('PRAGMA user_version = 2');
    }
    if ($ver < 3) {
        // v3: eventos (instantáneas de la configuración de tablets para guardar/restaurar).
        $pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            data TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            created_by TEXT NOT NULL DEFAULT \'\'
        )');
        $pdo->exec('PRAGMA user_version = 3');
    }
    if ($ver < 4) {
        // v4: estadísticas de interacción de los tablets (id único generado en el tablet: sin duplicados).
        $pdo->exec('CREATE TABLE IF NOT EXISTS stats (
            id TEXT PRIMARY KEY,
            event_id INTEGER,
            tablet INTEGER NOT NULL,
            product_id INTEGER,
            type TEXT NOT NULL,
            lang TEXT NOT NULL DEFAULT \'\',
            ts INTEGER NOT NULL,
            day TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS stats_event ON stats(event_id, tablet, type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS stats_day ON stats(event_id, day)');
        $pdo->exec('PRAGMA user_version = 4');
    }
    if ($ver < 5) {
        db_migrate_v5($pdo);
    }
}

/**
 * v5: varios eventos activos a la vez. Los eventos dejan de ser copias que se restauran: cada uno
 * tiene sus propios tablets y temas. La configuración en uso pasa a la feria en curso, que queda
 * activa; los demás eventos guardados se conservan, desactivados. Las copias originales siguen en events.data.
 */
function db_migrate_v5(PDO $pdo): void
{
    $set = $pdo->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $events = $pdo->query('SELECT * FROM events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $products = $pdo->query('SELECT id, short_name FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
    $photos = array_flip($pdo->query("SELECT id FROM media WHERE kind = 'photo'")->fetchAll(PDO::FETCH_COLUMN));
    $langs = array_flip($pdo->query('SELECT code FROM languages')->fetchAll(PDO::FETCH_COLUMN));
    $now = time();
    $theme = static fn(mixed $t, string $def): string => in_array($t, ['light', 'dark', 'cards', 'cards-dark'], true) ? $t : $def;

    // Tablets en uso: [id, number, label, active, items => [[product, arrow, photo, lang1, lang2, default], …]]
    $live = [];
    foreach ($pdo->query('SELECT * FROM tablets ORDER BY number')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $live[(int)$t['id']] = ['id' => (int)$t['id'], 'number' => (int)$t['number'], 'label' => (string)$t['label'],
                                'active' => (int)$t['active'], 'items' => []];
    }
    foreach ($pdo->query('SELECT * FROM tablet_items ORDER BY tablet_id, sort, id')->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $live[(int)$i['tablet_id']]['items'][] = [(int)$i['product_id'], (string)$i['arrow'],
            $i['photo_media_id'] !== null ? (int)$i['photo_media_id'] : null, (string)$i['lang1'], (string)$i['lang2'], (int)$i['is_default']];
    }
    $live = array_values($live);

    // Tablets de un evento guardado (los productos o fotos que ya no existen se omiten)
    $byName = [];
    foreach ($products as $pid => $name) {
        $byName[mb_strtolower((string)$name)] = (int)$pid;
    }
    $saved = static function (array $data) use ($products, $byName, $photos, $langs): array {
        $out = [];
        foreach (is_array($data['tablets'] ?? null) ? $data['tablets'] : [] as $t) {
            $num = (int)($t['number'] ?? 0);
            if ($num < 1 || $num > 999 || isset($out[$num])) {
                continue;
            }
            $items = [];
            $hasDefault = false;
            foreach (array_slice(is_array($t['items'] ?? null) ? $t['items'] : [], 0, 5) as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                if (!isset($products[$pid])) {
                    $pid = $byName[mb_strtolower((string)($it['product_name'] ?? ''))] ?? 0;
                }
                if (!$pid) {
                    continue;
                }
                $photo = (int)($it['photo_media_id'] ?? 0);
                $def = !empty($it['is_default']) && !$hasDefault ? 1 : 0;
                $hasDefault = $hasDefault || $def === 1;
                $items[] = [$pid, in_array($it['arrow'] ?? '', ['none', 'left', 'right', 'up'], true) ? $it['arrow'] : 'none',
                    isset($photos[$photo]) ? $photo : null,
                    isset($langs[(string)($it['lang1'] ?? '')]) ? (string)$it['lang1'] : 'en',
                    isset($langs[(string)($it['lang2'] ?? '')]) ? (string)$it['lang2'] : '', $def];
            }
            if ($items && !$hasDefault) {
                $items[0][5] = 1;
            }
            $out[$num] = ['id' => null, 'number' => $num, 'label' => mb_substr((string)($t['label'] ?? ''), 0, 80),
                          'active' => !empty($t['active']) && count($items) >= 2 ? 1 : 0, 'items' => $items];
        }
        ksort($out);
        return array_values($out);
    };
    $plain = static fn(array $tablets): array => array_map(static fn($t) => ['id' => null] + $t, $tablets);

    $pdo->exec('DROP TABLE tablet_items');
    $pdo->exec('DROP TABLE tablets');
    $pdo->exec('CREATE TABLE tablets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
        number INTEGER NOT NULL,
        label TEXT NOT NULL DEFAULT \'\',
        active INTEGER NOT NULL DEFAULT 1,
        updated_at INTEGER NOT NULL,
        UNIQUE (event_id, number)
    )');
    $pdo->exec("CREATE TABLE tablet_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tablet_id INTEGER NOT NULL REFERENCES tablets(id) ON DELETE CASCADE,
        product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        sort INTEGER NOT NULL DEFAULT 0,
        arrow TEXT NOT NULL DEFAULT 'none' CHECK (arrow IN ('none','left','right','up')),
        photo_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
        lang1 TEXT NOT NULL DEFAULT 'en',
        lang2 TEXT NOT NULL DEFAULT 'es',
        is_default INTEGER NOT NULL DEFAULT 0
    )");
    foreach (["active INTEGER NOT NULL DEFAULT 0", "title TEXT NOT NULL DEFAULT ''", "theme TEXT NOT NULL DEFAULT 'light'",
              "theme_port TEXT NOT NULL DEFAULT 'light'", "old_prefix TEXT NOT NULL DEFAULT ''"] as $col) {
        $pdo->exec('ALTER TABLE events ADD COLUMN ' . $col);
    }

    $insE = $pdo->prepare('INSERT INTO events(name, data, created_at, updated_at, created_by) VALUES(?,?,?,?,?)');
    $updE = $pdo->prepare('UPDATE events SET active = ?, title = ?, theme = ?, theme_port = ?, old_prefix = ? WHERE id = ?');
    $insT = $pdo->prepare('INSERT INTO tablets(id, event_id, number, label, active, updated_at) VALUES(?,?,?,?,?,?)');
    $insI = $pdo->prepare('INSERT INTO tablet_items(tablet_id, product_id, sort, arrow, photo_media_id, lang1, lang2, is_default) VALUES(?,?,?,?,?,?,?,?)');
    $store = static function (int $eventId, array $tablets) use ($pdo, $insT, $insI, $now): void {
        foreach ($tablets as $t) {
            $insT->execute([$t['id'], $eventId, $t['number'], $t['label'], $t['active'], $now]);
            $tid = $t['id'] ?? (int)$pdo->lastInsertId();
            foreach ($t['items'] as $k => [$pid, $arrow, $photo, $l1, $l2, $def]) {
                $insI->execute([$tid, $pid, $k, $arrow, $photo, $l1, $l2, $def]);
            }
        }
    };

    // Evento al que pertenece lo que hay en uso: la feria en curso; si no, uno guardado idéntico; si no, uno nuevo.
    $title = (string)($set['event_title'] ?? '');
    $active = (int)($set['active_event_id'] ?? 0);
    $current = null;
    foreach ($events as $k => $ev) {
        $data = json_decode((string)$ev['data'], true);
        $events[$k]['data'] = is_array($data) ? $data : [];
        $events[$k]['tablets'] = $saved($events[$k]['data']);
        if ((int)$ev['id'] === $active) {
            $current = $k;
        }
    }
    if ($current === null && $live) {
        foreach ($events as $k => $ev) {
            if ($ev['tablets'] == $plain($live)) {
                $current = $k;
            }
        }
    }
    if ($current === null) {
        $insE->execute([$title !== '' ? $title : 'Evento', '{}', $now, $now, '']);
        $currentId = (int)$pdo->lastInsertId();
    } else {
        $currentId = (int)$events[$current]['id'];
    }
    $land = $theme($set['theme'] ?? null, 'light');
    $updE->execute([1, $title, $land, $theme($set['theme_port'] ?? null, $land), (string)($set['url_prefix'] ?? ''), $currentId]);
    $store($currentId, $live); // primero, porque conserva los id de los tablets

    foreach ($events as $k => $ev) {
        $data = $ev['data'];
        $eid = (int)$ev['id'];
        if ($k === $current) {
            // Si lo guardado era distinto de lo que está en uso, se conserva como otro evento desactivado.
            if (!$ev['tablets'] || $ev['tablets'] == $plain($live)) {
                continue;
            }
            $insE->execute([mb_substr($ev['name'], 0, 100) . ' (copia guardada)', json_encode($data, JSON_UNESCAPED_UNICODE),
                            (int)$ev['created_at'], (int)$ev['updated_at'], (string)$ev['created_by']]);
            $eid = (int)$pdo->lastInsertId();
        }
        $land = $theme($data['theme'] ?? null, 'light');
        $updE->execute([0, is_string($data['event_title'] ?? null) && $data['event_title'] !== '' ? $data['event_title'] : (string)$ev['name'],
                        $land, $theme($data['theme_port'] ?? null, $land), '', $eid]);
        $store($eid, $ev['tablets']);
    }
    $pdo->exec("DELETE FROM settings WHERE k IN ('event_title', 'url_prefix', 'display_prefix', 'theme', 'theme_port', 'active_event_id')");
    // Los tablets y los navegadores deben pedir las páginas otra vez.
    $pdo->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute(['content_ver', (string)$now . '5']);
    $pdo->exec('PRAGMA user_version = 5');
}

function db_migrate_v1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT NOT NULL COLLATE NOCASE,
    pass_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_login INTEGER
);
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    expires INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires INTEGER NOT NULL,
    used INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS throttle (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS throttle_bucket ON throttle(bucket, ts);

CREATE TABLE IF NOT EXISTS languages (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK (kind IN ('video','model','photo')),
    path TEXT NOT NULL UNIQUE,
    source_path TEXT,
    name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0,
    width INTEGER,
    height INTEGER,
    mtime INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'ready',
    settings TEXT NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    short_name TEXT NOT NULL,
    video_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
    video_url TEXT,
    model_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
    photo_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS product_i18n (
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    lang TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    features TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (product_id, lang)
);
CREATE TABLE IF NOT EXISTS product_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    size TEXT NOT NULL DEFAULT 'medium',
    sort INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS tablets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number INTEGER NOT NULL UNIQUE,
    label TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS tablet_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tablet_id INTEGER NOT NULL REFERENCES tablets(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sort INTEGER NOT NULL DEFAULT 0,
    arrow TEXT NOT NULL DEFAULT 'none' CHECK (arrow IN ('none','left','right','up')),
    photo_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
    lang1 TEXT NOT NULL DEFAULT 'en',
    lang2 TEXT NOT NULL DEFAULT 'es',
    is_default INTEGER NOT NULL DEFAULT 0
);
SQL);

    $langs = [['en', 'English', 0], ['es', 'Español', 1], ['de', 'Deutsch', 2], ['fr', 'Français', 3],
              ['it', 'Italiano', 4], ['pt', 'Português', 5]];
    $st = $pdo->prepare('INSERT OR IGNORE INTO languages(code,name,sort) VALUES(?,?,?)');
    foreach ($langs as $l) {
        $st->execute($l);
    }
    $defaults = [
        'event_title'     => 'BBM 2026',
        'url_prefix'      => 'bbm-t',
        'display_prefix'  => 'BBM-t',
        'site_title'      => 'Bezares SA – New products and developments',
        'header_media_id' => '',
        'logo_media_id'   => '',
        'device_token'    => random_token(24),
        'access_code_hash'=> '',
        'openai_key'      => '',
        'openai_model'    => 'gpt-4o-mini',
        'mail_from'       => 'no-reply@bezares.com',
        'content_ver'     => (string)time(),
        'installed'       => '0',
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings(k,v) VALUES(?,?)');
    foreach ($defaults as $k => $v) {
        $st->execute([$k, $v]);
    }
    $pdo->exec('PRAGMA user_version = 1');
}

function setting(string $k, mixed $default = null): mixed
{
    static $cache = null;
    if ($k === '__reset__') {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
    setting('__reset__');
}

/** Limitador de intentos genérico. Devuelve true si se ha superado el límite. */
function throttle_exceeded(string $bucket, int $max, int $windowSec): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM throttle WHERE ts < ?')->execute([time() - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND ts > ?');
    $st->execute([$bucket, time() - $windowSec]);
    return (int)$st->fetchColumn() >= $max;
}

function throttle_hit(string $bucket): void
{
    db()->prepare('INSERT INTO throttle(bucket, ts) VALUES(?, ?)')->execute([$bucket, time()]);
}

function throttle_clear(string $bucket): void
{
    db()->prepare('DELETE FROM throttle WHERE bucket = ?')->execute([$bucket]);
}

function languages(): array
{
    return db()->query('SELECT code, name FROM languages ORDER BY sort, name')->fetchAll();
}
