<?php
// The web server. Every request to the site comes to this file (see Caddyfile).
// The request's path decides what happens. The list of routes is at the bottom.

ini_set('display_errors', '0');   // a PHP warning must never end up inside a chat
ini_set('log_errors', '1');       // ...it goes to the server log instead
require __DIR__ . '/agent.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');   // the map tile server requires a Referer

if (!is_dir(data_dir() . '/sessions')) mkdir(data_dir() . '/sessions');
session_start([
    'save_path' => data_dir() . '/sessions',
    'cookie_lifetime' => 30 * 86400, 'gc_maxlifetime' => 30 * 86400,   // stay logged in for a month
    'cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true,
]);

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fail(int $status, string $message): never { reply(['error' => $message], $status); }

function page(string $file, string $type): never   // one of the four files in frontend/
{
    header("Content-Type: $type");
    readfile(__DIR__ . "/frontend/$file");
    exit;
}

function log_in(): never
{
    $name = trim($_POST['username'] ?? '');
    if (mb_strlen($name) < 1 || mb_strlen($name) > 40) fail(400, 'Enter a username of 1 to 40 characters.');
    db()->prepare("INSERT OR IGNORE INTO users.users (username, created_at) VALUES (?, datetime('now'))")->execute([$name]);
    $q = db()->prepare('SELECT id FROM users.users WHERE username = ?');
    $q->execute([$name]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $q->fetchColumn();
    header('Location: /', true, 303);
    exit;
}

function log_out(): never
{
    session_destroy();
    header('Location: /', true, 303);
    exit;
}

function list_chats(int $user): array
{
    $q = db()->prepare('SELECT id, title FROM chats.chats WHERE user_id = ? ORDER BY id');
    $q->execute([$user]);
    return $q->fetchAll();
}

function list_houses(): array   // every house, with its full address, for the map
{
    $q = db()->prepare("SELECT id, address || ', ' || ? AS address, lat, lon FROM properties");
    $q->execute([CITY]);
    return $q->fetchAll();
}

function new_chat(int $user): array
{
    $title = 'chat' . (count(list_chats($user)) + 1);
    db()->prepare("INSERT INTO chats.chats (user_id, title, created_at) VALUES (?, ?, datetime('now'))")->execute([$user, $title]);
    return ['id' => (int) db()->lastInsertId(), 'title' => $title];
}

function chat_of(int $user, int $id): array   // the chat, but only if it belongs to this user
{
    $q = db()->prepare('SELECT * FROM chats.chats WHERE id = ? AND user_id = ?');
    $q->execute([$id, $user]);
    return $q->fetch() ?: fail(404, 'No such chat.');
}

function open_chat(int $user, int $id): array
{
    $chat = chat_of($user, $id);
    return ['messages' => json_decode($chat['messages'], true), 'cost_usd' => $chat['cost_usd'], 'model' => MODEL_NAME];
}

// Saves the chat, but only if it still has the $had messages we started from: otherwise another
// tab saved it in the meantime, and saving would erase that. Returns the chat's total cost so far.
function save(int $id, array $messages, int $had, float $cost): float
{
    $q = db()->prepare('UPDATE chats.chats SET messages = ?, cost_usd = cost_usd + ?
                        WHERE id = ? AND json_array_length(messages) = CAST(? AS INTEGER) RETURNING cost_usd');   // CAST: PDO sends numbers as text
    $q->execute([json_encode($messages), $cost, $id, $had]);
    $total = $q->fetchColumn();
    if ($total === false) throw new RuntimeException('This chat was changed in another tab. Reload the page to see it.');
    return $total;
}

// One line of the live chat feed. Its type says what it is: a message, the chat's cost so far, or an error.
function stream(array $line): void
{
    echo json_encode($line), "\n";
    flush();
}

// The user sent a message: run the agent, and stream every new message to the browser as it is saved.
function send(int $user, int $id): never
{
    $chat = chat_of($user, $id);
    $text = json_decode(file_get_contents('php://input'), true)['text'] ?? null;
    if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 10000) fail(400, 'Send a message of 1 to 10,000 characters.');

    session_write_close();       // the session is not needed any more, so the user's other requests can go ahead
    ignore_user_abort(true);     // finish the turn even if the browser tab closes
    set_time_limit(0);           // a turn takes as long as Claude and the tools take
    header('Content-Type: application/x-ndjson');

    $messages = json_decode($chat['messages'], true);
    $messages[] = ['role' => 'user', 'content' => trim($text)];
    try {
        save($id, $messages, count($messages) - 1, 0);
        stream(['type' => 'message', 'message' => array_last($messages)]);
        run_agent($messages, function (array $new, array $usage) use ($id, &$messages) {
            $total = save($id, $messages, count($messages) - count($new), price($usage));
            foreach ($new as $message) stream(['type' => 'message', 'message' => $message]);
            stream(['type' => 'cost', 'cost' => $total, 'model' => MODEL_NAME]);
        });
    } catch (Throwable $e) {
        stream(['type' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}


// ---- routes: the first line that matches answers the request ----
$user = $_SESSION['user_id'] ?? null;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($path === '/')                                                page($user ? 'app.html' : 'login.html', 'text/html; charset=utf-8');
if ($path === '/frontend/app.js')                                 page('app.js', 'text/javascript');
if ($path === '/frontend/style.css')                              page('style.css', 'text/css');
if ($path === '/login' && $post)                                  log_in();
if ($path === '/logout' && $post)                                 log_out();
if (!$user)                                                       fail(401, 'Log in first.');
if ($path === '/api/houses')                                      reply(list_houses());
if ($path === '/api/chats' && $post)                              reply(new_chat($user));
if ($path === '/api/chats')                                       reply(list_chats($user));
if (preg_match('#^/api/chats/(\d+)$#', $path, $m))                reply(open_chat($user, (int) $m[1]));
if (preg_match('#^/api/chats/(\d+)/send$#', $path, $m) && $post)  send($user, (int) $m[1]);
fail(404, 'Not found.');
