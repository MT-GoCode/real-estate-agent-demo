<?php
// One SQLite connection for the whole app. houses.db is the main file; the other three
// are attached by name, so a query says owners.owners, chats.chats or users.users.

// Where the four database files live. Tests point DATA_DIR at a scratch folder.
function data_dir(): string { return getenv('DATA_DIR') ?: __DIR__ . '/data'; }

function db(): PDO
{
    static $db = null;
    if ($db) return $db;

    $dir = data_dir();
    $db = new PDO("sqlite:$dir/houses.db", options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');   // a writer waits up to 5 s for another one; a save takes milliseconds
    foreach (['owners', 'chats', 'users'] as $name) $db->exec("ATTACH '$dir/$name.db' AS $name");
    foreach (['main', 'owners', 'chats', 'users'] as $name) $db->exec("PRAGMA $name.journal_mode = WAL");   // readers never wait for a writer

    $db->exec('CREATE TABLE IF NOT EXISTS users.users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL)');
    $db->exec("CREATE TABLE IF NOT EXISTS chats.chats (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL,
        messages TEXT NOT NULL DEFAULT '[]',     -- the exact message list sent to Claude
        cost_usd REAL NOT NULL DEFAULT 0, created_at TEXT NOT NULL)");
    return $db;
}
