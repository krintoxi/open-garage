<?php
// "Am I being ripped off?" (2026-11-08, roadmap #7). A usual price range for common jobs, from:
//   1. what shops on this app actually invoiced for that job (once there are 5+ jobs from 2+ shops), else
//   2. typical labor hours (the canned job list) x the national labor rate spread, plus a typical parts range.
// Always labelled as a rough guide: cars, regions and parts brands differ. No shop names, ever.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Typical retail parts cost for the canned jobs, aftermarket quality, US dollars [low, high]. Rough by design. */
const FAIR_PARTS = [
    'Oil and filter change' => [25, 70], 'Engine air filter' => [15, 45], 'Cabin air filter' => [15, 45], 'Wiper blades' => [20, 60],
    'Spark plugs (4-cylinder)' => [30, 90], 'Spark plugs (V6)' => [45, 130], 'Spark plugs (V8)' => [60, 170], 'Coolant flush' => [30, 70], 'Brake fluid flush' => [10, 30],
    'Automatic transmission fluid service' => [60, 180], 'Differential / transfer case fluid' => [25, 70], 'Serpentine belt' => [25, 80], 'Timing belt and water pump' => [150, 400],
    'Mount and balance 4 tires' => [400, 1000], 'Tire rotation' => [0, 0], 'Flat repair (plug-patch)' => [5, 15], 'Four-wheel alignment' => [0, 0],
    'Front brake pads and rotors' => [150, 350], 'Rear brake pads and rotors' => [140, 330], 'Front brake pads only' => [40, 110], 'Brake caliper (one)' => [50, 150],
    'Battery replacement' => [140, 280], 'Alternator' => [180, 450], 'Starter' => [150, 400], 'Electrical diagnosis' => [0, 0], 'Check engine light diagnosis' => [0, 0],
    'Ignition coil (one)' => [35, 120], 'Upstream O2 / air-fuel sensor' => [50, 180], 'Downstream O2 sensor' => [40, 140], 'MAF sensor' => [70, 250], 'Throttle body service' => [8, 15],
    'Catalytic converter' => [300, 1500], 'EVAP smoke test' => [0, 0], 'EVAP purge valve' => [30, 110], 'Intake/vacuum leak smoke test' => [0, 0],
    'Thermostat' => [30, 120], 'Water pump' => [60, 250], 'Radiator' => [120, 400], 'Cooling fan' => [100, 400], 'A/C performance check' => [0, 0], 'A/C evacuate and recharge' => [30, 150],
    'Front struts (pair)' => [180, 500], 'Rear shocks (pair)' => [70, 250], 'Sway bar links (pair)' => [30, 90], 'Outer tie rod ends (pair)' => [40, 120],
    'Wheel bearing / hub (one)' => [70, 250], 'CV axle (one)' => [80, 250],
];
/** Labor rate spread: posted rates are most often $100-139/h; the national average effective rate is about $137 (Tekmetric Shop Index, Sep 2026). */
const FAIR_RATE = [100, 160];
const FAIR_PREMIUM_MAKES = ['MERCEDES-BENZ', 'MERCEDES', 'BMW', 'AUDI', 'PORSCHE', 'LAND ROVER', 'JAGUAR', 'VOLVO', 'MINI', 'LEXUS', 'ACURA', 'INFINITI', 'GENESIS', 'ALFA ROMEO', 'MASERATI', 'TESLA'];

/** The common jobs, by category: name => [category, labor hours]. */
function fair_jobs(): array {
    $out = [];
    foreach (canned_defaults() as [$cat, $name, $lines]) {
        if (!isset(FAIR_PARTS[$name])) continue;
        $out[$name] = [$cat, array_sum(array_map(fn($l) => $l[0] === 'labor' ? (float)$l[2] : 0, $lines))];
    }
    return $out;
}

/** What shops on this instance invoiced for this job (approved lines of one service group): [n, shops, p25, median, p75] or null. */
function fair_from_shops(string $job): ?array {
    $rows = rows("SELECT j.user_id u, SUM(l.qty*l.price) amt FROM job_lines l JOIN jobs j ON j.id=l.job_id
        WHERE l.grp=? AND l.status='approved' AND j.stage IN ('invoiced','paid') AND j.invoiced_at >= ? GROUP BY l.job_id HAVING amt > 0", [$job, date('Y-m-d', strtotime('-2 years'))]);
    if (count($rows) < 5 || count(array_unique(array_column($rows, 'u'))) < 2) return null;
    $a = array_map(fn($r) => (float)$r['amt'], $rows); sort($a);
    $pct = fn(float $p) => $a[(int)floor($p * (count($a) - 1))];
    return ['n' => count($a), 'shops' => count(array_unique(array_column($rows, 'u'))), 'lo' => $pct(0.25), 'mid' => $pct(0.5), 'hi' => $pct(0.75)];
}

/** The usual range for a job on this vehicle: lo / hi / mid, labor hours, parts range and where the numbers came from. */
function fair_range(string $job, array $veh = []): ?array {
    $jobs = fair_jobs(); if (!isset($jobs[$job])) return null;
    [, $hours] = $jobs[$job]; [$pl, $ph] = FAIR_PARTS[$job];
    $premium = in_array(strtoupper(trim((string)($veh['make'] ?? ''))), FAIR_PREMIUM_MAKES, true);
    if ($premium) { $pl *= 1.3; $ph *= 1.6; $hours *= 1.15; }
    $r = ['hours' => round($hours, 1), 'parts' => [round($pl), round($ph)], 'labor' => [round($hours * FAIR_RATE[0]), round($hours * FAIR_RATE[1])], 'premium' => $premium, 'basis' => 'typical', 'n' => 0];
    $r['lo'] = $r['labor'][0] + $r['parts'][0]; $r['hi'] = $r['labor'][1] + $r['parts'][1]; $r['mid'] = round(($r['lo'] + $r['hi']) / 2);
    if (($s = fair_from_shops($job)) && !$premium) $r = array_merge($r, ['lo' => round($s['lo']), 'hi' => round($s['hi']), 'mid' => round($s['mid']), 'basis' => 'shops', 'n' => $s['n']]);
    return $r;
}

/** Where a quote sits: ['low'|'fair'|'high'|'very_high', words]. Generous margins: this is a rough guide. */
function fair_verdict(float $amount, array $r): array {
    if ($amount < $r['lo'] * 0.75) return ['low', 'Below the usual range. Check what is included (parts quality, all the labor) before you say yes.'];
    if ($amount <= $r['hi'] * 1.15) return ['fair', 'In the usual range for this job.'];
    if ($amount <= $r['hi'] * 1.5) return ['high', 'On the high side. Ask what is included and why; a dealer or OEM parts can explain part of it. A second quote is reasonable.'];
    return ['very_high', 'Well above the usual range. Ask for an itemized estimate and get a second quote before you agree.'];
}
