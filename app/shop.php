<?php
// Shop management (2026-11-08): repair orders from estimate to paid invoice, pricing (labor rate, tiered parts markup,
// shop supplies, separate parts/labor tax), canned jobs, parts catalog, customer authorization, vehicle inspection.
// Follows common shop-software practice (Tekmetric, Shopmonkey, Mitchell 1) and estimate/authorization record rules
// such as California BAR "Write It Right" (authorized amount, who/when/how, additional authorization).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const JOB_STAGES = [
    'estimate' => 'Estimate', 'approved' => 'Approved', 'in_progress' => 'In progress', 'waiting_parts' => 'Waiting for parts',
    'done' => 'Work done', 'invoiced' => 'Invoiced', 'paid' => 'Paid', 'declined' => 'Declined', 'void' => 'Void',
];
const LINE_KINDS = ['labor' => 'Labor', 'part' => 'Part', 'fee' => 'Fee', 'sublet' => 'Sublet (outside work)', 'core' => 'Core charge', 'note' => 'Note'];
const PART_STATUS = ['quoted' => 'Quoted', 'ordered' => 'Ordered', 'received' => 'Received', 'installed' => 'Installed', 'returned' => 'Returned'];
const AUTH_METHODS = ['link' => 'Signed online (link)', 'in_person' => 'In person (signed)', 'phone' => 'Phone call', 'text' => 'Text message', 'email' => 'Email'];
const PAY_METHODS = ['card' => 'Card', 'cash' => 'Cash', 'check' => 'Check', 'transfer' => 'Bank transfer', 'financing' => 'Financing', 'warranty' => 'Warranty / insurance', 'other' => 'Other'];

/* ---------- Shop profile ---------- */

function shop_defaults(): array {
    return [
        'name' => '', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'license' => '', 'tax_id' => '',
        'currency' => '$', 'labor_rate' => 120.0, 'tax_parts' => 7.0, 'tax_labor' => 0.0, 'tax_fees' => 0.0,
        'supplies_pct' => 5.0, 'supplies_cap' => 35.0,
        // Tiered parts markup: [cost from, markup %]. Typical industry matrix: cheap parts carry a higher markup.
        'matrix' => [[0, 150], [5, 100], [25, 75], [75, 60], [150, 45], [300, 35], [750, 25]],
        'show_rates' => 1, 'show_part_numbers' => 1, 'est_prefix' => 'E-', 'inv_prefix' => '', 'next_est' => 1001, 'next_inv' => 1001,
        'warranty' => '12 months / 12,000 miles on parts and labor, whichever comes first, unless stated otherwise.',
        'est_terms' => 'This estimate is valid for 30 days. Prices for parts may change. Work beyond this estimate will not be done without your authorization.',
        'inv_terms' => 'Payment is due when the vehicle is picked up. Replaced parts are available on request unless returned for core credit or warranty.',
        'auth_threshold_pct' => 10.0, 'auth_threshold_amt' => 100.0,
        // Appointments and online booking. hours: ISO weekday => [open, close] (absent = closed).
        'hours' => [1 => ['08:00', '17:30'], 2 => ['08:00', '17:30'], 3 => ['08:00', '17:30'], 4 => ['08:00', '17:30'], 5 => ['08:00', '17:30'], 6 => ['09:00', '13:00']],
        'slot_min' => 60, 'bays' => 2, 'book_on' => 0, 'book_token' => '', 'book_lead_hours' => 2, 'book_days' => 21, 'book_note' => '',
        // Purchase orders and card payments (Stripe Checkout on the shop's own account; key never shown back).
        'po_prefix' => 'PO-', 'next_po' => 1001, 'stripe_sk' => '', 'currency_code' => 'usd',
    ];
}
function shop(?int $userId = null): array {
    static $cache = [];
    $u = $userId ?? uid();
    if (!isset($cache[$u])) {
        $d = json_decode((string)col('SELECT data FROM shop_profile WHERE user_id=?', [$u]), true) ?: [];
        $cache[$u] = array_replace(shop_defaults(), $d);
    }
    return $cache[$u];
}
function shop_save(array $data): void { q('INSERT OR REPLACE INTO shop_profile (user_id, data) VALUES (?,?)', [uid(), json_encode(array_replace(shop(), $data))]); }
function money(?float $v, ?array $s = null): string {
    $c = ($s ?? shop())['currency'] ?: '$';
    return ($v < 0 ? '-' : '') . $c . number_format(abs((float)$v), 2);
}
/** Sell price from cost by the shop's markup matrix. */
function matrix_price(float $cost, ?array $s = null): float {
    $pct = 0;
    foreach (($s ?? shop())['matrix'] as [$from, $m]) if ($cost >= (float)$from) $pct = (float)$m;
    return round($cost * (1 + $pct / 100), 2);
}
function next_number(string $kind): string {
    $s = shop(); $k = $kind === 'inv' ? 'next_inv' : 'next_est'; $n = (int)$s[$k];
    shop_save([$k => $n + 1]);
    return ($kind === 'inv' ? $s['inv_prefix'] : $s['est_prefix']) . $n;
}

/* ---------- Totals ---------- */

/**
 * Money for a repair order. $scope: 'quoted' (everything not declined: what the estimate shows) or 'approved'
 * (only authorized lines: what the work order and invoice charge).
 */
function job_totals(array $job, array $lines, string $scope = 'quoted'): array {
    $t = ['labor' => 0.0, 'parts' => 0.0, 'fees' => 0.0, 'sublet' => 0.0, 'core' => 0.0, 'hours' => 0.0, 'cost' => 0.0, 'tax' => 0.0, 'supplies' => 0.0];
    $taxParts = 0.0; $taxLabor = 0.0; $taxFees = 0.0;
    foreach ($lines as $l) {
        if ($l['status'] === 'declined' || ($scope === 'approved' && $l['status'] !== 'approved')) continue;
        $amt = round((float)$l['qty'] * (float)$l['price'], 2);
        $cost = (float)$l['qty'] * (float)$l['cost'];
        switch ($l['kind']) {
            case 'labor': $t['labor'] += $amt; $t['hours'] += (float)$l['qty']; if ($l['taxable']) $taxLabor += $amt; break;
            case 'part': $t['parts'] += $amt; $t['cost'] += $cost; if ($l['taxable']) $taxParts += $amt; break;
            case 'core': $t['core'] += $amt; if ($l['taxable']) $taxParts += $amt; break;
            case 'sublet': $t['sublet'] += $amt; $t['cost'] += $cost; if ($l['taxable']) $taxLabor += $amt; break;
            case 'fee': $t['fees'] += $amt; if ($l['taxable']) $taxFees += $amt; break;
        }
    }
    $pct = (float)($job['supplies_pct'] ?? 0); $cap = (float)($job['supplies_cap'] ?? 0);
    $t['supplies'] = round(min($cap > 0 ? $cap : PHP_FLOAT_MAX, $t['labor'] * $pct / 100), 2);
    $sub = $t['labor'] + $t['parts'] + $t['fees'] + $t['sublet'] + $t['core'] + $t['supplies'];
    $disc = min((float)($job['discount'] ?? 0), $sub);
    // Discount reduces the taxable base proportionally.
    $ratio = $sub > 0 ? ($sub - $disc) / $sub : 1;
    $s = shop((int)$job['user_id']);
    $t['tax'] = round(($taxParts * (float)$job['tax_parts'] + ($taxLabor + $t['supplies']) * (float)$job['tax_labor'] + $taxFees * (float)$s['tax_fees']) / 100 * $ratio, 2);
    $t['subtotal'] = round($sub, 2); $t['discount'] = round($disc, 2);
    $t['total'] = round($sub - $disc + $t['tax'], 2);
    $t['profit'] = round($t['total'] - $t['tax'] - $t['cost'], 2);
    $t['margin'] = $sub - $disc > 0 ? round($t['profit'] / ($sub - $disc) * 100, 1) : 0;
    $t['elr'] = $t['hours'] > 0 ? round(($t['labor'] - $disc * ($t['labor'] / max(0.01, $sub))) / $t['hours'], 2) : 0; // effective labor rate
    return $t;
}
function job_lines(int $jobId): array { return rows('SELECT * FROM job_lines WHERE job_id=? ORDER BY sort, id', [$jobId]); }
/** Recompute and store the job total (shown on boards and customer lists) and the paid amount. */
function job_refresh(int $jobId): void {
    $j = row('SELECT * FROM jobs WHERE id=?', [$jobId]); if (!$j) return;
    $scope = in_array($j['stage'], ['estimate', 'declined'], true) ? 'quoted' : 'approved';
    $t = job_totals($j, job_lines($jobId), $scope);
    $paid = (float)col('SELECT COALESCE(SUM(amount),0) FROM job_payments WHERE job_id=?', [$jobId]);
    q('UPDATE jobs SET total=?, paid=?, updated_at=? WHERE id=?', [$t['total'], $paid, now(), $jobId]);
    if ($j['stage'] === 'invoiced' && $paid >= $t['total'] - 0.005 && $t['total'] > 0) q("UPDATE jobs SET stage='paid', paid_at=? WHERE id=?", [now(), $jobId]);
}
/** Authorized so far, and whether the current approved total needs additional authorization (e.g. CA: >10% or >$100 over). */
function job_auth_state(array $job, array $lines): array {
    $auth = (float)col('SELECT COALESCE(MAX(amount),0) FROM job_auth WHERE job_id=?', [$job['id']]);
    $now = job_totals($job, $lines, in_array($job['stage'], ['estimate', 'declined'], true) ? 'quoted' : 'approved')['total'];
    $s = shop((int)$job['user_id']);
    $limit = $auth + max($auth * (float)$s['auth_threshold_pct'] / 100, (float)$s['auth_threshold_amt']);
    return ['authorized' => $auth, 'current' => $now, 'over' => $auth > 0 && $now > $auth + 0.005, 'over_limit' => $auth > 0 && $now > $limit + 0.005];
}
function job_vehicle(array $job): array { return $job['vehicle_id'] ? (row('SELECT * FROM vehicle WHERE id=?', [(int)$job['vehicle_id']]) ?? []) : []; }
function job_customer(array $job): array { return $job['customer_id'] ? (row('SELECT * FROM customers WHERE id=?', [(int)$job['customer_id']]) ?? []) : []; }
function job_label(array $job): string { return $job['inv_no'] && in_array($job['stage'], ['invoiced', 'paid'], true) ? 'Invoice ' . $job['inv_no'] : 'RO ' . $job['est_no']; }

/* ---------- Canned jobs (typical labor; shops should check their labor guide) ---------- */

/** Built-in starter jobs: [category, name, [[kind, description, qty], ...]]. Hours are typical book times for common vehicles. */
function canned_defaults(): array {
    $L = fn(string $d, float $h) => ['labor', $d, $h]; $P = fn(string $d, float $q = 1) => ['part', $d, $q]; $F = fn(string $d, float $q = 1) => ['fee', $d, $q];
    return [
        ['Maintenance', 'Oil and filter change', [$L('Change engine oil and filter, check fluids and tire pressures', 0.5), $P('Engine oil (quarts, per spec)', 5), $P('Oil filter'), $F('Oil disposal')]],
        ['Maintenance', 'Engine air filter', [$L('Replace engine air filter', 0.2), $P('Engine air filter')]],
        ['Maintenance', 'Cabin air filter', [$L('Replace cabin air filter', 0.3), $P('Cabin air filter')]],
        ['Maintenance', 'Wiper blades', [$L('Replace wiper blades', 0.2), $P('Wiper blade, driver'), $P('Wiper blade, passenger')]],
        ['Maintenance', 'Spark plugs (4-cylinder)', [$L('Replace spark plugs', 1.0), $P('Spark plug', 4)]],
        ['Maintenance', 'Spark plugs (V6)', [$L('Replace spark plugs (rear bank access varies)', 2.5), $P('Spark plug', 6)]],
        ['Maintenance', 'Spark plugs (V8)', [$L('Replace spark plugs', 2.0), $P('Spark plug', 8)]],
        ['Maintenance', 'Coolant flush', [$L('Drain and refill / flush cooling system, bleed air', 1.0), $P('Coolant (gallon, per spec)', 2), $F('Coolant disposal')]],
        ['Maintenance', 'Brake fluid flush', [$L('Flush and bleed brake hydraulic system', 0.8), $P('Brake fluid (per spec)', 1)]],
        ['Maintenance', 'Automatic transmission fluid service', [$L('Drain/refill or exchange transmission fluid, replace filter where serviceable', 1.2), $P('Transmission fluid (quarts, per spec)', 6), $P('Transmission filter / pan gasket kit')]],
        ['Maintenance', 'Differential / transfer case fluid', [$L('Drain and refill differential fluid', 0.6), $P('Gear oil (quarts, per spec)', 2)]],
        ['Maintenance', 'Serpentine belt', [$L('Replace serpentine (drive) belt', 0.5), $P('Serpentine belt')]],
        ['Maintenance', 'Timing belt and water pump', [$L('Replace timing belt, tensioner, idlers and water pump', 4.0), $P('Timing belt kit with water pump'), $P('Coolant (gallon)', 2)]],
        ['Tires & alignment', 'Mount and balance 4 tires', [$L('Mount and balance 4 tires', 1.0), $P('Tire', 4), $P('TPMS service kit', 4), $F('Tire disposal', 4)]],
        ['Tires & alignment', 'Tire rotation', [$L('Rotate tires and set pressures', 0.5)]],
        ['Tires & alignment', 'Flat repair (plug-patch)', [$L('Dismount, plug-patch and balance tire', 0.5), $P('Plug-patch')]],
        ['Tires & alignment', 'Four-wheel alignment', [$L('Four-wheel alignment', 1.0)]],
        ['Brakes', 'Front brake pads and rotors', [$L('Replace front brake pads and rotors, service slides', 1.5), $P('Front brake pad set'), $P('Front brake rotor', 2), $P('Brake hardware kit')]],
        ['Brakes', 'Rear brake pads and rotors', [$L('Replace rear brake pads and rotors, service slides', 1.5), $P('Rear brake pad set'), $P('Rear brake rotor', 2), $P('Brake hardware kit')]],
        ['Brakes', 'Front brake pads only', [$L('Replace front brake pads, service slides', 1.0), $P('Front brake pad set')]],
        ['Brakes', 'Brake caliper (one)', [$L('Replace brake caliper and bleed', 1.0), $P('Brake caliper (reman)'), ['core', 'Caliper core charge', 1], $P('Brake fluid')]],
        ['Electrical', 'Battery replacement', [$L('Replace battery, clean terminals, test charging system', 0.4), $P('Battery'), ['core', 'Battery core charge', 1]]],
        ['Electrical', 'Alternator', [$L('Replace alternator', 1.8), $P('Alternator (reman)'), ['core', 'Alternator core charge', 1]]],
        ['Electrical', 'Starter', [$L('Replace starter motor', 1.5), $P('Starter (reman)'), ['core', 'Starter core charge', 1]]],
        ['Electrical', 'Electrical diagnosis', [$L('Electrical system diagnosis (first hour)', 1.0)]],
        ['Engine performance', 'Check engine light diagnosis', [$L('Scan codes, review freeze frame, pinpoint tests (first hour)', 1.0)]],
        ['Engine performance', 'Ignition coil (one)', [$L('Replace ignition coil', 0.5), $P('Ignition coil')]],
        ['Engine performance', 'Upstream O2 / air-fuel sensor', [$L('Replace upstream oxygen sensor', 0.6), $P('Oxygen sensor, upstream')]],
        ['Engine performance', 'Downstream O2 sensor', [$L('Replace downstream oxygen sensor', 0.6), $P('Oxygen sensor, downstream')]],
        ['Engine performance', 'MAF sensor', [$L('Replace mass airflow sensor', 0.3), $P('Mass airflow sensor')]],
        ['Engine performance', 'Throttle body service', [$L('Clean throttle body and perform idle relearn', 0.6), $P('Throttle body cleaner')]],
        ['Engine performance', 'Catalytic converter', [$L('Replace catalytic converter', 1.5), $P('Catalytic converter (legal for this vehicle)'), $P('Exhaust gaskets / hardware')]],
        ['Engine performance', 'EVAP smoke test', [$L('EVAP system smoke test and diagnosis', 1.0)]],
        ['Engine performance', 'EVAP purge valve', [$L('Replace EVAP purge solenoid', 0.6), $P('EVAP purge valve')]],
        ['Engine performance', 'Intake/vacuum leak smoke test', [$L('Intake and crankcase smoke test', 0.8)]],
        ['Cooling', 'Thermostat', [$L('Replace thermostat and gasket, refill and bleed', 1.2), $P('Thermostat / housing'), $P('Coolant (gallon)', 1)]],
        ['Cooling', 'Water pump', [$L('Replace water pump, refill and bleed', 2.5), $P('Water pump'), $P('Coolant (gallon)', 2)]],
        ['Cooling', 'Radiator', [$L('Replace radiator, refill and bleed', 2.0), $P('Radiator'), $P('Coolant (gallon)', 2)]],
        ['Cooling', 'Cooling fan', [$L('Replace electric cooling fan assembly', 1.2), $P('Cooling fan assembly')]],
        ['A/C', 'A/C performance check', [$L('A/C performance test and leak check', 0.5)]],
        ['A/C', 'A/C evacuate and recharge', [$L('Evacuate, vacuum test and recharge A/C', 1.0), $P('Refrigerant (oz/lb, per spec)', 1), $P('UV dye')]],
        ['Suspension & steering', 'Front struts (pair)', [$L('Replace front strut assemblies (pair)', 2.5), $P('Front strut assembly', 2)]],
        ['Suspension & steering', 'Rear shocks (pair)', [$L('Replace rear shocks (pair)', 1.0), $P('Rear shock absorber', 2)]],
        ['Suspension & steering', 'Sway bar links (pair)', [$L('Replace stabilizer links (pair)', 0.6), $P('Sway bar link', 2)]],
        ['Suspension & steering', 'Outer tie rod ends (pair)', [$L('Replace outer tie rod ends (pair)', 1.2), $P('Outer tie rod end', 2)]],
        ['Suspension & steering', 'Wheel bearing / hub (one)', [$L('Replace wheel hub and bearing assembly', 1.5), $P('Hub and bearing assembly')]],
        ['Suspension & steering', 'CV axle (one)', [$L('Replace CV axle shaft', 1.5), $P('CV axle (new or reman)'), ['core', 'Axle core charge', 1]]],
        ['Engine', 'Valve cover gasket', [$L('Replace valve cover gasket(s) and seals', 1.5), $P('Valve cover gasket set')]],
        ['Engine', 'Oil pan gasket', [$L('Replace oil pan gasket, refill oil', 2.5), $P('Oil pan gasket'), $P('Engine oil (quarts)', 5), $P('Oil filter')]],
        ['Inspection', 'Pre-purchase inspection', [$L('Pre-purchase inspection with road test and scan', 1.0)]],
        ['Inspection', 'Courtesy multi-point inspection', [$L('Multi-point vehicle inspection', 0.0)]],
        ['Motorcycle & scooter', 'Scooter/motorcycle oil change', [$L('Change oil (and screen/filter), check chain/belt and tires', 0.5), $P('4-stroke motorcycle oil (JASO MA)', 1), $P('Oil filter / screen gasket')]],
        ['Motorcycle & scooter', 'Scooter rear tire', [$L('Remove rear wheel, replace tire, balance', 1.0), $P('Rear tire'), $F('Tire disposal')]],
        ['Motorcycle & scooter', 'Scooter CVT service', [$L('Replace CVT belt and variator rollers, clean clutch', 1.5), $P('CVT drive belt'), $P('Variator roller set')]],
        ['Motorcycle & scooter', 'Carburetor clean', [$L('Remove, clean and adjust carburetor', 1.5), $P('Carburetor rebuild kit')]],
    ];
}
/** The account's canned jobs, seeding the starter set once. */
function canned_jobs(): array {
    if (!(int)col('SELECT COUNT(*) FROM canned_jobs WHERE user_id=?', [uid()])) {
        foreach (canned_defaults() as $i => [$cat, $name, $lines]) insert('canned_jobs', ['user_id' => uid(), 'category' => $cat, 'name' => $name, 'lines' => json_encode($lines), 'sort' => $i]);
    }
    return rows('SELECT * FROM canned_jobs WHERE user_id=? ORDER BY category, sort, name', [uid()]);
}
/** Add a canned job to a repair order as a new service group. Parts come in at no price until looked up (or from the catalog by name). */
function job_add_canned(array $job, array $cj, ?string $group = null): void {
    $g = $group ?: $cj['name']; $s = shop();
    $sort = (int)col('SELECT COALESCE(MAX(sort),0) FROM job_lines WHERE job_id=?', [$job['id']]);
    $status = in_array($job['stage'], ['estimate', 'declined'], true) ? 'pending' : 'approved';
    foreach (json_decode((string)$cj['lines'], true) ?: [] as $ln) {
        [$kind, $desc, $qty] = $ln + [2 => 1];
        $price = 0.0; $cost = (float)($ln[3] ?? 0);
        if ($kind === 'labor') $price = (float)$job['labor_rate'];
        elseif ($cost > 0) $price = (float)($ln[4] ?? matrix_price($cost, $s));
        insert('job_lines', ['job_id' => $job['id'], 'grp' => $g, 'kind' => $kind, 'description' => $desc, 'qty' => $qty, 'cost' => $cost, 'price' => $price,
            'taxable' => 1, 'status' => $status, 'pstatus' => 'quoted', 'sort' => ++$sort]);
    }
}
/** Suggested canned job names for a diagnosis (by code family). */
function diag_job_suggestions(array $codes, array $veh = []): array {
    $map = ['misfire_cyl' => 'Ignition coil (one)', 'coil_circuit' => 'Ignition coil (one)', 'o2_heater' => 'Upstream O2 / air-fuel sensor', 'o2_perf' => 'Upstream O2 / air-fuel sensor',
        'catalyst' => 'Catalytic converter', 'evap_small' => 'EVAP smoke test', 'evap_large' => 'EVAP smoke test', 'evap_purge' => 'EVAP purge valve', 'maf' => 'MAF sensor',
        'lean' => 'Intake/vacuum leak smoke test', 'idle_lean_rich' => 'Intake/vacuum leak smoke test', 'thermostat' => 'Thermostat', 'overheat' => 'Thermostat',
        'voltage_low' => 'Battery replacement', 'charging' => 'Alternator', 'iac_idle' => 'Throttle body service', 'throttle' => 'Throttle body service', 'misfire_random' => 'Spark plugs (4-cylinder)',
        'trans_ratio' => 'Automatic transmission fluid service', 'tcc' => 'Automatic transmission fluid service', 'fan_circuit' => 'Cooling fan', 'abs_wheel_speed' => 'Electrical diagnosis', 'can_bus' => 'Electrical diagnosis'];
    $out = [];
    $eng = strtolower((string)($veh['engine'] ?? '') . ' ' . (string)($veh['model'] ?? ''));
    $plugs = preg_match('/v8|8-cyl|\b8 cyl/', $eng) ? 'Spark plugs (V8)' : (preg_match('/v6|6-cyl|\b6 cyl|inline-?6|i6/', $eng) ? 'Spark plugs (V6)' : 'Spark plugs (4-cylinder)');
    foreach ($codes as $c) { $f = obd_codes_map()[$c][1] ?? null; if ($f && isset($map[$f])) $out[$f === 'misfire_random' ? $plugs : $map[$f]] = true; }
    return array_keys($out);
}

/* ---------- Parts search ---------- */

/** Supplier searches for a part on a vehicle (opens the supplier's own site; prices and availability are theirs). */
function parts_links(string $q, array $v = []): array {
    $veh = trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? ''));
    $full = trim($veh . ' ' . $q); $e = urlencode($full); $eq = urlencode($q);
    $ra = !empty($v['make']) && !empty($v['year']) ? 'https://www.rockauto.com/en/catalog/' . rawurlencode(strtolower((string)$v['make'])) . ',' . (int)$v['year'] . ',' . rawurlencode(strtolower((string)$v['model'])) : 'https://www.rockauto.com/';
    return [
        'US retail & online' => [['RockAuto (vehicle catalog)', $ra], ['AutoZone', "https://www.autozone.com/searchresult?searchText=$e"], ["O'Reilly", "https://www.oreillyauto.com/search?q=$e"],
            ['Advance Auto Parts', "https://shop.advanceautoparts.com/web/SearchResults?searchTerm=$e"], ['NAPA', "https://www.napaonline.com/en/search?text=$e"], ['CarParts.com', "https://www.carparts.com/search?q=$e"]],
        'European & specialty' => [['FCP Euro', "https://www.fcpeuro.com/products?keywords=$e"], ['ECS Tuning', "https://www.ecstuning.com/Search/SiteSearch/$eq/"], ['Pelican Parts', "https://www.pelicanparts.com/catalog/search.htm?search=$e"]],
        'Marketplaces' => [['eBay Motors', "https://www.ebay.com/sch/i.html?_nkw=$e"], ['Amazon', "https://www.amazon.com/s?k=$e"]],
        'Outside the US' => [['Autodoc (EU/UK)', "https://www.autodoc.co.uk/search?keyword=$e"], ['Euro Car Parts (UK)', "https://www.eurocarparts.com/search?q=$e"], ['MercadoLibre (LatAm)', 'https://listado.mercadolibre.com.mx/' . rawurlencode(str_replace(' ', '-', strtolower($full)))]],
        'Shop B2B (log in there)' => [['PartsTech', 'https://app.partstech.com/'], ['Nexpart', 'https://www.nexpart.com/'], ['WorldPac speedDIAL', 'https://speeddial.worldpac.com/']],
    ];
}

/* ---------- Vehicle inspection (DVI) ---------- */

const INSPECTION_TEMPLATE = [
    'Under hood' => ['Engine oil level/condition', 'Coolant level/condition', 'Brake fluid', 'Power steering fluid', 'Transmission fluid', 'Battery and terminals', 'Drive belt', 'Hoses', 'Engine air filter', 'Cabin air filter', 'Leaks (engine)'],
    'Brakes' => ['Front pads', 'Rear pads / shoes', 'Front rotors', 'Rear rotors', 'Brake lines and hoses', 'Parking brake'],
    'Tires & wheels' => ['Left front tire', 'Right front tire', 'Left rear tire', 'Right rear tire', 'Spare / repair kit', 'Tire pressures / TPMS'],
    'Steering & suspension' => ['Front shocks/struts', 'Rear shocks/struts', 'Ball joints', 'Tie rod ends', 'Control arm bushings', 'Sway bar links', 'CV boots / axles', 'Wheel bearings'],
    'Underbody' => ['Exhaust system', 'Leaks (transmission, differential)', 'Fuel lines', 'Frame / rust'],
    'Lights & electrical' => ['Headlights', 'Brake lights', 'Turn signals', 'Warning lights on dash', 'Horn'],
    'Visibility & comfort' => ['Wiper blades', 'Windshield', 'A/C operation', 'Heater operation'],
];
const INSPECT_RATINGS = ['good' => 'OK', 'soon' => 'Needs attention soon', 'now' => 'Needs attention now', 'na' => 'Not checked'];
