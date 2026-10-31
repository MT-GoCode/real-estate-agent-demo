<?php
// The one test suite.   All tests:  frankenphp php-cli test.php     One category:  frankenphp php-cli test.php tools
// Tests run on a scratch copy of the data (never data/), seeded fresh at the start.

$scratch = sys_get_temp_dir() . '/real-estate-agent-test-' . getmypid();   // one folder per run, removed at the end
mkdir($scratch);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($scratch)));
putenv("DATA_DIR=$scratch");
foreach (parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) as $key => $value) putenv("$key=$value");
require __DIR__ . '/agent.php';

$tests = [];
function test(string $category, string $name, Closure $body): void { global $tests; $tests[] = [$category, $name, $body]; }
function check(bool $ok, string $what): void { if (!$ok) throw new Exception($what); }
function throws(Closure $body, string $class, string $message): bool   // exactly this error, not just any error
{
    try { $body(); return false; }
    catch (Throwable $e) { return $e instanceof $class && str_contains($e->getMessage(), $message); }
}
function seed(): void { exec(PHP_BINARY . ' php-cli ' . escapeshellarg(__DIR__ . '/seed.php'), $out, $code); check($code === 0, 'seed.php failed: ' . implode("\n", $out)); }
function raw(string $sql, array $args = []): array { $q = db()->prepare($sql); $q->execute($args); return $q->fetchAll(); }
$houses = json_decode(file_get_contents(__DIR__ . '/data/westchester.json'), true);
seed();


// ---------- tools: the fake data and the four tools ----------

test('tools', 'seed_is_deterministic', function () use ($scratch) {
    $snapshot = function () use ($scratch) {   // a fresh connection each time: seed.php replaces the files
        $pdo = new PDO("sqlite:$scratch/houses.db");
        $pdo->exec("ATTACH '$scratch/owners.db' AS owners");
        $rows = [];
        foreach (['properties', 'mortgages', 'owners.owners', 'owners.phones'] as $t)
            $rows[$t] = $pdo->query("SELECT * FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        check(count($rows['properties']) > 0, 'seed produced no rows');
        return sha1(json_encode($rows));
    };
    seed(); $first = $snapshot();
    seed(); check($snapshot() === $first, 'two seeds produced different data');
});

test('tools', 'every_real_house_is_searchable', function () use ($houses) {
    check(count(raw('SELECT id FROM properties')) === count($houses), 'property count differs from westchester.json');
    foreach ($houses as $h) {
        $found = SearchProperty("$h[address], Los Angeles, CA 90045");
        check($found['address'] === $h['address'], "search for $h[address] returned $found[address]");
        $row = raw('SELECT apn, lat, lon, year_built, units FROM properties WHERE id = ?', [$found['id']])[0];
        check($row === ['apn' => $h['apn'], 'lat' => $h['lat'], 'lon' => $h['lon'], 'year_built' => $h['year_built'], 'units' => $h['units']],
            "real fields of $h[address] were changed");
    }
    $h = $houses[62];
    check(SearchProperty('  ' . strtoupper(str_replace(' ', '  ', $h['address'])))['address'] === $h['address'], 'search is not case/space tolerant');
});

test('tools', 'unknown_address_throws', fn() => check(throws(fn() => SearchProperty('1 Fake Street'), ToolError::class, 'No property found'), 'no error for a made-up address'));

test('tools', 'common_address_spellings_match', function () {
    foreach (['8311 Kenyon Ave' => '8311 Kenyon Avenue', '8311 kenyon ave., LA' => '8311 Kenyon Avenue',
              '6421 W 87th Pl' => '6421 West 87th Place', '6421 W. 87th St' => null] as $typed => $real) {
        if ($real === null) { check(throws(fn() => SearchProperty($typed), ToolError::class, 'No property found'), "$typed matched a house"); continue; }
        check(SearchProperty($typed)['address'] === $real, "$typed did not find $real");
    }
});

test('tools', 'detail_debt_and_equity', function () {
    $no_value = $two_loans = 0;
    foreach (raw('SELECT id, estimated_value FROM properties') as $p) {
        $loans = raw('SELECT balance FROM mortgages WHERE property_id = ?', [$p['id']]);
        $debt = 0; foreach ($loans as $l) $debt += $l['balance'];
        $d = PropertyDetail($p['id']);
        check($d['mortgage_count'] === count($loans) && count($d['mortgages']) === count($loans), "mortgage count wrong for $p[id]");
        check($d['total_mortgage_balance'] === $debt, "debt wrong for $p[id]");
        check($d['estimated_equity'] === ($p['estimated_value'] === null ? null : $p['estimated_value'] - $debt), "equity wrong for $p[id]");
        check(str_ends_with($d['address'], ', Los Angeles, CA 90045'), 'address lacks city');
        $no_value += $p['estimated_value'] === null;
        $two_loans += count($loans) === 2;
    }
    check($no_value > 0 && $two_loans > 0, 'data lacks a house with no value or one with two loans');
});

test('tools', 'comps_match_independent_distance', function () {
    $s = raw('SELECT lat, lon FROM properties WHERE id = 100')[0];
    $expected = [];
    foreach (raw('SELECT id, lat, lon FROM properties WHERE estimated_value IS NOT NULL') as $p) {
        [$a, $b, $dl] = [deg2rad($s['lat']), deg2rad($p['lat']), deg2rad($p['lon'] - $s['lon'])];
        $m = 6371000 * acos(min(1, sin($a) * sin($b) + cos($a) * cos($b) * cos($dl)));   // spherical law of cosines
        if ($m <= 75) $expected[$p['id']] = $m;
    }
    $r = PropertyComps($s['lat'], $s['lon'], 75);
    $ids = array_column($r['comps'], 'id'); sort($ids);
    $want = array_keys($expected); sort($want);
    check($ids === $want, 'comps set differs from independent distance');
    $d = array_column($r['comps'], 'distance_meters');
    check($d === array_map(fn($id) => round($expected[$id]), array_column($r['comps'], 'id')), 'distance_meters wrong');
    $sorted = $d; sort($sorted);
    check($d === $sorted, 'comps not nearest first');
    check($r['count'] === count($expected) && count($expected) >= 5, 'comps count wrong or too few to be a report');
    foreach ($r['comps'] as $c) check($c['estimated_value'] !== null, 'a comp has no value');
});

test('tools', 'owner_record', function () {
    $check_years = 0;
    foreach (raw('SELECT id, last_sale_date FROM properties WHERE id % 15 = 0') as $p) {
        $o = OwnerDetail($p['id']);
        check($o['years_owned'] === date_diff(date_create($p['last_sale_date']), date_create('today'))->y, "years_owned wrong for $p[id]");
        check(count($o['phones']) === count(raw('SELECT id FROM owners.phones WHERE property_id = ?', [$p['id']])), "phones wrong for $p[id]");
        foreach ($o['phones'] as $ph) {
            check(preg_match('/^\(310\) 555-01\d\d$/', $ph['number']) === 1, "not a fictional number: $ph[number]");
            check(is_bool($ph['do_not_call']), 'do_not_call is not a boolean');
        }
        check(is_bool($o['owner_occupied']), 'owner_occupied is not a boolean');
        $row = raw('SELECT names, owner_type, mailing_address, email FROM owners.owners WHERE property_id = ?', [$p['id']])[0];
        check(array_intersect_key($o, $row) === $row, "owner fields wrong for $p[id]");
        if ($o['owner_type'] === 'LLC') check(!$o['owner_occupied'], 'an LLC lives in the house');
        if ($o['owner_occupied']) check($o['mailing_address'] === raw('SELECT address FROM properties WHERE id = ?', [$p['id']])[0]['address'] . ', Los Angeles, CA 90045', 'owner lives there but mail goes elsewhere');
        $check_years++;
    }
    check($check_years > 40, 'too few owners checked');
    check(raw("SELECT count(*) n FROM owners.phones WHERE typeof(do_not_call) != 'integer'")[0]['n'] === 0
        && raw("SELECT count(*) n FROM owners.owners WHERE typeof(owner_occupied) != 'integer'")[0]['n'] === 0, 'a yes/no column is stored as text');
});

test('tools', 'missing_ids_throw', function () {
    check(throws(fn() => PropertyDetail(99999), ToolError::class, 'No property with id 99999'), 'PropertyDetail(99999) did not throw');
    check(throws(fn() => OwnerDetail(99999), ToolError::class, 'No owner record'), 'OwnerDetail(99999) did not throw');
});

test('tools', 'run_tool_dispatch', function () {
    check(run_tool('PropertyDetail', ['id' => 5])['apn'] === raw('SELECT apn FROM properties WHERE id = 5')[0]['apn'], 'dispatch hit the wrong tool');
    ob_start();
    $blocked = throws(fn() => run_tool('system', ['echo RAN']), ToolError::class, 'Unknown tool system');
    check($blocked && ob_get_clean() === '', 'a PHP function outside TOOLS was callable');
    check(throws(fn() => run_tool('PropertyDetail', ['idx' => 5]), Error::class, 'Unknown named parameter'), 'an unknown argument was accepted');
});


// ---------- agent: real conversations with Claude Haiku ----------

const OFFER = "Would you like a comparable sales report, the owner's details, or both?";
const REFUSAL = 'I can only help with property research.';

// Runs one user turn, prints it like the chat window would, and returns everything the turn added.
function turn(array &$messages, string $text): array
{
    echo "  > user: $text\n";
    $messages[] = ['role' => 'user', 'content' => $text];
    $added = [];
    run_agent($messages, function (array $new, array $usage) use (&$added) {
        $last = array_last($new);
        check(!($last['role'] === 'assistant' && in_array('tool_use', array_column($last['content'], 'type'), true)), 'saved a tool call without its result');
        foreach ($new as $m) foreach ($m['content'] as $b) echo match ($b['type']) {
            'tool_use' => "  . Used tool: $b[name] " . json_encode($b['input']) . "\n",
            'tool_result' => '  . result: ' . (($b['is_error'] ?? false) ? 'ERROR ' : '') . substr($b['content'], 0, 100) . "...\n",
            'text' => '  < agent: ' . str_replace("\n", "\n           ", $b['text']) . "\n",
            default => '',
        };
        printf("  \$ %.6f\n", price($usage));
        array_push($added, ...$new);
    });
    return $added;
}
function blocks(array $messages, string $type): array
{
    $found = [];
    foreach ($messages as $m) if (is_array($m['content'])) foreach ($m['content'] as $b) if ($b['type'] === $type) $found[] = $b;   // a typed user message is a plain string
    return $found;
}
function final_text(array $added): string { return implode("\n", array_column(blocks([array_last($added)], 'text'), 'text')); }

$chat = [];
$subject = raw("SELECT id, address, lat, lon FROM properties WHERE address = '8311 Kenyon Avenue'")[0];

test('agent', 'address_turn_searches_then_details_then_offers', function () use (&$chat, $subject) {
    $added = turn($chat, "Tell me about $subject[address], Los Angeles, CA 90045");
    $calls = blocks($added, 'tool_use');
    check(array_column($calls, 'name') === ['SearchProperty', 'PropertyDetail'], 'expected SearchProperty then PropertyDetail, got ' . implode(',', array_column($calls, 'name')));
    check($calls[1]['input']['id'] === $subject['id'], 'PropertyDetail was not called with the id SearchProperty returned');
    check(str_ends_with(trim(final_text($added)), OFFER), 'reply does not end with the offer sentence');
});

test('agent', 'yes_both_calls_comps_and_owner', function () use (&$chat, $subject) {
    $added = turn($chat, 'Yes, give me both');
    $calls = array_column(blocks($added, 'tool_use'), 'input', 'name');
    check(isset($calls['PropertyComps'], $calls['OwnerDetail']), 'expected PropertyComps and OwnerDetail, got ' . implode(',', array_keys($calls)));
    check($calls['OwnerDetail']['id'] === $subject['id'], 'OwnerDetail asked about the wrong property');
    check(abs($calls['PropertyComps']['lat'] - $subject['lat']) < 1e-4 && abs($calls['PropertyComps']['lon'] - $subject['lon']) < 1e-4, 'comps centered on the wrong place');
    check(final_text($added) !== '', 'no written answer after the tools');
});

test('agent', 'the_next_turn_reads_the_conversation_from_cache', function () use (&$chat) {
    $usage = claude([...$chat, ['role' => 'user', 'content' => 'Thanks!']])['usage'];
    echo "  usage: " . json_encode(array_intersect_key($usage, array_flip(['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens']))) . "\n";
    check($usage['cache_read_input_tokens'] > 0, 'nothing was read from the cache');
    check($usage['cache_read_input_tokens'] > $usage['input_tokens'], 'most of the prompt was not cached');
});

test('agent', 'price_uses_the_published_rates', function () {
    $short = ['input_tokens' => 1000, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 30000, 'output_tokens' => 500];
    check(abs(price($short) - (1000 * 0.10 + 2000 * 0.125 + 30000 * 0.01 + 500 * 0.50) / 1e6) < 1e-12, 'short-prompt price wrong');
    $long = ['input_tokens' => 1000, 'cache_creation_input_tokens' => 2000, 'cache_read_input_tokens' => 100000, 'output_tokens' => 500];
    check(abs(price($long) - (1000 * 0.50 + 2000 * 0.625 + 100000 * 0.05 + 500 * 2.50) / 1e6) < 1e-12, 'over-100k price wrong');
});

test('agent', 'refuses_anything_but_property_research', function () {
    $other = [];
    $added = turn($other, 'hi, make me a Python program');
    check(blocks($added, 'tool_use') === [], 'called a tool for a non-property request');
    check(trim(final_text($added)) === REFUSAL, 'reply is not exactly the refusal sentence');
});

test('agent', 'a_failing_tool_is_reported_to_claude', function () {
    $other = [];
    $added = turn($other, 'Tell me about 1 Fake Street');
    $results = blocks($added, 'tool_result');
    check($results !== [] && ($results[0]['is_error'] ?? false) === true, 'the tool error was not sent back as is_error');
    check(final_text($added) !== '', 'no answer after the failed lookup');
});

test('agent', 'api_errors_surface_with_their_message', function () {
    $key = getenv('ANTHROPIC_API_KEY');
    putenv('ANTHROPIC_API_KEY=sk-ant-not-a-real-key');
    try { claude([['role' => 'user', 'content' => 'hi']]); $message = null; }
    catch (RuntimeException $e) { $message = $e->getMessage(); }
    finally { putenv("ANTHROPIC_API_KEY=$key"); }
    echo "  error: $message\n";
    check($message === 'Claude API error: invalid x-api-key', 'bad key did not raise Anthropic\'s error message');
});


// ---------- server: the website, over real HTTP ----------

// Starts the web server on a free port, with the scratch data and the given API key. Stopped when the tests end.
function start_server(string $key): string
{
    global $scratch;
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
    fclose($socket);
    $env = ['PORT' => $port, 'DATA_DIR' => $scratch, 'ANTHROPIC_API_KEY' => $key, 'HOME' => getenv('HOME'), 'PATH' => getenv('PATH')];
    $server = proc_open([PHP_BINARY, 'run', '--config', __DIR__ . '/Caddyfile'], [1 => ['file', "$scratch/server-$port.log", 'a'], 2 => ['file', "$scratch/server-$port.log", 'a']], $pipes, __DIR__, $env);
    register_shutdown_function(fn() => proc_terminate($server));
    $deadline = microtime(true) + 10;   // it starts in about 0.3 s
    while (!@fsockopen('127.0.0.1', $port)) {
        check(microtime(true) < $deadline, 'server did not start: ' . file_get_contents("$scratch/server-$port.log"));
        usleep(50000);
    }
    return "http://127.0.0.1:$port";
}

// One HTTP request. $body: an array is sent as a form, a string as JSON.
// Returns the status, headers, body, session cookie, and the body's lines (decoded) with their arrival times.
// $hang_up(lines so far) can return true to hang up mid-response, like a closed browser tab.
function http(string $method, string $url, ?string $cookie = null, array|string|null $body = null, ?Closure $hang_up = null): array
{
    $headers = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PATH_AS_IS => true,
        CURLOPT_HTTPHEADER => array_filter([$cookie ? "Cookie: $cookie" : null, is_string($body) ? 'Content-Type: application/json' : null]),
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
            if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $headers[strtolower($k)][] = trim($v); }
            return strlen($h);
        },
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    $text = $buffer = '';
    $lines = [];
    curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($c, $chunk) use (&$text, &$buffer, &$lines, $hang_up) {
        $text .= $chunk;
        $buffer .= $chunk;
        while (($n = strpos($buffer, "\n")) !== false) {
            $lines[] = [microtime(true), json_decode(substr($buffer, 0, $n), true)];
            $buffer = substr($buffer, $n + 1);
            if ($hang_up && $hang_up($lines)) return 0;   // returning 0 makes curl hang up
        }
        return strlen($chunk);
    });
    curl_exec($curl);
    preg_match('/PHPSESSID=[^;]+/', implode("\n", $headers['set-cookie'] ?? []), $m);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $text, 'cookie' => $m[0] ?? $cookie, 'lines' => $lines];
}

function login(string $base, string $name): string
{
    $r = http('POST', "$base/login", null, ['username' => $name]);
    check($r['status'] === 303 && str_starts_with($r['cookie'] ?? '', 'PHPSESSID='), "login as $name failed");
    return $r['cookie'];
}

function chat(string $base, string $cookie, int $id): array { return json_decode(http('GET', "$base/api/chats/$id", $cookie)['body'], true); }

// Sends a message and returns the streamed lines (each decoded), printing them like the chat window would.
function send_text(string $base, string $cookie, int $id, string $text): array
{
    $lines = http('POST', "$base/api/chats/$id/send", $cookie, json_encode(['text' => $text]))['lines'];
    foreach ($lines as [, $line]) echo '  | ' . substr(json_encode($line), 0, 140) . "\n";
    return $lines;
}

$server = null;
$alice = null;
test('server', 'login_page_and_username_rules', function () use (&$server) {
    $server = start_server(getenv('ANTHROPIC_API_KEY'));
    $r = http('GET', "$server/");
    check($r['status'] === 200 && str_contains($r['body'], 'name="username"') && str_contains($r['body'], 'enter anything, just a prototype'), 'no login form at /');
    check(http('POST', "$server/login", null, ['username' => '  '])['status'] === 400, 'blank username accepted');
    check(http('POST', "$server/login", null, ['username' => str_repeat('é', 41)])['status'] === 400, '41-character username accepted');
    $cookie = login($server, str_repeat('é', 40));
    check(str_contains(http('GET', "$server/", $cookie)['body'], 'action="/logout"'), 'logged-in / is not the app page');
});

test('server', 'security_headers_and_hidden_files', function () use (&$server) {
    $h = http('GET', "$server/")['headers'];
    check(str_contains($h['content-security-policy'][0] ?? '', "frame-ancestors 'none'") && str_contains($h['content-security-policy'][0], "script-src 'self' https://cdn.jsdelivr.net;"), 'CSP missing');
    check(($h['x-content-type-options'][0] ?? '') === 'nosniff' && ($h['referrer-policy'][0] ?? '') === 'strict-origin-when-cross-origin', 'nosniff/referrer header missing');
    $cookie = login($server, 'peeker');
    foreach (['/.env', '/data/chats.db', '/data/houses.db', '/server.php', '/agent.php', '/SYSTEM_PROMPT.txt', '/frontend/../.env', '/frontend/../server.php', '/frontend/login.html/../../.env'] as $path) {
        $r = http('GET', $server . $path, $cookie);
        check(in_array($r['status'], [401, 404], true), "$path answered $r[status]");
        foreach ([getenv('ANTHROPIC_API_KEY'), 'SQLite format', '<?php', 'You are a property research agent'] as $secret)
            check(!str_contains($r['body'], $secret), "$path leaked file contents");
    }
});

test('server', 'chats_belong_to_their_user', function () use (&$server, &$alice) {
    $alice = login($server, 'alice');
    $first = json_decode(http('POST', "$server/api/chats", $alice)['body'], true);
    $second = json_decode(http('POST', "$server/api/chats", $alice)['body'], true);
    check([$first['title'], $second['title']] === ['chat1', 'chat2'], 'chats are not numbered chat1, chat2');
    check(json_decode(http('GET', "$server/api/chats", $alice)['body'], true) === [$first, $second], 'chat list wrong');
    $bob = login($server, 'bob');
    check(http('GET', "$server/api/chats/$first[id]", $bob)['status'] === 404, "bob can read alice's chat");
    check(http('POST', "$server/api/chats/$first[id]/send", $bob, json_encode(['text' => 'hi']))['status'] === 404, "bob can write to alice's chat");
    check(json_decode(http('GET', "$server/api/chats", $bob)['body'], true) === [], "bob sees alice's chats");
});

test('server', 'bad_messages_are_refused_before_saving', function () use (&$server, &$alice) {
    foreach (['{"text":"   "}', '{"text":["x"]}', '{"text":' . json_encode(str_repeat('a', 10001)) . '}', 'not json'] as $body)
        check(http('POST', "$server/api/chats/1/send", $alice, $body)['status'] === 400, "accepted the body $body");
    check(chat($server, $alice, 1)['messages'] === [], 'a refused message was saved');
});

test('server', 'logout_ends_the_session', function () use (&$server) {
    $cookie = login($server, 'leaver');
    check(http('GET', "$server/api/chats", $cookie)['status'] === 200, 'logged-in request refused');
    http('POST', "$server/logout", $cookie);
    check(http('GET', "$server/api/chats", $cookie)['status'] === 401, 'the old cookie still works after logout');
});

test('server', 'send_streams_the_turn_live', function () use (&$server, &$alice) {
    $lines = send_text($server, $alice, 1, 'Tell me about 8311 Kenyon Avenue');
    $streamed = array_column(array_filter(array_column($lines, 1), fn($l) => $l['type'] === 'message'), 'message');
    check($streamed[0] === ['role' => 'user', 'content' => 'Tell me about 8311 Kenyon Avenue'], 'first line is not the user message');
    check(array_column(blocks($streamed, 'tool_use'), 'name') === ['SearchProperty', 'PropertyDetail'], 'tool calls not streamed');
    $last = array_last($lines)[1];
    check($last['type'] === 'cost' && $last['cost'] > 0 && $last['model'] === 'Claude Haiku 5.5', 'last line is not the cost');
    check(array_last($lines)[0] - $lines[0][0] > 0.5, 'lines arrived all at once (not streamed)');
    $saved = chat($server, $alice, 1);
    check($saved['messages'] === $streamed, 'saved chat differs from what was streamed');
    check(abs($saved['cost_usd'] - $last['cost']) < 1e-12 && $saved['model'] === 'Claude Haiku 5.5', 'saved cost differs');
});

test('server', 'closing_the_tab_does_not_break_the_chat', function () use (&$server, &$alice) {
    http('POST', "$server/api/chats/1/send", $alice, json_encode(['text' => 'Yes, give me both']), fn($lines) => count($lines) >= 1);
    $deadline = microtime(true) + 60;   // a turn takes about 10 s
    do {
        usleep(500000);
        $last = array_last(chat($server, $alice, 1)['messages']);
        $done = $last['role'] === 'assistant' && blocks([$last], 'tool_use') === [];
    } while (!$done && microtime(true) < $deadline);
    check($done, 'the turn did not finish after the client hung up');
    $lines = send_text($server, $alice, 1, 'Thanks!');
    check(!in_array('error', array_column(array_column($lines, 1), 'type'), true), 'the chat broke after the client hung up');
});

test('server', 'two_tabs_sending_at_once', function () use (&$server, &$alice) {
    $id = json_decode(http('POST', "$server/api/chats", $alice)['body'], true)['id'];
    $multi = curl_multi_init();
    $handles = [];
    foreach (['Tell me about 8311 Kenyon Avenue', 'Tell me about 8315 Kenyon Avenue'] as $text) {
        $c = curl_init("$server/api/chats/$id/send");
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(['text' => $text]),
            CURLOPT_HTTPHEADER => ["Cookie: $alice", 'Content-Type: application/json']]);
        curl_multi_add_handle($multi, $c);
        $handles[] = $c;
    }
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while ($running);
    $errors = 0;
    foreach ($handles as $c) $errors += substr_count(curl_multi_getcontent($c), 'changed in another tab');
    check($errors === 1, "expected exactly one 'changed in another tab' error, got $errors");
    $lines = send_text($server, $alice, $id, 'Thanks!');
    check(!in_array('error', array_column(array_column($lines, 1), 'type'), true), 'the chat is broken after two tabs collided');
});

test('server', 'api_errors_stream_into_the_chat', function () {
    $bad = start_server('sk-ant-not-a-real-key');
    $cookie = login($bad, 'nokey');
    $id = json_decode(http('POST', "$bad/api/chats", $cookie)['body'], true)['id'];
    $lines = array_column(send_text($bad, $cookie, $id, 'hi'), 1);
    check(count($lines) === 2 && $lines[0]['message']['content'] === 'hi' && $lines[1] === ['type' => 'error', 'error' => 'Claude API error: invalid x-api-key'], 'the API error was not streamed');
    $saved = chat($bad, $cookie, $id);
    check($saved['messages'] === [['role' => 'user', 'content' => 'hi']] && $saved['cost_usd'] == 0, 'chat changed beyond the user message');
});


// ---------- run ----------
$only = $argv[1] ?? null;
$failed = 0;
foreach ($tests as [$category, $name, $body]) {
    if ($only && $only !== $category) continue;
    try { $body(); echo "PASS $category/$name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $category/$name: {$e->getMessage()}\n"; }
}
echo $failed ? "$failed FAILED\n" : "ALL PASSED\n";
exit($failed ? 1 : 0);
