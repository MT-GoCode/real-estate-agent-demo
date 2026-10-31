<?php
// Builds data/houses.db and data/owners.db. Run: frankenphp php-cli seed.php
//
// REAL (from OpenStreetMap, © OpenStreetMap contributors, ODbL): every address, parcel number (APN),
// map position, year built and unit count, saved in data/westchester.json by this Overpass query:
//   way["building"="house"]["addr:housenumber"]["addr:street"](33.955,-118.410,33.963,-118.398);out center tags;
// (762 houses; 3 without a parcel number or year built were dropped.)
//
// FAKE: everything else -- values, sales, mortgages, owners, phones, emails. The numbers are only
// meant to look plausible for Westchester. The random generator starts from a fixed seed, so
// every run builds exactly the same data and property ids never change.
// Only houses.db and owners.db are rebuilt; users and chats are never touched.

require __DIR__ . '/tools.php';

$dir = getenv('DATA_DIR') ?: __DIR__ . '/data';
foreach (['houses', 'owners'] as $name)
    foreach (['', '-wal', '-shm'] as $suffix)
        if (file_exists("$dir/$name.db$suffix")) unlink("$dir/$name.db$suffix");

mt_srand(42);
function pick(array $list) { return $list[mt_rand(0, count($list) - 1)]; }
function chance(float $p): bool { return mt_rand() / mt_getrandmax() < $p; }
function day_in(int $year): string { return sprintf('%d-%02d-%02d', $year, mt_rand(1, 12), mt_rand(1, 28)); }

const FIRST = ['James', 'Maria', 'Robert', 'Linda', 'Michael', 'Patricia', 'David', 'Jennifer', 'William', 'Elizabeth',
    'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Daniel', 'Karen', 'Kevin', 'Nancy', 'Brian', 'Lisa',
    'Anthony', 'Michelle', 'Mark', 'Angela', 'Steven', 'Grace', 'Andrew', 'Emily', 'Carlos', 'Sofia', 'Wei', 'Mei',
    'Hiroshi', 'Yuki', 'Ahmed', 'Fatima', 'Kwame', 'Amara'];
const LAST = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
    'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee',
    'Perez', 'Thompson', 'White', 'Harris', 'Clark', 'Lewis', 'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Nguyen',
    'Kim', 'Chen', 'Tanaka', 'Patel', 'Okafor', 'Cohen', 'Rivera'];
const LENDERS = ['Wells Fargo', 'Chase', 'Bank of America', 'Rocket Mortgage', 'U.S. Bank', 'loanDepot',
    'United Wholesale Mortgage', 'Guild Mortgage'];
const ELSEWHERE = ['Wilshire Blvd, Los Angeles, CA 90010', 'Ocean Ave, Santa Monica, CA 90401', 'Sepulveda Blvd, Culver City, CA 90230',
    'Pacific Coast Hwy, Torrance, CA 90505', 'Ventura Blvd, Sherman Oaks, CA 91403', 'Main St, Irvine, CA 92614',
    'Lake Ave, Pasadena, CA 91101', 'Market St, San Francisco, CA 94103'];
const NOW = 2026;

$db = db();
$db->exec('CREATE TABLE properties (
    id INTEGER PRIMARY KEY, address TEXT NOT NULL, address_key TEXT NOT NULL UNIQUE, apn TEXT NOT NULL,
    lat REAL NOT NULL, lon REAL NOT NULL, property_type TEXT NOT NULL, units INTEGER NOT NULL,
    beds INTEGER NOT NULL, baths REAL NOT NULL, sqft INTEGER NOT NULL, lot_sqft INTEGER NOT NULL, year_built INTEGER NOT NULL,
    last_sale_date TEXT NOT NULL, last_sale_price INTEGER NOT NULL,
    estimated_value INTEGER,   -- NULL: no valuation on file
    assessed_value INTEGER NOT NULL, foreclosure_status TEXT NOT NULL)');
$db->exec('CREATE TABLE mortgages (
    id INTEGER PRIMARY KEY, property_id INTEGER NOT NULL, lender TEXT NOT NULL, loan_type TEXT NOT NULL,
    original_amount INTEGER NOT NULL, balance INTEGER NOT NULL, interest_rate REAL NOT NULL, recorded_date TEXT NOT NULL)');
$db->exec('CREATE TABLE owners.owners (
    property_id INTEGER PRIMARY KEY, names TEXT NOT NULL, owner_type TEXT NOT NULL, mailing_address TEXT NOT NULL,
    owner_occupied INTEGER NOT NULL, age_range TEXT NOT NULL, email TEXT NOT NULL)');
$db->exec('CREATE TABLE owners.phones (
    id INTEGER PRIMARY KEY, property_id INTEGER NOT NULL, number TEXT NOT NULL, line_type TEXT NOT NULL,
    do_not_call INTEGER NOT NULL)');

$property = $db->prepare('INSERT INTO properties VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$mortgage = $db->prepare('INSERT INTO mortgages VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)');
$owner = $db->prepare('INSERT INTO owners.owners VALUES (?, ?, ?, ?, ?, ?, ?)');
$phone = $db->prepare('INSERT INTO owners.phones VALUES (NULL, ?, ?, ?, ?)');

$db->beginTransaction();
foreach (json_decode(file_get_contents(__DIR__ . '/data/westchester.json'), true) as $i => $h) {
    $id = $i + 1;
    $unit_sqft = $h['units'] == 1 ? mt_rand(1100, 2400) : mt_rand(700, 1100);   // a house, or one apartment of a multi-unit
    $sqft = $h['units'] * $unit_sqft;
    $market = round($sqft * mt_rand(900, 1100), -3);               // today's value, ~$900-1,100 per sq ft
    $sale_year = mt_rand(max($h['year_built'], 1975), NOW - 1);
    $sale_date = day_in($sale_year);
    $years = NOW - $sale_year;
    $sale_price = round($market / 1.055 ** $years, -3);             // ~5.5% yearly appreciation since the sale
    $property->execute([
        $id, $h['address'], address_key($h['address']), $h['apn'], $h['lat'], $h['lon'],
        match ($h['units']) { 1 => 'Single Family Residence', 2 => 'Duplex', default => 'Multi-family' }, $h['units'],
        $h['units'] * min(5, max(1, round($unit_sqft / 550))), $h['units'] * min(3.5, max(1, round($unit_sqft / 350) / 2)), $sqft, mt_rand(50, 75) * 100, $h['year_built'],
        $sale_date, $sale_price,
        chance(0.8) ? $market : null,                                  // about 1 in 5 houses has no valuation
        min($market, round($sale_price * 1.02 ** $years)),             // California Prop 13: assessment grows at most 2% a year
        chance(0.03) ? pick(['Pre-foreclosure (Notice of Default)', 'Auction scheduled']) : 'None',
    ]);

    if ($years < 30 && chance(0.7)) {                                  // a 30-year loan from the purchase, partly paid down
        $amount = round($sale_price * 0.8, -3);
        $mortgage->execute([$id, pick(LENDERS), 'Conventional 30-yr fixed', $amount, round($amount * (1 - $years / 30), -3),
            mt_rand(275, 750) / 100, $sale_date]);
    }
    if (chance(0.15)) {                                                // a home-equity line taken out later
        $amount = mt_rand(10, 30) * 10000;
        $mortgage->execute([$id, pick(LENDERS), 'HELOC', $amount, round($amount * mt_rand(50, 100) / 100, -3),
            mt_rand(600, 950) / 100, day_in(mt_rand($sale_year, NOW - 1))]);
    }

    $first = pick(FIRST);
    $last = pick(LAST);
    $r = mt_rand(1, 100);
    $type = $r <= 82 ? 'Individual' : ($r <= 94 ? 'Trust' : 'LLC');
    $occupied = $type != 'LLC' && chance(0.78);
    $owner->execute([
        $id,
        match ($type) {
            'Individual' => chance(0.5) ? "$first $last" : "$first & " . pick(FIRST) . " $last",
            'Trust' => "The $last Family Trust",
            'LLC' => "$last Property Holdings LLC",
        },
        $type,
        $occupied ? "$h[address], " . CITY : mt_rand(100, 9999) . ' ' . pick(ELSEWHERE),
        (int) $occupied,
        pick(match (true) { $years < 5 => ['25-34', '35-44'], $years < 15 => ['35-44', '45-54'],
                            $years < 30 => ['45-54', '55-64'], default => ['65-74', '75+'] }),
        $type == 'LLC' ? 'info@' . strtolower($last) . '-holdings.example.com' : strtolower("$first.$last") . '@example.com',
    ]);
    foreach (range(1, mt_rand(1, 3)) as $_)                            // 555-0100..0199 is reserved for fiction
        $phone->execute([$id, sprintf('(310) 555-01%02d', mt_rand(0, 99)), pick(['Mobile', 'Mobile', 'Landline', 'VoIP']), (int) chance(0.3)]);
}
$db->commit();

$count = fn($table) => $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
echo "Seeded {$count('properties')} properties, {$count('mortgages')} mortgages, {$count('owners.owners')} owners, {$count('owners.phones')} phones.\n";
