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
    run_agent($messages, function (array $new, array $usages) use (&$added) {
        $last = end($new);
        check(!($last['role'] === 'assistant' && in_array('tool_use', array_column($last['content'], 'type'), true)), 'saved a tool call without its result');
        check(count($usages) === count(array_filter($new, fn($m) => $m['role'] === 'assistant')), 'not exactly one usage per Claude call');
        foreach ($new as $m) foreach ($m['content'] as $b) echo match ($b['type']) {
            'tool_use' => "  . Used tool: $b[name] " . json_encode($b['input']) . "\n",
            'tool_result' => '  . result: ' . (($b['is_error'] ?? false) ? 'ERROR ' : '') . substr($b['content'], 0, 100) . "...\n",
            'text' => '  < agent: ' . str_replace("\n", "\n           ", $b['text']) . "\n",
            default => '',
        };
        printf("  \$ %.6f\n", array_sum(array_map('price', $usages)));
        array_push($added, ...$new);
    });
    return $added;
}
function blocks(array $messages, string $type): array
{
    $found = [];
    foreach ($messages as $m) foreach ($m['content'] as $b) if ($b['type'] === $type) $found[] = $b;
    return $found;
}
function final_text(array $added): string { return implode("\n", array_column(blocks([end($added)], 'text'), 'text')); }

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
