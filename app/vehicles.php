<?php
declare(strict_types=1);
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/**
 * Multi-vehicle helpers: stand-in artwork, VIN decoding (NHTSA vPIC), stock photo lookup (Wikimedia Commons),
 * and starter maintenance / weekly-check templates per vehicle kind.
 */

/** Two display colours from a free-text colour ("White and black", "Obsidian Black", "red"). */
function vehicle_colors(?string $text): array {
    $map = ['white' => '#eef0f3', 'pearl' => '#eef0f3', 'black' => '#141519', 'obsidian' => '#141519', 'red' => '#c8102e', 'blue' => '#1f4fa8',
        'silver' => '#b9bec6', 'grey' => '#7d838c', 'gray' => '#7d838c', 'green' => '#2f7d4a', 'yellow' => '#f2c200', 'orange' => '#e8681c',
        'brown' => '#6b4a2f', 'beige' => '#d8c7a5', 'gold' => '#c9a648', 'purple' => '#5b3c8c', 'pink' => '#e27aa6'];
    $found = [];
    foreach (preg_split('/[^a-z]+/', strtolower((string)$text)) as $w) if (isset($map[$w]) && !in_array($map[$w], $found, true)) $found[] = $map[$w];
    $p = $found[0] ?? '#2a2d33';
    $a = $found[1] ?? ($p === '#141519' ? '#3a3e46' : '#141519');
    return [$p, $a];
}

/** Where things sit on the stand-in artwork (percent of width / height), for issue markers. */
function art_points(string $kind): array {
    if (in_array($kind, ['scooter', 'motorcycle'], true)) return [
        'wheels' => [25, 76], 'brakes' => [77, 77], 'engine' => [37, 70], 'transmission' => [44, 70], 'driveline' => [44, 70], 'exhaust' => [19, 74],
        'electrical' => [80, 40], 'body' => [40, 46], 'suspension_front' => [74, 57], 'suspension_rear' => [32, 62], 'cabin' => [40, 34],
        'evap' => [40, 42], 'cooling' => [36, 62], 'fan' => [36, 62], 'ac' => [36, 62], 'intake' => [33, 58], 'doors' => [58, 60], 'other' => [52, 22]];
    return [
        'engine' => [80, 52], 'intake' => [76, 47], 'transmission' => [60, 72], 'driveline' => [45, 74], 'exhaust' => [22, 76], 'evap' => [30, 70],
        'cooling' => [88, 57], 'fan' => [86, 55], 'ac' => [90, 62], 'electrical' => [84, 50], 'cabin' => [50, 40], 'doors' => [50, 55],
        'suspension_front' => [77, 66], 'suspension_rear' => [24, 66], 'brakes' => [77, 74], 'wheels' => [24, 74], 'body' => [36, 52], 'other' => [50, 26]];
}

/** Inline SVG side view (front on the right) used until the owner adds a photo or a 3D model exists. */
function vehicle_art_svg(string $kind, ?string $color): string {
    [$p, $a] = vehicle_colors($color);
    $light = in_array($p, ['#eef0f3', '#b9bec6', '#f2c200', '#d8c7a5'], true);
    $edge = $light ? 'rgba(0,0,0,.35)' : 'rgba(255,255,255,.18)';
    $wheel = fn(float $x, float $y, float $r, int $spokes) => "<g transform=\"translate($x $y)\">"
        . "<circle r=\"$r\" fill=\"url(#tire)\"/><circle r=\"" . ($r * 0.92) . "\" fill=\"none\" stroke=\"#000\" stroke-opacity=\".5\" stroke-width=\"3\"/>"
        . "<circle r=\"" . ($r * 0.66) . "\" fill=\"url(#rim)\"/>"
        . implode('', array_map(fn($i) => '<rect x="-2.6" y="-' . ($r * 0.64) . '" width="5.2" height="' . ($r * 0.64) . '" rx="2" fill="#0b0c0f" transform="rotate(' . (360 / $spokes * $i) . ')"/>', range(0, $spokes - 1)))
        . "<circle r=\"" . ($r * 0.42) . "\" fill=\"#5d636c\" opacity=\".55\"/><circle r=\"" . ($r * 0.15) . "\" fill=\"#22252b\" stroke=\"#8a9099\" stroke-width=\"2\"/></g>";
    $defs = '<defs>'
        . '<radialGradient id="tire" r=".5"><stop offset=".72" stop-color="#1c1d21"/><stop offset=".86" stop-color="#0b0b0d"/><stop offset="1" stop-color="#26272c"/></radialGradient>'
        . '<radialGradient id="rim" r=".5"><stop offset="0" stop-color="#3c4048"/><stop offset="1" stop-color="#17181c"/></radialGradient>'
        . "<linearGradient id=\"bodyP\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\"><stop offset=\"0\" stop-color=\"$p\"/><stop offset=\".55\" stop-color=\"$p\"/><stop offset=\"1\" stop-color=\"$p\" stop-opacity=\".78\"/></linearGradient>"
        . "<linearGradient id=\"bodyA\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\"><stop offset=\"0\" stop-color=\"$a\"/><stop offset=\"1\" stop-color=\"$a\" stop-opacity=\".85\"/></linearGradient>"
        . '<linearGradient id="shine" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>'
        . '<radialGradient id="lamp" r=".5"><stop offset="0" stop-color="#fff"/><stop offset=".6" stop-color="#dfe9ff"/><stop offset="1" stop-color="#9fb6e6"/></radialGradient>'
        . '<filter id="glow" x="-1" y="-1" width="3" height="3"><feGaussianBlur stdDeviation="6"/></filter></defs>';
    if (in_array($kind, ['scooter', 'motorcycle'], true)) {
        // Sport scooter, side view: rear wheel left, front wheel right.
        $svg = '<svg class="vart" viewBox="0 0 640 380" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Scooter">' . $defs
            . '<ellipse cx="322" cy="352" rx="262" ry="13" fill="#000" opacity=".55"/>'
            // exhaust + engine / CVT case
            . '<path d="M96 296 L 196 284 Q 214 282 214 296 Q 214 310 196 312 L 104 318 Q 84 320 84 306 Q 84 298 96 296 Z" fill="#3b3f47"/><path d="M100 300 L 190 290" stroke="#6c727c" stroke-width="3" stroke-linecap="round"/>'
            . '<path d="M150 262 Q 146 236 176 232 L 300 228 Q 326 228 326 252 L 320 284 Q 316 298 296 298 L 196 300 Q 168 300 158 288 Z" fill="#2b2e35"/><path d="M168 244 L 300 240" stroke="#4a4f58" stroke-width="2"/>'
            . $wheel(160, 296, 56, 7) . $wheel(498, 296, 54, 12)
            // rear swingarm shock
            . '<path d="M232 236 L 258 176" stroke="#c8102e" stroke-width="9" stroke-linecap="round"/><path d="M232 236 L 258 176" stroke="#ff5a6e" stroke-width="2" stroke-dasharray="4 5"/>'
            // floorboard
            . "<path d=\"M300 262 L 418 258 Q 430 258 432 268 L 432 280 L 300 284 Z\" fill=\"url(#bodyA)\" stroke=\"$edge\"/>"
            // rear body (accent lower, primary upper) and tail
            . "<path d=\"M64 150 C 68 140 90 136 120 140 L 330 160 C 356 162 364 180 352 198 L 330 232 Q 318 252 296 254 L 176 260 C 130 262 100 238 84 200 C 74 182 64 168 64 150 Z\" fill=\"url(#bodyP)\" stroke=\"$edge\" stroke-width=\"1.5\"/>"
            . "<path d=\"M84 200 C 120 214 190 218 240 210 C 280 204 310 202 345 202 L 330 232 Q 318 252 296 254 L 176 260 C 130 262 100 238 84 200 Z\" fill=\"url(#bodyA)\" opacity=\".95\"/>"
            . '<path d="M62 150 L 94 145 L 94 159 L 66 165 Z" fill="#c8102e"/><path d="M66 152 L 92 148" stroke="#ff8a96" stroke-width="2"/>'
            . '<path d="M96 156 C 160 154 250 162 330 170" stroke="url(#shine)" stroke-width="5" fill="none" opacity=".7"/>'
            // seat
            . '<path d="M118 138 C 126 120 170 112 222 118 L 318 132 C 344 136 352 150 340 160 L 150 152 C 128 150 114 146 118 138 Z" fill="#1a1b1f"/><path d="M140 126 C 200 116 280 124 330 140" stroke="#34373e" stroke-width="3" fill="none"/>'
            // leg shield / front body
            . "<path d=\"M392 262 C 396 214 402 166 418 120 L 452 112 C 448 160 446 214 444 262 Z\" fill=\"url(#bodyA)\" stroke=\"$edge\"/>"
            . "<path d=\"M436 268 C 446 214 460 156 470 112 L 512 112 C 532 150 540 196 524 236 C 516 254 500 262 478 264 Z\" fill=\"url(#bodyP)\" stroke=\"$edge\" stroke-width=\"1.5\"/>"
            . '<path d="M474 120 C 470 160 462 210 452 250" stroke="url(#shine)" stroke-width="5" fill="none" opacity=".6"/>'
            // headlight (sharp LED shape) + glow
            . '<path d="M506 150 L 538 168 L 532 196 L 512 184 Z" fill="url(#lamp)"/><ellipse cx="530" cy="178" rx="26" ry="16" fill="#cfe0ff" opacity=".45" filter="url(#glow)"/>'
            // front fender over the wheel
            . '<path d="M440 270 C 452 228 540 222 560 266 L 548 266 C 530 238 470 240 456 270 Z" fill="#17181c"/>'
            // fork, bars, screen, mirrors
            . '<path d="M486 140 L 498 296" stroke="#9aa0a8" stroke-width="10" stroke-linecap="round"/><path d="M486 140 L 498 296" stroke="#d7dbe0" stroke-width="3"/>'
            . '<path d="M462 104 L 520 98 L 524 114 L 466 120 Z" fill="#1a1b1f"/><path d="M500 100 C 512 74 534 62 556 66 L 548 92 C 534 92 520 98 510 104 Z" fill="#141519" stroke="#3a3e46"/>'
            . '<path d="M470 104 L 452 70" stroke="#2a2d33" stroke-width="5" stroke-linecap="round"/><ellipse cx="448" cy="62" rx="14" ry="9" fill="#1a1b1f" stroke="#3a3e46"/>'
            . '<rect x="440" y="96" width="30" height="9" rx="4" fill="#d9dce1"/>'
            // amber side marker on fork (like the owner's photo)
            . '<rect x="489" y="240" width="7" height="26" rx="3" fill="#ffb02e"/><rect x="487" y="236" width="11" height="34" rx="5" fill="#ffb02e" opacity=".35" filter="url(#glow)"/>'
            . '</svg>';
        return art_unique_ids($svg);
    }
    // Sedan / SUV side view
    $tall = $kind === 'suv';
    $roofY = $tall ? 96 : 118; $beltY = $tall ? 182 : 196;
    return art_unique_ids('<svg class="vart" viewBox="0 0 640 380" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Car">' . $defs
        . '<ellipse cx="320" cy="342" rx="292" ry="14" fill="#000" opacity=".55"/>'
        . "<path d=\"M40 286 C 34 250 44 226 72 214 L 160 202 L 232 " . ($roofY + 12) . " C 260 " . ($roofY - 4) . " 380 " . ($roofY - 6) . " 420 " . ($roofY + 10) . " L 492 196 C 560 202 600 214 610 240 L 612 284 C 612 300 600 306 586 306 L 56 306 C 46 306 40 298 40 286 Z\" fill=\"url(#bodyP)\" stroke=\"$edge\" stroke-width=\"1.5\"/>"
        . "<path d=\"M180 " . ($beltY + 2) . " L 238 " . ($roofY + 20) . " C 262 " . ($roofY + 6) . " 376 " . ($roofY + 4) . " 412 " . ($roofY + 18) . " L 470 " . ($beltY + 2) . " Z\" fill=\"#0c0e12\" stroke=\"#3a3e46\"/>"
        . "<path d=\"M326 " . ($roofY + 10) . " L 322 " . ($beltY + 2) . "\" stroke=\"$p\" stroke-width=\"8\"/>"
        . '<path d="M70 232 C 200 220 460 220 600 236" stroke="url(#shine)" stroke-width="4" fill="none" opacity=".6"/>'
        . '<path d="M576 226 L 608 234 L 606 250 L 580 246 Z" fill="url(#lamp)"/><ellipse cx="596" cy="240" rx="24" ry="12" fill="#cfe0ff" opacity=".4" filter="url(#glow)"/>'
        . '<path d="M42 236 L 62 228 L 64 250 L 44 254 Z" fill="#c8102e"/>'
        . $wheel(150, 300, 50, 10) . $wheel(496, 300, 50, 10)
        . '</svg>');
}
/** Gradient/filter ids must be unique per page (the garage page shows several drawings). */
function art_unique_ids(string $svg): string {
    static $n = 0; $n++;
    foreach (['tire', 'rim', 'bodyP', 'bodyA', 'shine', 'lamp', 'glow'] as $id) $svg = str_replace(['id="' . $id . '"', 'url(#' . $id . ')'], ['id="' . $id . $n . '"', 'url(#' . $id . $n . ')'], $svg);
    return $svg;
}

/** Decode a 17-character VIN with NHTSA vPIC (free, no key). Returns the useful fields, or ['error' => ...]. */
function vin_decode(string $vin): array {
    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin));
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) return ['error' => 'A VIN is 17 letters and digits (never I, O or Q).'];
    $json = http_get_json('https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValuesExtended/' . $vin . '?format=json');
    $r = $json['Results'][0] ?? null;
    if (!$r) return ['error' => 'The VIN service did not answer. Try again, or fill the fields in yourself.'];
    $type = strtoupper((string)($r['VehicleType'] ?? ''));
    $body = strtolower((string)($r['BodyClass'] ?? ''));
    $kind = match (true) {
        str_contains($body, 'scooter') || str_contains($body, 'moped') => 'scooter',
        str_contains($type, 'MOTORCYCLE') => 'motorcycle',
        str_contains($type, 'TRUCK') || str_contains($type, 'MULTIPURPOSE') => 'suv',
        default => 'car',
    };
    $cc = (float)($r['DisplacementCC'] ?? 0);
    $engine = trim(implode(' ', array_filter([
        $cc ? ($cc >= 1000 ? number_format($cc / 1000, 1) . ' L' : round($cc) . ' cc') : '',
        !empty($r['EngineConfiguration']) ? $r['EngineConfiguration'] : '',
        !empty($r['EngineCylinders']) ? $r['EngineCylinders'] . '-cyl' : '',
        !empty($r['EngineModel']) ? '(' . $r['EngineModel'] . ')' : '',
        !empty($r['FuelTypePrimary']) && stripos((string)$r['FuelTypePrimary'], 'gasoline') === false ? $r['FuelTypePrimary'] : '',
    ])));
    $out = [
        'vin' => $vin, 'year' => $r['ModelYear'] ?? '', 'make' => ucwords(strtolower((string)($r['Make'] ?? '')), " -"), 'model' => $r['Model'] ?? '',
        'trim' => trim(($r['Trim'] ?? '') . ' ' . ($r['Series'] ?? '')), 'engine' => $engine,
        'transmission' => trim(($r['TransmissionStyle'] ?? '') . ' ' . ($r['TransmissionSpeeds'] ? $r['TransmissionSpeeds'] . '-speed' : '')),
        'kind' => $kind, 'body' => $r['BodyClass'] ?? '', 'plant' => trim(($r['PlantCity'] ?? '') . ' ' . ($r['PlantCountry'] ?? '')),
        'note' => trim((string)($r['ErrorText'] ?? '')),
    ];
    if ($out['make'] === '' && $out['model'] === '') $out['error'] = 'NHTSA does not know this VIN (common for imported scooters). Fill the fields in yourself.';
    return $out;
}

function http_get(string $url, int $timeout = 8): ?string {
    if (!function_exists('curl_init')) return null;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'GarageApp/1.0 (vehicle maintenance log)', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $body = curl_exec($c);
    $ok = $body !== false && curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
    return $ok ? (string)$body : null;
}
function http_get_json(string $url): ?array { $b = http_get($url); $j = $b ? json_decode($b, true) : null; return is_array($j) ? $j : null; }

/**
 * A free stand-in photo of the same make AND model from Wikimedia Commons, saved (re-encoded) into UPLOAD_DIR.
 * Strict on purpose: a loose search returns unrelated pictures. Returns ['path' => 'uploads/x.jpg', 'credit' => '...'] or null.
 */
function find_stock_photo(array $v): ?array {
    $make = strtolower(trim((string)$v['make'])); $model = strtolower(trim((string)$v['model']));
    if ($make === '' || $model === '') return null;
    $modelTokens = array_filter(preg_split('/[^a-z0-9]+/', $model), fn($t) => strlen($t) >= 2);
    if (!$modelTokens) return null;
    $api = 'https://commons.wikimedia.org/w/api.php?' . http_build_query(['action' => 'query', 'generator' => 'search', 'gsrsearch' => 'filetype:bitmap ' . $v['make'] . ' ' . $v['model'],
        'gsrnamespace' => 6, 'gsrlimit' => 15, 'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 1600, 'format' => 'json']);
    $pages = http_get_json($api)['query']['pages'] ?? [];
    usort($pages, fn($x, $y) => ($x['index'] ?? 0) <=> ($y['index'] ?? 0));
    foreach ($pages as $pg) {
        $title = strtolower(preg_replace('/[_\s]+/', ' ', (string)$pg['title']));
        $ii = $pg['imageinfo'][0] ?? null;
        if (!$ii || !str_contains($title, $make)) continue;
        if (!array_filter($modelTokens, fn($t) => preg_match('/\b' . preg_quote($t, '/') . '\b/', $title))) continue;
        if (!in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true) || ($ii['width'] ?? 0) < 800 || ($ii['width'] ?? 0) < ($ii['height'] ?? 0)) continue;
        $em = $ii['extmetadata'] ?? [];
        $license = trim(strip_tags((string)($em['LicenseShortName']['value'] ?? '')));
        if ($license === '') continue;
        $img = http_get((string)($ii['thumburl'] ?? $ii['url']), 15);
        if (!$img) continue;
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
        $tmp = tempnam(sys_get_temp_dir(), 'stk'); file_put_contents($tmp, $img);
        $name = date('Ymd-His') . '-stock-' . bin2hex(random_bytes(3)) . '.jpg';
        $ok = reencode_image($tmp, UPLOAD_DIR . '/' . $name, 1600); @unlink($tmp);
        if (!$ok) continue;
        $artist = trim(html_entity_decode(strip_tags((string)($em['Artist']['value'] ?? 'Unknown')), ENT_QUOTES));
        return ['path' => UPLOAD_URL . '/' . $name, 'credit' => 'Stand-in photo: ' . mb_substr($artist, 0, 80) . ', ' . $license . ', via Wikimedia Commons (' . ($ii['descriptionurl'] ?? '') . ')'];
    }
    return null;
}

/** Starter maintenance schedule and weekly checklist for a new vehicle. Intervals are conservative defaults; edit them to the owner's manual. */
function vehicle_templates(string $kind): array {
    if (in_array($kind, ['scooter', 'motorcycle'], true)) return [
        'maintenance' => [
            ['Engine oil + clean oil strainer', 'Engine', 1000, 6, '4-stroke 50cc scooters usually take about 0.8 L (0.85 qt) of 10W-40 motorcycle oil (JASO MA). Check the dipstick and the manual.', 1],
            ['Gear oil (final drive)', 'Drivetrain', 3000, 12, 'Usually about 110-120 ml of 80W-90 gear oil. Drain and fill bolts are on the rear hub.', 2],
            ['Tires: tread, pressure, sidewall cracks', 'Wheels / Tires', 0, 0, 'Pressure is on the sticker under the seat or in the manual. Replace when the tread is near the wear bars or the sidewall is cracked.', 1],
            ['Brake pads / shoes', 'Brakes', 2000, 12, 'Front disc pads and rear drum shoes. Check the wear indicator on the rear brake arm.', 1],
            ['Air filter', 'Engine', 3000, 12, 'Foam or paper element in the airbox on the left side of the engine.', 3],
            ['Spark plug', 'Engine', 4000, 24, 'Common size for 50cc 4-stroke scooters: C7HSA. Gap 0.6-0.7 mm.', 3],
            ['CVT belt, rollers and clutch inspection', 'Drivetrain', 5000, 24, 'Look for cracks, glazing and flat spots on the rollers.', 3],
            ['Valve clearance', 'Engine', 4000, 24, 'Check cold. Typical for 50cc 4-stroke scooters: 0.05-0.08 mm intake and exhaust.', 4],
            ['Battery', 'Electrical', 0, 12, 'Check the terminals and charge, especially after the scooter sits.', 4],
        ],
        'checks' => [
            ['Tires', 'Tire pressure and tread', 'Check both tires cold. Look for cracks, bulges, nails and worn tread.', 1],
            ['Brakes', 'Front and rear brakes', 'Both levers firm, brake light comes on, no grinding.', 2],
            ['Engine', 'Oil level', 'Scooter upright on the centre stand, dipstick resting on the threads, not screwed in.', 3],
            ['Lights', 'Lights, horn and turn signals', 'Headlight high/low, tail and brake light, all four signals, horn.', 4],
            ['Leaks', 'Look under for drips', 'Oil, gear oil or fuel under the engine and rear hub.', 5],
            ['Controls', 'Throttle snaps back, mirrors tight', 'Throttle returns to closed by itself; mirrors and bars are not loose.', 6],
        ],
    ];
    return [
        'maintenance' => [
            ['Engine oil and filter', 'Engine', 5000, 6, 'Use the oil spec and capacity from the owner\'s manual.', 1],
            ['Tire rotation and pressures', 'Wheels / Tires', 6000, 6, '', 2],
            ['Brake pads and rotors inspection', 'Brakes', 10000, 12, '', 2],
            ['Engine air filter', 'Engine', 15000, 24, '', 3],
            ['Cabin air filter', 'A/C & HVAC', 15000, 12, '', 4],
            ['Brake fluid', 'Brakes', 0, 24, '', 3],
            ['Coolant', 'Cooling', 60000, 60, '', 4],
            ['Spark plugs', 'Engine', 60000, 72, '', 4],
            ['Transmission fluid', 'Transmission', 60000, 0, 'Follow the manual; many "lifetime" fluids still need changing.', 3],
        ],
        'checks' => [
            ['Tires', 'Tire pressure and tread', 'Check cold. Look for uneven wear, nails and bulges.', 1],
            ['Fluids', 'Engine oil level', 'On level ground, engine off for a few minutes.', 2],
            ['Leaks', 'Cardboard under the car overnight', 'Note the colour and where it drips.', 3],
            ['Lights', 'All lights and signals', '', 4],
            ['Dash', 'Warning lights on the dash', 'Write down any light that stays on.', 5],
        ],
    ];
}

/** Insert the starter schedule and checklist for a vehicle. */
function seed_vehicle_templates(int $vehicleId, string $kind): void {
    $t = vehicle_templates($kind);
    foreach ($t['maintenance'] as [$item, $cat, $mi, $mo, $notes, $prio]) insert('maintenance', ['vehicle_id' => $vehicleId, 'item' => $item, 'category' => $cat, 'interval_miles' => $mi, 'interval_months' => $mo, 'notes' => $notes, 'priority' => $prio, 'source' => 'Typical schedule for this kind of vehicle']);
    foreach ($t['checks'] as [$cat, $item, $how, $sort]) insert('checks', ['vehicle_id' => $vehicleId, 'category' => $cat, 'item' => $item, 'how_to' => $how, 'frequency' => 'weekly', 'sort' => $sort, 'active' => 1]);
}

/** Delete a vehicle with every record and photo file that belongs to it. */
function delete_vehicle(int $vidDel): void {
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vidDel]);
    if (!$v) return;
    $paths = array_merge(array_column(rows('SELECT path FROM photos WHERE vehicle_id=?', [$vidDel]), 'path'), array_filter([$v['hero_cutout'] ?? null, $v['hero_depth'] ?? null, $v['mesh_glb'] ?? null]));
    q('DELETE FROM issue_notes WHERE issue_id IN (SELECT id FROM issues WHERE vehicle_id=?)', [$vidDel]);
    q('DELETE FROM visit_issues WHERE visit_id IN (SELECT id FROM visits WHERE vehicle_id=?) OR issue_id IN (SELECT id FROM issues WHERE vehicle_id=?)', [$vidDel, $vidDel]);
    q('DELETE FROM inspection_results WHERE inspection_id IN (SELECT id FROM inspections WHERE vehicle_id=?)', [$vidDel]);
    foreach (SCOPED as $t) q("DELETE FROM $t WHERE vehicle_id=?", [$vidDel]);
    q('DELETE FROM diag_reports WHERE vehicle_id=?', [$vidDel]);
    q('DELETE FROM handoffs WHERE vehicle_id=?', [$vidDel]);
    q('DELETE FROM vehicle WHERE id=?', [$vidDel]);
    foreach (array_unique($paths) as $pth) {
        if ((int)col('SELECT COUNT(*) FROM photos WHERE path=?', [$pth]) || (int)col('SELECT COUNT(*) FROM vehicle WHERE ? IN (photo, hero_cutout, hero_depth, mesh_glb)', [$pth])) continue;
        $f = UPLOAD_DIR . '/' . basename((string)$pth); if (is_file($f)) @unlink($f);
    }
}

/** "What's going on with it?" answers in the Add-vehicle wizard, turned into issues with plain-language guidance. */
function quick_issue_templates(string $kind): array {
    $two = in_array($kind, ['scooter', 'motorcycle'], true);
    $t = [
        'oil' => ['Oil change due', 'Fluids', 'engine', 3, $two
            ? "- Use 4-stroke motorcycle oil (JASO MA), not car oil: car oil can make the clutch slip.\n- Small scooters hold under a litre, so old oil wears them fast. Change it now if you don't know when it was last done.\n- Mark it done under Maintenance with the mileage so the next one counts down."
            : "- Check the oil type and amount in the owner's manual (or the sticker under the hood).\n- If you don't know when it was last done, change it now and start the record here.\n- Mark it done under Maintenance with the mileage so the next one counts down.", 'Engine oil and filter service.'],
        'tire' => ['Tire worn or damaged', 'Wheels / Tires', 'wheels', 4,
            "- Don't drive or ride on it if the tread is at the wear bars, cords show, or the sidewall is cracked or bulging.\n- Read the size off the sidewall and add a photo of it here: that's what you buy.\n" . ($two ? "- Car tire shops often refuse two-wheelers. Motorcycle or powersports shops will fit it, often cheaply if you bring just the wheel." : "- Replace in pairs on the same axle, and get an alignment if the wear is uneven."), 'Inspect and replace the tire; check pressures and wear pattern.'],
        'brakes' => ['Brakes squeal or feel soft', 'Brakes', 'brakes', 4,
            "- A soft or sinking pedal/lever, or grinding metal, means stop driving and get it checked now.\n- A light squeal on the first stops of the day is often normal; a constant squeal usually means pads are near the wear indicator.\n- Ask for pad thickness in millimetres and photos when it's checked.", 'Inspect pads/shoes, rotors/drums and fluid.'],
        'battery' => ['Hard to start or weak battery', 'Electrical', 'electrical', 2,
            "- Note whether it clicks, cranks slowly, or nothing happens.\n- Most auto parts stores test batteries for free.\n- A battery over 4-5 years old is due anyway.", 'Test battery and charging system.'],
        'leak' => ['Something is leaking', 'Fluids', 'engine', 3,
            "- Put white cardboard under it overnight and photograph the spot.\n- Colour tells you what: red/pink is transmission or power steering fluid, green/orange/pink and sweet-smelling is coolant, brown/black is engine oil, clear is usually just A/C water.\n- Add the photo here and note where under the vehicle it drips.", 'Find the source of the leak.'],
        'noise' => ['New noise', 'Other', 'other', 2,
            "- Write down when it happens: cold or warm, turning, braking, over bumps, at what speed.\n- Record a short video with sound and add it to your phone's notes; mechanics find it much faster that way.", 'Diagnose the noise described.'],
        'lights' => ['A light is out', 'Electrical', 'electrical', 2, "- Check every light: low/high beam, tail, brake, signals" . ($two ? '' : ', plate') . ".\n- A bulb is usually a cheap fix; a fast-blinking signal means a bulb on that side is out.", 'Replace the bulb or find the fault.'],
    ];
    if (!$two) {
        $t['cel'] = ['Check engine light is on', 'Engine', 'engine', 3, "- Steady light: get the code read soon (many auto parts stores do it free) and log it under OBD codes.\n- Flashing light: misfire, which can damage the catalytic converter. Drive gently and get it checked right away.\n- A loose gas cap is a common cause; tighten it and see if the light clears after a few drives.", 'Read codes and diagnose.'];
        $t['ac'] = ['A/C is not cold', 'A/C & HVAC', 'ac', 2, "- Check whether the cooling fan behind the grille runs with the A/C on.\n- Avoid DIY recharge cans: overfilling causes more damage. Ask for a leak test.", 'A/C performance check and leak test.'];
    }
    return $t;
}
/** Create issues from the wizard's ticked answers. Returns how many were added. */
function add_quick_issues(int $vehicleId, string $kind, array $keys, int $miles): int {
    $t = quick_issue_templates($kind); $n = 0;
    foreach (array_unique($keys) as $k) {
        if (!isset($t[$k])) continue;
        [$title, $sys, $loc, $sev, $owner, $mech] = $t[$k];
        $iid = insert('issues', ['vehicle_id' => $vehicleId, 'title' => $title, 'system' => $sys, 'location' => $loc, 'severity' => $sev, 'status' => 'open', 'summary' => '',
            'evidence' => '', 'likely_causes' => '', 'owner_guidance' => $owner, 'mechanic_guidance' => $mech, 'opened_on' => today(), 'resolved_on' => null,
            'caused_by_visit_id' => null, 'est_cost' => '', 'links' => '', 'created_at' => now(), 'updated_at' => now()]);
        if ($k === 'oil') q("UPDATE maintenance SET due_date=? WHERE vehicle_id=? AND item LIKE '%oil%' AND category='Engine'", [today(), $vehicleId]);
        $n++;
    }
    return $n;
}

/** Makes (curated, popular first) and models (NHTSA vPIC, cached 30 days) for the wizard's pickers. */
function vpic_list(string $what, string $kind, string $make, int $year): array {
    $two = in_array($kind, ['scooter', 'motorcycle'], true);
    if ($what === 'makes') return ['makes' => $two
        ? ['Honda', 'Yamaha', 'Suzuki', 'Kawasaki', 'Harley-Davidson', 'BMW', 'Ducati', 'KTM', 'Triumph', 'Royal Enfield', 'Indian', 'Vespa', 'Piaggio', 'Kymco', 'SYM', 'Genuine', 'Italika', 'Tao Motor', 'Znen', 'Lance', 'Wolf', 'Can-Am', 'Aprilia', 'CFMoto', 'Benelli', 'Zero']
        : ['Toyota', 'Honda', 'Ford', 'Chevrolet', 'Nissan', 'Hyundai', 'Kia', 'Jeep', 'Ram', 'GMC', 'Subaru', 'Mazda', 'Volkswagen', 'BMW', 'Mercedes-Benz', 'Audi', 'Lexus', 'Tesla', 'Dodge', 'Chrysler', 'Buick', 'Cadillac', 'Acura', 'Infiniti', 'Volvo', 'Mitsubishi', 'Lincoln', 'Land Rover', 'Porsche', 'Mini', 'Genesis', 'Jaguar', 'Alfa Romeo', 'Fiat', 'Maserati', 'Rivian', 'Polestar', 'Pontiac', 'Saturn', 'Scion', 'Mercury', 'Hummer', 'Suzuki', 'Isuzu']];
    if ($what !== 'models' || $make === '' || $year < 1981 || $year > (int)date('Y') + 2) return ['models' => []];
    $types = $two ? ['motorcycle'] : ($kind === 'suv' ? ['truck', 'mpv'] : ['car', 'mpv', 'truck']);
    $key = 'models:' . strtolower($make) . ':' . $year . ':' . implode(',', $types);
    $c = row('SELECT * FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 30 * 86400) return json_decode($c['body'], true) ?: ['models' => []];
    $models = [];
    foreach ($types as $t) {
        $j = http_get_json('https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMakeYear/make/' . rawurlencode($make) . '/modelyear/' . $year . '/vehicletype/' . $t . '?format=json');
        foreach ($j['Results'] ?? [] as $r) if (!empty($r['Model_Name'])) $models[trim($r['Model_Name'])] = true;
    }
    $out = ['models' => array_keys($models)];
    natcasesort($out['models']); $out['models'] = array_values($out['models']);
    if ($out['models']) q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, json_encode($out), time()]);
    return $out;
}

/* ---- garage-vision: cut-out + depth showroom image, and experimental single-photo 3D (runs on a GPU machine) ---- */
function vision_call(string $method, string $path, ?string $body = null, int $timeout = 40): ?array {
    if (VISION_URL === '' || !function_exists('curl_init')) return null;
    $c = curl_init(rtrim(VISION_URL, '/') . $path);
    $h = ['Authorization: Bearer ' . VISION_TOKEN];
    if ($body !== null) $h[] = 'Content-Type: application/octet-stream';
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); $type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE);
    if ($out === false) return null;
    return ['code' => $code, 'type' => $type, 'body' => (string)$out];
}
function store_bytes(string $bytes, string $ext, string $tag): string {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    protect_dir(UPLOAD_DIR);
    $name = date('Ymd-His') . '-' . $tag . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents(UPLOAD_DIR . '/' . $name, $bytes);
    return UPLOAD_URL . '/' . $name;
}
function drop_file(?string $path): void {
    if (!$path) return;
    if ((int)col('SELECT COUNT(*) FROM photos WHERE path=?', [$path])) return;
    $f = UPLOAD_DIR . '/' . basename($path); if (is_file($f)) @unlink($f);
}
/** Make the cut-out + depth images for a vehicle's own photo. Returns ready | pending | limit | off. */
function vision_process(int $vehicleId, bool $force = false): string {
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vehicleId]);
    if (!$v || !$v['photo'] || $v['photo_credit']) return 'off';
    if (VISION_URL === '') return 'off';
    if (MULTI_USER && !rate_ok('vision:' . $v['user_id'] . ':' . date('Ymd'), LIMIT_VISION_PER_DAY, 86400)) return 'limit';
    $src = UPLOAD_DIR . '/' . basename((string)$v['photo']);
    $r = is_file($src) ? vision_call('POST', '/showroom', (string)file_get_contents($src), 45) : null;
    $j = ($r && $r['code'] === 200) ? json_decode($r['body'], true) : null;
    if (!$j || empty($j['cutout']) || empty($j['depth'])) { update('vehicle', ['vision_status' => 'pending:' . time()], $vehicleId); return 'pending'; }
    $cut = store_bytes(base64_decode($j['cutout']), 'png', 'cutout'); $dep = store_bytes(base64_decode($j['depth']), 'png', 'depth');
    drop_file($v['hero_cutout']); drop_file($v['hero_depth']);
    update('vehicle', ['hero_cutout' => $cut, 'hero_depth' => $dep, 'vision_status' => 'ready'], $vehicleId);
    return 'ready';
}
/** Start building a 3D model from the cut-out. Returns running | limit | offline | nophoto. */
function mesh_start(int $vehicleId): string {
    if (!VISION_MESH) return 'offline';
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vehicleId]);
    if (!$v || !$v['photo'] || $v['photo_credit']) return 'nophoto';
    if (MULTI_USER && !rate_ok('vision:' . $v['user_id'] . ':' . date('Ymd'), LIMIT_VISION_PER_DAY, 86400)) return 'limit';
    $src = UPLOAD_DIR . '/' . basename((string)($v['hero_cutout'] ?: $v['photo']));
    $r = is_file($src) ? vision_call('POST', '/mesh/start', (string)file_get_contents($src), 20) : null;
    $j = ($r && $r['code'] === 200) ? json_decode($r['body'], true) : null;
    if (!$j || empty($j['job'])) return 'offline';
    update('vehicle', ['mesh_job' => (string)$j['job'], 'mesh_status' => 'running'], $vehicleId);
    return 'running';
}
/** Status for the dashboard poller; also retries a pending cut-out and collects a finished 3D model. */
function vision_status_for(int $vehicleId): array {
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vehicleId]);
    if (!$v) return ['vision' => 'none'];
    $vs = (string)$v['vision_status'];
    if (str_starts_with($vs, 'pending:') && (int)substr($vs, 8) < time() - 60) $vs = vision_process($vehicleId);
    $mesh = (string)$v['mesh_status']; $progress = null;
    if ($mesh === 'running' && $v['mesh_job']) {
        $r = vision_call('GET', '/mesh/status?job=' . rawurlencode($v['mesh_job']), null, 8);
        $j = ($r && $r['code'] === 200) ? json_decode($r['body'], true) : null;
        $st = $j['status'] ?? 'unknown'; $progress = $j['progress'] ?? null;
        if ($st === 'done') {
            $g = vision_call('GET', '/mesh/result?job=' . rawurlencode($v['mesh_job']), null, 30);
            if ($g && $g['code'] === 200 && strlen($g['body']) > 1000) {
                $path = store_bytes($g['body'], 'glb', 'mesh'); drop_file($v['mesh_glb']);
                update('vehicle', ['mesh_glb' => $path, 'mesh_status' => 'ready', 'mesh_job' => null], $vehicleId); $mesh = 'ready'; $v['mesh_glb'] = $path;
            }
        } elseif (in_array($st, ['error', 'missing'], true)) { update('vehicle', ['mesh_status' => 'error', 'mesh_job' => null], $vehicleId); $mesh = 'error'; }
    }
    return ['vision' => str_starts_with($vs, 'pending') ? 'pending' : ($vs ?: 'none'), 'cutout' => $v['hero_cutout'] ? photo_url($v['hero_cutout']) : null,
        'depth' => $v['hero_depth'] ? photo_url($v['hero_depth']) : null, 'mesh' => $mesh ?: 'none', 'progress' => $progress, 'glb' => $v['mesh_glb'] ? photo_url($v['mesh_glb']) : null];
}

/** Everything one account owns, for the JSON backup in multi-user mode. */
function account_export(int $userId): array {
    $out = ['exported_at' => now(), 'vehicles' => []];
    foreach (rows('SELECT * FROM vehicle WHERE user_id=?', [$userId]) as $v) {
        $vid = (int)$v['id']; $e = ['vehicle' => $v];
        foreach (SCOPED as $t) if (!in_array($t, ['manual_pages'], true)) $e[$t] = rows("SELECT * FROM $t WHERE vehicle_id=?", [$vid]);
        $e['issue_notes'] = rows('SELECT n.* FROM issue_notes n JOIN issues i ON i.id=n.issue_id WHERE i.vehicle_id=?', [$vid]);
        $e['visit_issues'] = rows('SELECT vi.* FROM visit_issues vi JOIN visits v ON v.id=vi.visit_id WHERE v.vehicle_id=?', [$vid]);
        $e['inspection_results'] = rows('SELECT r.* FROM inspection_results r JOIN inspections i ON i.id=r.inspection_id WHERE i.vehicle_id=?', [$vid]);
        $out['vehicles'][] = $e;
    }
    $out['mechanics'] = rows('SELECT * FROM mechanics WHERE user_id=?', [$userId]);
    $out['shop_reports'] = rows('SELECT id, vehicle_id, concern, created_at, shop_name, imported_at, returned_at, revoked FROM handoffs WHERE user_id=?', [$userId]);
    foreach (['customers', 'diag_reports', 'tool_links', 'jobs', 'canned_jobs', 'parts_catalog', 'shop_profile', 'appointments', 'techs', 'time_entries', 'vendors', 'purchase_orders', 'part_returns', 'legacy_ros'] as $t) $out[$t] = rows("SELECT * FROM $t WHERE user_id=?", [$userId]);
    foreach ($out['shop_profile'] as &$sp) { $d = json_decode((string)$sp['data'], true) ?: []; unset($d['stripe_sk']); $sp['data'] = json_encode($d); } unset($sp);
    $out['po_lines'] = rows('SELECT x.* FROM po_lines x JOIN purchase_orders p ON p.id=x.po_id WHERE p.user_id=?', [$userId]);
    foreach (['job_lines', 'job_payments', 'job_auth', 'job_inspect', 'job_versions'] as $t) $out[$t] = rows("SELECT x.* FROM $t x JOIN jobs j ON j.id=x.job_id WHERE j.user_id=?", [$userId]);
    $out['links'] = rows('SELECT * FROM links WHERE user_id=?', [$userId]);
    return $out;
}
