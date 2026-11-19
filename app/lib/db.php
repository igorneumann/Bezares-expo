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
    if ($ver >= 1) {
        return;
    }
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
