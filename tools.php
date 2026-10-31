<?php
// The agent's four tools. Each tool is two things:
//   1. An entry in TOOLS: a name, a description and the inputs it takes. This list is sent
//      to Claude with every request, so Claude knows the tools exist and how to call them.
//   2. A PHP function with the same name. It runs here, on our server, when Claude asks for it.
// Claude never touches the database. It only asks; this file answers.

require_once __DIR__ . '/db.php';

const CITY = 'Los Angeles, CA 90045';

const TOOLS = [
    [
        'name' => 'SearchProperty',
        'description' => 'Look up a property by street address and get its property ID. Pass the house number and full street name, for example "8701 Kenyon Avenue". Call this first whenever the user names an address.',
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['address'],
            'properties' => ['address' => ['type' => 'string']]],
    ],
    [
        'name' => 'PropertyDetail',
        'description' => 'Get the full record for a property ID: parcel number, location, size, beds and baths, year built, last sale, estimated value, tax-assessed value, mortgages and equity, and foreclosure status.',
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer']]],
    ],
    [
        'name' => 'PropertyComps',
        'description' => 'Comparable-sales (comps) report: every property within radius_meters of a point that has an estimated value, nearest first. Use the subject property\'s lat and lon; 75 meters is a good radius in this neighborhood (about 10-20 comps).',
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lat', 'lon', 'radius_meters'],
            'properties' => ['lat' => ['type' => 'number'], 'lon' => ['type' => 'number'], 'radius_meters' => ['type' => 'number']]],
    ],
    [
        'name' => 'OwnerDetail',
        'description' => 'Get the owner record for a property ID: owner names, owner type, mailing address, whether the owner lives there, years owned, estimated age range, phone numbers (with Do-Not-Call flags) and email.',
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
            'properties' => ['id' => ['type' => 'integer']]],
    ],
];

// Claude asked for tool $name with inputs $input: call the PHP function of that name.
// A tool that cannot answer throws; agent.php sends the message back to Claude as an error.
function run_tool(string $name, array $input): array
{
    if (!in_array($name, array_column(TOOLS, 'name'), true)) throw new InvalidArgumentException("Unknown tool $name");
    return $name(...$input);
}

// The lookup key for an address: "8311  W. Kenyon Ave, Los Angeles" -> "8311 west kenyon avenue"
const ABBREVIATIONS = ['w' => 'west', 'ave' => 'avenue', 'av' => 'avenue', 'st' => 'street', 'pl' => 'place'];
function address_key(string $address): string
{
    $words = preg_split('/\s+/', trim(strtolower(str_replace('.', '', explode(',', $address)[0]))));
    return implode(' ', array_map(fn($word) => ABBREVIATIONS[$word] ?? $word, $words));
}

function SearchProperty(string $address): array
{
    $q = db()->prepare('SELECT id, address FROM properties WHERE address_key = ?');
    $q->execute([address_key($address)]);
    return $q->fetch() ?: throw new RuntimeException("No property found at \"$address\". This demo covers Westchester, " . CITY . '.');
}

function PropertyDetail(int $id): array
{
    $q = db()->prepare('SELECT * FROM properties WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch() ?: throw new RuntimeException("No property with id $id.");

    $q = db()->prepare('SELECT lender, loan_type, original_amount, balance, interest_rate, recorded_date
                        FROM mortgages WHERE property_id = ? ORDER BY recorded_date');
    $q->execute([$id]);
    $mortgages = $q->fetchAll();
    $debt = array_sum(array_column($mortgages, 'balance'));

    unset($p['address_key']);
    $p['address'] .= ', ' . CITY;
    return $p + [
        'mortgage_count' => count($mortgages),
        'total_mortgage_balance' => $debt,
        'estimated_equity' => $p['estimated_value'] === null ? null : $p['estimated_value'] - $debt,
        'mortgages' => $mortgages,
    ];
}

function PropertyComps(float $lat, float $lon, float $radius_meters): array
{
    $comps = [];
    foreach (db()->query('SELECT id, address, lat, lon, estimated_value, last_sale_price, last_sale_date, sqft, beds, baths
                          FROM properties WHERE estimated_value IS NOT NULL') as $p) {
        $meters = distance_meters($lat, $lon, $p['lat'], $p['lon']);
        if ($meters > $radius_meters) continue;
        unset($p['lat'], $p['lon']);
        $comps[] = $p + ['distance_meters' => round($meters)];
    }
    usort($comps, fn($a, $b) => $a['distance_meters'] <=> $b['distance_meters']);
    return ['radius_meters' => $radius_meters, 'count' => count($comps), 'comps' => $comps];
}

function OwnerDetail(int $id): array
{
    $q = db()->prepare('SELECT o.names, o.owner_type, o.mailing_address, o.owner_occupied, o.age_range, o.email, p.last_sale_date
                        FROM owners.owners o JOIN properties p ON p.id = o.property_id WHERE o.property_id = ?');
    $q->execute([$id]);
    $o = $q->fetch() ?: throw new RuntimeException("No owner record for property id $id.");

    $q = db()->prepare('SELECT number, line_type, do_not_call FROM owners.phones WHERE property_id = ?');
    $q->execute([$id]);
    $phones = $q->fetchAll();
    foreach ($phones as &$phone) $phone['do_not_call'] = (bool) $phone['do_not_call'];

    $o['owner_occupied'] = (bool) $o['owner_occupied'];
    $o['years_owned'] = (new DateTime($o['last_sale_date']))->diff(new DateTime('today'))->y;
    unset($o['last_sale_date']);
    return $o + ['phones' => $phones];
}

// Straight-line distance between two points on Earth (haversine formula).
function distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
    return 2 * 6371000 * asin(sqrt($a));   // 6,371,000 m = Earth's mean radius
}
