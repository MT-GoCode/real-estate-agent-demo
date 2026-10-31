<?php
// The one test suite.   All tests:  frankenphp php-cli test.php     One category:  frankenphp php-cli test.php tools
// Tests run on a scratch copy of the data (never data/), seeded fresh at the start.

$scratch = sys_get_temp_dir() . '/real-estate-agent-test';
if (is_dir($scratch)) array_map('unlink', glob("$scratch/*"));
else mkdir($scratch);
putenv("DATA_DIR=$scratch");
require __DIR__ . '/tools.php';

$tests = [];
function test(string $category, string $name, Closure $body): void { global $tests; $tests[] = [$category, $name, $body]; }
function check(bool $ok, string $what): void { if (!$ok) throw new Exception($what); }
function throws(Closure $body): bool { try { $body(); return false; } catch (Throwable) { return true; } }
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

test('tools', 'unknown_address_throws', fn() => check(throws(fn() => SearchProperty('1 Fake Street')), 'no error for a made-up address'));

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
        $check_years++;
    }
    check($check_years > 40, 'too few owners checked');
    check(raw("SELECT count(*) n FROM owners.phones WHERE typeof(do_not_call) != 'integer'")[0]['n'] === 0
        && raw("SELECT count(*) n FROM owners.owners WHERE typeof(owner_occupied) != 'integer'")[0]['n'] === 0, 'a yes/no column is stored as text');
});

test('tools', 'missing_ids_throw', function () {
    check(throws(fn() => PropertyDetail(99999)), 'PropertyDetail(99999) did not throw');
    check(throws(fn() => OwnerDetail(99999)), 'OwnerDetail(99999) did not throw');
});

test('tools', 'run_tool_dispatch', function () {
    check(run_tool('PropertyDetail', ['id' => 5])['apn'] === raw('SELECT apn FROM properties WHERE id = 5')[0]['apn'], 'dispatch hit the wrong tool');
    check(throws(fn() => run_tool('system', ['ls'])), 'a function outside TOOLS was callable');
    check(throws(fn() => run_tool('PropertyDetail', ['idx' => 5])), 'an unknown argument was accepted');
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
