<?php
// Diagnostics workflow (2026-11-08): worldwide VIN identification, scan-report import (text, CSV, PDF, photo),
// owner-complaint data, the DIY / Mechanic mode switch, customers and saved diagnostic reports.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

function migrate_diag(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('customer_id', $cols('vehicle'), true)) $pdo->exec('ALTER TABLE vehicle ADD COLUMN customer_id INT DEFAULT NULL');
    $uc = $cols('users');
    foreach (['mode' => "TEXT DEFAULT 'diy'", 'is_guest' => 'INT DEFAULT 0', 'guest_expires' => 'INT'] as $c => $t) if (!in_array($c, $uc, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $t");
}

/* ---------- Guest accounts ---------- */

function is_guest(): bool { return MULTI_USER && (bool)(current_user()['is_guest'] ?? false); }
/** Delete one account and everything in it (also used for expired guests). */
function account_wipe(int $userId): void {
    foreach (rows('SELECT id FROM vehicle WHERE user_id=?', [$userId]) as $dv) delete_vehicle((int)$dv['id']);
    foreach (['job_lines', 'job_payments', 'job_auth', 'job_inspect'] as $t) q("DELETE FROM $t WHERE job_id IN (SELECT id FROM jobs WHERE user_id=?)", [$userId]);
    foreach (['mechanics', 'links', 'customers', 'diag_reports', 'tool_links', 'jobs', 'canned_jobs', 'parts_catalog', 'shop_profile'] as $t) q("DELETE FROM $t WHERE user_id=?", [$userId]);
    q('DELETE FROM users WHERE id=?', [$userId]);
}
function guests_purge(): void {
    foreach (rows('SELECT id FROM users WHERE is_guest=1 AND guest_expires < ? LIMIT 50', [time()]) as $g) account_wipe((int)$g['id']);
}

/* ---------- DIY / Mechanic mode ---------- */

function ui_mode(?string $set = null): string {
    if ($set !== null && in_array($set, ['diy', 'pro'], true)) {
        $_SESSION['mode'] = $set;
        if (MULTI_USER && uid()) q('UPDATE users SET mode=? WHERE id=?', [$set, uid()]);
        elseif (!MULTI_USER) q("INSERT OR REPLACE INTO settings (key, value) VALUES ('mode', ?)", [$set]);
        return $set;
    }
    if (!empty($_SESSION['mode'])) return $_SESSION['mode'];
    $m = MULTI_USER ? (string)(current_user()['mode'] ?? 'diy') : (string)(col("SELECT value FROM settings WHERE key='mode'") ?: 'diy');
    return $_SESSION['mode'] = in_array($m, ['diy', 'pro'], true) ? $m : 'diy';
}
function is_pro(): bool { return ui_mode() === 'pro'; }

/* ---------- Worldwide VIN identification (ISO 3779 WMI) ---------- */

function vin_country(string $vin): string {
    $a = $vin[0]; $b = $vin[1];
    $in = fn(string $from, string $to) => strcmp($b, $from) >= 0 && strcmp($b, $to) <= 0;
    // ISO 3779 orders the second character A-Z then 1-9, 0; map digits after letters for range checks.
    $ord = fn(string $c) => ctype_digit($c) ? ($c === '0' ? 36 : 26 + (int)$c) : ord($c) - 64;
    $r = fn(string $from, string $to) => $ord($b) >= $ord($from) && $ord($b) <= $ord($to);
    return match (true) {
        in_array($a, ['1', '4', '5'], true) => 'United States',
        $a === '2' => 'Canada', $a === '3' && $r('A', 'W') => 'Mexico', $a === '3' => 'Costa Rica / Caribbean',
        $a === '6' => 'Australia', $a === '7' && $r('A', 'E') => 'New Zealand', $a === '7' => 'United States',
        $a === '8' && $r('A', 'E') => 'Argentina', $a === '8' && $r('F', 'K') => 'Chile', $a === '8' && $r('L', 'R') => 'Ecuador', $a === '8' && $r('S', 'W') => 'Peru', $a === '8' => 'Venezuela',
        $a === '9' && ($r('A', 'E') || $r('3', '9')) => 'Brazil', $a === '9' && $r('F', 'J') => 'Colombia', $a === '9' => 'South America',
        $a === 'A' && $r('A', 'H') => 'South Africa', $a === 'A' => 'Africa',
        $a === 'J' => 'Japan', $a === 'K' && $r('L', 'R') => 'South Korea', $a === 'K' && $r('A', 'E') => 'Sri Lanka', $a === 'K' && $r('F', 'K') => 'Israel', $a === 'K' => 'Asia',
        $a === 'L' => 'China', $a === 'M' && $r('A', 'E') => 'India', $a === 'M' && $r('F', 'K') => 'Indonesia', $a === 'M' && $r('L', 'R') => 'Thailand', $a === 'M' => 'Asia',
        $a === 'N' && $r('L', 'R') => 'Turkey', $a === 'N' && $r('F', 'K') => 'Pakistan', $a === 'N' && $r('A', 'E') => 'Iran', $a === 'N' => 'Asia',
        $a === 'P' && $r('A', 'E') => 'Philippines', $a === 'P' && $r('F', 'K') => 'Singapore', $a === 'P' && $r('L', 'R') => 'Malaysia', $a === 'P' => 'Asia',
        $a === 'R' && $r('A', 'E') => 'United Arab Emirates', $a === 'R' && $r('F', 'K') => 'Taiwan', $a === 'R' && $r('L', 'R') => 'Vietnam', $a === 'R' => 'Asia',
        $a === 'S' && $r('A', 'M') => 'United Kingdom', $a === 'S' && $r('N', 'T') => 'Germany', $a === 'S' && $r('U', 'Z') => 'Poland', $a === 'S' => 'Latvia',
        $a === 'T' && $r('A', 'H') => 'Switzerland', $a === 'T' && $r('J', 'P') => 'Czech Republic', $a === 'T' && $r('R', 'V') => 'Hungary', $a === 'T' && $r('W', '1') => 'Portugal', $a === 'T' => 'Europe',
        $a === 'U' && $r('H', 'M') => 'Denmark', $a === 'U' && $r('N', 'R') => 'Ireland', $a === 'U' && $r('U', 'Z') => 'Romania', $a === 'U' && $r('5', '7') => 'Slovakia', $a === 'U' => 'Europe',
        $a === 'V' && $r('A', 'E') => 'Austria', $a === 'V' && $r('F', 'R') => 'France', $a === 'V' && $r('S', 'W') => 'Spain', $a === 'V' && $r('X', '2') => 'Serbia', $a === 'V' && $r('3', '5') => 'Croatia', $a === 'V' => 'Europe',
        $a === 'W' => 'Germany', $a === 'X' && $r('A', 'E') => 'Bulgaria', $a === 'X' && $r('F', 'K') => 'Greece', $a === 'X' && $r('L', 'R') => 'Netherlands', $a === 'X' && ($r('S', 'W') || $r('3', '0')) => 'Russia', $a === 'X' => 'Europe',
        $a === 'Y' && $r('A', 'E') => 'Belgium', $a === 'Y' && $r('F', 'K') => 'Finland', $a === 'Y' && $r('S', 'W') => 'Sweden', $a === 'Y' && $r('X', '2') => 'Norway', $a === 'Y' && $r('3', '5') => 'Belarus', $a === 'Y' && $r('6', '8') => 'Ukraine', $a === 'Y' => 'Europe',
        $a === 'Z' && $r('A', 'R') => 'Italy', $a === 'Z' && $r('X', '2') => 'Slovenia', $a === 'Z' => 'Europe',
        default => 'Unknown',
    };
}
/** Manufacturer by WMI (first 3, else first 2 characters of the VIN). Covers the large makers worldwide. */
function vin_wmi_maker(string $vin): string {
    static $w = [
        // North America
        '1FA' => 'Ford', '1FB' => 'Ford', '1FC' => 'Ford', '1FD' => 'Ford', '1FM' => 'Ford', '1FT' => 'Ford', '1FU' => 'Freightliner', '1G1' => 'Chevrolet', '1GC' => 'Chevrolet', '1GN' => 'Chevrolet', '1G4' => 'Buick', '1G6' => 'Cadillac', '1GT' => 'GMC', '1GK' => 'GMC', '1GY' => 'Cadillac',
        '1C3' => 'Chrysler', '1C4' => 'Jeep / Chrysler', '1C6' => 'Ram', '1J4' => 'Jeep', '1J8' => 'Jeep', '1D7' => 'Dodge', '1B3' => 'Dodge', '1HG' => 'Honda', '1HD' => 'Harley-Davidson', '1N4' => 'Nissan', '1N6' => 'Nissan', '1VW' => 'Volkswagen', '1YV' => 'Mazda', '1ZV' => 'Ford (AutoAlliance)', '1LN' => 'Lincoln', '1ME' => 'Mercury',
        '2FA' => 'Ford', '2FM' => 'Ford', '2FT' => 'Ford', '2G1' => 'Chevrolet', '2GT' => 'GMC', '2C3' => 'Chrysler / Dodge', '2C4' => 'Chrysler', '2D3' => 'Dodge', '2HG' => 'Honda', '2HK' => 'Honda', '2HJ' => 'Honda', '2HN' => 'Acura', '2T1' => 'Toyota', '2T2' => 'Lexus', '2T3' => 'Toyota', '2LM' => 'Lincoln',
        '3FA' => 'Ford', '3FE' => 'Ford', '3G1' => 'Chevrolet', '3GC' => 'Chevrolet', '3GN' => 'Chevrolet', '3GT' => 'GMC', '3HG' => 'Honda', '3N1' => 'Nissan', '3N6' => 'Nissan', '3VW' => 'Volkswagen', '3KP' => 'Kia', '3MZ' => 'Mazda', '3MV' => 'Mazda', '3TM' => 'Toyota', '3TY' => 'Toyota', '3C4' => 'Chrysler', '3C6' => 'Ram', '3D7' => 'Dodge', '3LN' => 'Lincoln', '3H1' => 'Honda',
        '4T1' => 'Toyota', '4T3' => 'Toyota', '4T4' => 'Toyota', '4TA' => 'Toyota', '4S3' => 'Subaru', '4S4' => 'Subaru', '4JG' => 'Mercedes-Benz', '4US' => 'BMW', '4A3' => 'Mitsubishi', '4F2' => 'Mazda', '4V4' => 'Volvo Trucks', '4M2' => 'Mercury',
        '5YJ' => 'Tesla', '7SA' => 'Tesla', '5UX' => 'BMW', '5UM' => 'BMW M', '5N1' => 'Nissan', '5FN' => 'Honda', '5J6' => 'Honda', '5J8' => 'Acura', '5NP' => 'Hyundai', '5NM' => 'Hyundai', '5XY' => 'Kia', '5XX' => 'Kia', '5TD' => 'Toyota', '5TF' => 'Toyota', '5TE' => 'Toyota', '5LM' => 'Lincoln', '5GA' => 'Buick', '5YF' => 'Toyota', '5YM' => 'BMW M', '7FA' => 'Honda', '7MU' => 'Toyota', '7G2' => 'Tesla',
        // Japan
        'JA3' => 'Mitsubishi', 'JA4' => 'Mitsubishi', 'JA7' => 'Mitsubishi', 'JAA' => 'Isuzu', 'JAL' => 'Isuzu', 'JF1' => 'Subaru', 'JF2' => 'Subaru', 'JH2' => 'Honda (motorcycle)', 'JH4' => 'Acura', 'JHM' => 'Honda', 'JHL' => 'Honda', 'JHG' => 'Honda', 'JKA' => 'Kawasaki', 'JKB' => 'Kawasaki',
        'JM1' => 'Mazda', 'JM3' => 'Mazda', 'JMZ' => 'Mazda', 'JN1' => 'Nissan', 'JN8' => 'Nissan', 'JNK' => 'Infiniti', 'JNR' => 'Infiniti', 'JS1' => 'Suzuki (motorcycle)', 'JS2' => 'Suzuki', 'JS3' => 'Suzuki', 'JSA' => 'Suzuki',
        'JT2' => 'Toyota', 'JT3' => 'Toyota', 'JT4' => 'Toyota', 'JT6' => 'Lexus', 'JT8' => 'Lexus', 'JTD' => 'Toyota', 'JTE' => 'Toyota', 'JTK' => 'Toyota (Scion)', 'JTL' => 'Toyota', 'JTM' => 'Toyota', 'JTN' => 'Toyota', 'JTH' => 'Lexus', 'JTJ' => 'Lexus', 'JYA' => 'Yamaha', 'JYE' => 'Yamaha',
        // Korea
        'KL1' => 'GM Korea (Chevrolet/Daewoo)', 'KL4' => 'Buick (GM Korea)', 'KL7' => 'Chevrolet (GM Korea)', 'KLA' => 'Daewoo', 'KMH' => 'Hyundai', 'KM8' => 'Hyundai', 'KMF' => 'Hyundai', 'KMT' => 'Genesis', 'KNA' => 'Kia', 'KNC' => 'Kia', 'KND' => 'Kia', 'KNE' => 'Kia', 'KNM' => 'Renault Samsung', 'KPT' => 'SsangYong', 'KPA' => 'SsangYong',
        // China
        'LFV' => 'FAW-Volkswagen', 'LSV' => 'SAIC Volkswagen', 'LSG' => 'SAIC-GM', 'LVS' => 'Changan Ford', 'LVG' => 'GAC Toyota', 'LFM' => 'FAW Toyota', 'LHG' => 'GAC Honda', 'LUC' => 'Dongfeng Honda', 'LDC' => 'Dongfeng Peugeot-Citroen', 'LGB' => 'Dongfeng Nissan', 'LBV' => 'BMW Brilliance',
        'LE4' => 'Beijing Benz', 'LRW' => 'Tesla (Shanghai)', 'LYV' => 'Volvo (China)', 'LGX' => 'BYD', 'LC0' => 'BYD', 'LB3' => 'Geely', 'L6T' => 'Geely', 'LBE' => 'Beijing Hyundai', 'LJD' => 'Dongfeng Yueda Kia', 'LS5' => 'Changan', 'LSJ' => 'SAIC MG / Roewe', 'LNB' => 'BAIC', 'LGW' => 'Great Wall', 'LVV' => 'Chery', 'LPS' => 'Polestar', 'LRB' => 'Buick (SAIC-GM)', 'LZW' => 'SAIC-GM-Wuling', 'LMG' => 'GAC Motor', 'LNP' => 'NIO', 'LDP' => 'Voyah', 'LFP' => 'FAW', 'LXV' => 'Xpeng',
        // India, SE Asia
        'MA1' => 'Mahindra', 'MA3' => 'Maruti Suzuki', 'MBH' => 'Maruti Suzuki', 'MAL' => 'Hyundai (India)', 'MAT' => 'Tata', 'MAJ' => 'Ford (India)', 'MBJ' => 'Toyota (India)', 'MEE' => 'Renault (India)', 'MD2' => 'Bajaj', 'ME4' => 'Honda (motorcycle, India)', 'MBL' => 'Hero',
        'MHF' => 'Toyota (Indonesia)', 'MHR' => 'Honda (Indonesia)', 'MH1' => 'Honda (motorcycle, Indonesia)', 'MNB' => 'Ford (Thailand)', 'MR0' => 'Toyota (Thailand)', 'MMT' => 'Mitsubishi (Thailand)', 'MRH' => 'Honda (Thailand)', 'MPA' => 'Isuzu (Thailand)', 'MLH' => 'Honda (motorcycle, Thailand)', 'MM7' => 'Mazda (Thailand)', 'PL1' => 'Proton', 'PM2' => 'Perodua', 'RFB' => 'Kymco', 'RFG' => 'SYM',
        // Europe
        'WAU' => 'Audi', 'WA1' => 'Audi', 'WUA' => 'Audi Sport', 'WBA' => 'BMW', 'WBS' => 'BMW M', 'WBX' => 'BMW', 'WBY' => 'BMW i', 'WB1' => 'BMW (motorcycle)', 'WDB' => 'Mercedes-Benz', 'WDD' => 'Mercedes-Benz', 'WDC' => 'Mercedes-Benz', 'WDF' => 'Mercedes-Benz (van)', 'WD3' => 'Mercedes-Benz (van)', 'WD4' => 'Mercedes-Benz (van)',
        'W1K' => 'Mercedes-Benz', 'W1N' => 'Mercedes-Benz', 'W1V' => 'Mercedes-Benz (van)', 'W1W' => 'Mercedes-Benz (van)', 'WMW' => 'MINI', 'WME' => 'smart', 'WP0' => 'Porsche', 'WP1' => 'Porsche', 'WVW' => 'Volkswagen', 'WVG' => 'Volkswagen', 'WV1' => 'Volkswagen (commercial)', 'WV2' => 'Volkswagen (bus/van)',
        'WF0' => 'Ford (Germany)', 'WF1' => 'Ford (Germany)', 'W0L' => 'Opel / Vauxhall', 'W0V' => 'Opel / Vauxhall', 'WMA' => 'MAN', 'WKK' => 'Karl Kassbohrer (Setra)', 'W09' => 'Small manufacturer (Germany)',
        'VF1' => 'Renault', 'VF3' => 'Peugeot', 'VF7' => 'Citroen', 'VR3' => 'Peugeot', 'VR7' => 'Citroen', 'VR1' => 'DS', 'VF6' => 'Renault Trucks', 'VNK' => 'Toyota (France)', 'VSS' => 'SEAT', 'VSA' => 'Mercedes-Benz (Spain)', 'VS6' => 'Ford (Spain)', 'VWV' => 'Volkswagen (Spain)', 'VSK' => 'Nissan (Spain)', 'VSE' => 'Suzuki (Santana)', 'VBK' => 'KTM', 'VTH' => 'Derbi',
        'ZFA' => 'Fiat', 'ZFF' => 'Ferrari', 'ZAR' => 'Alfa Romeo', 'ZAM' => 'Maserati', 'ZHW' => 'Lamborghini', 'ZLA' => 'Lancia', 'ZAC' => 'Jeep / Fiat (Italy)', 'ZCF' => 'Iveco', 'ZDM' => 'Ducati', 'ZAP' => 'Piaggio / Vespa', 'ZD4' => 'Aprilia', 'ZCG' => 'MV Agusta', 'ZGU' => 'Moto Guzzi',
        'SAJ' => 'Jaguar', 'SAD' => 'Jaguar', 'SAL' => 'Land Rover', 'SAR' => 'Rover', 'SCC' => 'Lotus', 'SCF' => 'Aston Martin', 'SCB' => 'Bentley', 'SCA' => 'Rolls-Royce', 'SBM' => 'McLaren', 'SHH' => 'Honda (UK)', 'SHS' => 'Honda (UK)', 'SJN' => 'Nissan (UK)', 'SB1' => 'Toyota (UK)', 'SMT' => 'Triumph', 'SFD' => 'Alexander Dennis', 'SAT' => 'Triumph',
        'TMB' => 'Skoda', 'TMA' => 'Hyundai (Czech)', 'TMK' => 'Karosa', 'TRU' => 'Audi (Hungary)', 'TSM' => 'Suzuki (Hungary)', 'U5Y' => 'Kia (Slovakia)', 'UU1' => 'Dacia', 'UU6' => 'Daewoo (Romania)', 'XTA' => 'Lada (AvtoVAZ)', 'XW8' => 'Volkswagen (Russia)', 'X7L' => 'Renault (Russia)', 'XLR' => 'DAF',
        'YV1' => 'Volvo', 'YV4' => 'Volvo', 'YV2' => 'Volvo Trucks', 'YS3' => 'Saab', 'YS2' => 'Scania', 'YK1' => 'Saab-Valmet', 'NMT' => 'Toyota (Turkey)', 'NM0' => 'Ford (Turkey)', 'NM4' => 'Tofas (Fiat Turkey)', 'NLA' => 'Honda (Turkey)', 'NLH' => 'Hyundai (Turkey)', 'NMA' => 'MAN (Turkey)',
        // Latin America, Africa, Oceania
        '9BW' => 'Volkswagen (Brazil)', '9BG' => 'Chevrolet (Brazil)', '9BD' => 'Fiat (Brazil)', '93H' => 'Honda (Brazil)', '9BR' => 'Toyota (Brazil)', '93Y' => 'Renault (Brazil)', '9BF' => 'Ford (Brazil)', '94D' => 'Nissan (Brazil)', '9C2' => 'Honda (motorcycle, Brazil)', '9BM' => 'Mercedes-Benz (Brazil)', '936' => 'Peugeot (Brazil)', '935' => 'Citroen (Brazil)', '9BH' => 'Hyundai (Brazil)', '9C6' => 'Yamaha (Brazil)',
        '8AP' => 'Fiat (Argentina)', '8AJ' => 'Toyota (Argentina)', '8AF' => 'Ford (Argentina)', '8AG' => 'Chevrolet (Argentina)', '8AW' => 'Volkswagen (Argentina)', '8AD' => 'Peugeot (Argentina)', '8A1' => 'Renault (Argentina)',
        'AAV' => 'Volkswagen (South Africa)', 'AHT' => 'Toyota (South Africa)', 'AFA' => 'Ford (South Africa)', 'ADM' => 'GM (South Africa)', 'ADN' => 'Nissan (South Africa)', 'AC5' => 'Hyundai (South Africa)',
        '6G1' => 'Holden', '6G2' => 'Pontiac (Holden)', '6FP' => 'Ford (Australia)', '6T1' => 'Toyota (Australia)', '6MM' => 'Mitsubishi (Australia)', '6U9' => 'Small manufacturer (Australia)',
    ];
    return $w[substr($vin, 0, 3)] ?? $w[substr($vin, 0, 2)] ?? '';
}
/** Model-year guess from position 10 (reliable for North American VINs; most others since about 2010). */
function vin_year_guess(string $vin): ?int {
    $codes = 'ABCDEFGHJKLMNPRSTVWXY123456789';
    $p = strpos($codes, $vin[9]);
    if ($p === false) return null;
    $y = 1980 + $p;
    // Position 7 is a letter on 2010+ North American passenger vehicles; for others pick the most recent plausible cycle.
    if (ctype_alpha($vin[6]) || $y + 30 <= (int)date('Y') + 1) $y += 30;
    return $y <= (int)date('Y') + 1 ? $y : $y - 30;
}
/** VIN decode that works for any vehicle in the world: NHTSA vPIC first, then the WMI tables. */
function vin_decode_world(string $vin): array {
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $vin));
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $clean)) {
        // Japanese-market and older vehicles use shorter chassis/frame numbers like "ZZT231-0012345".
        if (preg_match('/^[A-Z0-9]{2,8}-?\d{5,8}$/', $clean)) return ['vin' => $clean, 'country' => 'Japan (chassis number)', 'error' => 'This looks like a Japanese chassis (frame) number, not a 17-character VIN. The part before the dash is the model code. Enter the make and model yourself; the codes and checklists still work.'];
        return ['error' => 'A VIN is 17 letters and digits (never I, O or Q). Older and Japanese-market vehicles may have a shorter chassis number: enter the make and model yourself.'];
    }
    $r = vin_decode($clean);
    $r['vin'] = $clean;
    $r['country'] = vin_country($clean);
    $maker = vin_wmi_maker($clean);
    if (empty($r['make']) && $maker) {
        $r['make'] = preg_replace('/\s*\(.*\)$/', '', $maker);
        $r['year'] = $r['year'] ?: (string)(vin_year_guess($clean) ?? '');
        $r['error'] = "Identified from the VIN's manufacturer code: " . $maker . ', built in ' . $r['country'] . '. Add the model yourself.';
    } elseif (empty($r['make'])) {
        $r['error'] = 'Built in ' . $r['country'] . '. This manufacturer code is not in our tables yet: enter the make and model yourself.';
    }
    $r['maker'] = $maker;
    return $r;
}

/* ---------- Owner complaints (NHTSA, US models) ---------- */

/** Complaints owners filed with NHTSA for a year/make/model, grouped by component. Cached 30 days. */
function vehicle_complaints(array $v): ?array {
    $year = (int)($v['year'] ?? 0); $make = strtoupper(trim((string)($v['make'] ?? ''))); $model = trim((string)($v['model'] ?? ''));
    if ($year < 1980 || $make === '' || $model === '') return null;
    $key = 'complaints:' . $year . ':' . $make . ':' . strtoupper($model);
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 30 * 86400) return json_decode((string)$c['body'], true);
    $list = null;
    $pairs = nhtsa_models($year, $make, $model, 'c') ?: array_map(fn($mk) => [$mk, $model], array_unique([$make, str_replace('-', ' ', $make)]));
    foreach ($pairs as [$mk, $md]) {
        $b = http_get('https://api.nhtsa.gov/complaints/complaintsByVehicle?' . http_build_query(['make' => $mk, 'model' => $md, 'modelYear' => $year]), 20);
        $j = $b ? json_decode($b, true) : null;
        if (!is_array($j)) continue;
        $list = $j['results'] ?? [];
        if ($list) break;
    }
    if ($list === null) return null;
    $by = [];
    foreach ($list as $x) foreach (array_filter(array_map('trim', explode(',', (string)($x['components'] ?? '')))) as $comp) {
        $by[$comp]['n'] = ($by[$comp]['n'] ?? 0) + 1;
        if (($x['crash'] ?? false) || ($x['fire'] ?? false)) $by[$comp]['serious'] = ($by[$comp]['serious'] ?? 0) + 1;
        if (count($by[$comp]['samples'] ?? []) < 3 && strlen((string)($x['summary'] ?? '')) > 60) $by[$comp]['samples'][] = mb_strimwidth(trim((string)$x['summary']), 0, 360, '…');
    }
    uasort($by, fn($a, $b) => $b['n'] <=> $a['n']);
    $out = ['total' => count($list), 'components' => array_slice($by, 0, 12, true)];
    q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, json_encode($out), time()]);
    return $out;
}
/** Which NHTSA complaint components relate to a code's system, to show the most relevant owner reports first. */
function complaint_components_for(array $codes): array {
    $m = [];
    foreach ($codes as $c) {
        $f = obd_codes_map()[$c][1] ?? '';
        $m = array_merge($m, match (true) {
            str_starts_with($f, 'trans') || in_array($f, ['tcm_request', 'tcc', 'shift_solenoid'], true) => ['POWER TRAIN'],
            in_array($f, ['srs'], true) => ['AIR BAGS'],
            in_array($f, ['abs_wheel_speed'], true) => ['SERVICE BRAKES', 'ELECTRONIC STABILITY CONTROL'],
            in_array($f, ['can_bus', 'can_data', 'voltage_low', 'voltage_high', 'charging', 'ecu_internal'], true) => ['ELECTRICAL SYSTEM'],
            in_array($f, ['fuel_pressure_low', 'fuel_pressure_high', 'fuel_pump_circuit', 'fuel_pressure_sensor'], true) => ['FUEL/PROPULSION SYSTEM', 'FUEL SYSTEM, GASOLINE', 'FUEL SYSTEM, DIESEL'],
            in_array($f, ['overheat', 'thermostat', 'fan_circuit'], true) => ['ENGINE AND ENGINE COOLING', 'ENGINE'],
            default => ['ENGINE', 'ENGINE AND ENGINE COOLING', 'FUEL/PROPULSION SYSTEM'],
        });
    }
    return array_values(array_unique($m));
}

/** Official recall lookups outside the US (NHTSA covers US-market vehicles only). */
function recall_links_world(): array {
    return [
        ['United States', 'https://www.nhtsa.gov/recalls'], ['Canada', 'https://tc.canada.ca/en/road-transportation/motor-vehicle-safety/motor-vehicle-safety-recalls-database'],
        ['United Kingdom', 'https://www.check-vehicle-recalls.service.gov.uk/'], ['Australia', 'https://www.vehiclerecalls.gov.au/'], ['European Union', 'https://ec.europa.eu/safety-gate-alerts/'],
        ['Mexico', 'https://www.gob.mx/profeco'],
    ];
}

/* ---------- Scan report import ---------- */

/** Best-effort text from a PDF without external tools: inflates streams, reads text operators, and decodes
 *  font-encoded (hex) strings through the fonts' ToUnicode maps, which is how most generated reports store text. */
function pdf_text(string $pdf): string {
    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $mm)) return '';
    $streams = [];
    foreach ($mm[1] as $s) { $d = @gzuncompress($s); if ($d === false) $d = @gzinflate(substr($s, 2)); $streams[] = $d === false ? $s : $d; }
    // ToUnicode CMaps (merged; enough for scan reports that use one or two fonts).
    $map = []; $klen = 4;
    foreach ($streams as $d) {
        if (!str_contains($d, 'beginbf')) continue;
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $d, $bc)) foreach ($bc[1] as $blk) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $pp, PREG_SET_ORDER);
            foreach ($pp as $p) { $map[strtoupper($p[1])] = $p[2]; $klen = strlen($p[1]); }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $d, $br)) foreach ($br[1] as $blk) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $pp, PREG_SET_ORDER);
            foreach ($pp as $p) { $a = hexdec($p[1]); $b = hexdec($p[2]); $u = hexdec($p[3]); $klen = strlen($p[1]);
                for ($i = 0; $i <= min($b - $a, 2000); $i++) $map[strtoupper(str_pad(dechex($a + $i), $klen, '0', STR_PAD_LEFT))] = str_pad(dechex($u + $i), 4, '0', STR_PAD_LEFT); }
        }
    }
    $utf = fn(string $hex) => mb_convert_encoding((string)hex2bin(strlen($hex) % 4 ? str_pad($hex, (int)ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT) : $hex), 'UTF-8', 'UTF-16BE');
    $hexStr = function (string $h) use ($map, $klen, $utf): string {
        $h = strtoupper(preg_replace('/\s+/', '', $h)); $o = '';
        if (!$map) return (string)@hex2bin($h);
        for ($i = 0; $i + $klen <= strlen($h); $i += $klen) { $k = substr($h, $i, $klen); $o .= isset($map[$k]) ? $utf($map[$k]) : ''; }
        return $o;
    };
    $out = '';
    foreach ($streams as $d) {
        if (!preg_match('/\b(Tj|TJ)\b/', $d)) continue;
        preg_match_all('/\[(.*?)\]\s*TJ|\((.*?)(?<!\\\\)\)\s*Tj|<([0-9A-Fa-f\s]+)>\s*Tj|(T\*|Td|TD|ET|Tm)\b/s', $d, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            if (!empty($op[4])) { $out .= in_array($op[4], ['Td', 'TD', 'Tm'], true) ? ' ' : "\n"; continue; }
            if (isset($op[3]) && $op[3] !== '') { $out .= $hexStr($op[3]); continue; }
            if (isset($op[1]) && $op[1] !== '') {
                preg_match_all('/\((.*?)(?<!\\\\)\)|<([0-9A-Fa-f\s]+)>|(-?\d+(?:\.\d+)?)/s', $op[1], $parts, PREG_SET_ORDER);
                foreach ($parts as $p) {
                    if (isset($p[2]) && $p[2] !== '') $out .= $hexStr($p[2]);
                    elseif (isset($p[1]) && $p[1] !== '') $out .= stripcslashes($p[1]);
                    elseif (isset($p[3]) && (float)$p[3] < -200) $out .= ' ';
                }
            } elseif (isset($op[2])) $out .= stripcslashes($op[2]);
        }
        $out .= "\n";
    }
    return $out;
}
/** CSV log (e.g. Torque Pro, OBD Fusion): turn the columns into "name: median" lines for the freeze-frame reader. */
function csv_to_freeze(string $csv): string {
    $lines = array_values(array_filter(preg_split('/\R/', $csv), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) return $csv;
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $head = str_getcsv($lines[0], $sep, '"', '\\'); $cols = [];
    foreach (array_slice($lines, 1, 5000) as $l) foreach (str_getcsv($l, $sep, '"', '\\') as $i => $v) if (is_numeric(trim((string)$v))) $cols[$i][] = (float)$v;
    $txt = '';
    foreach ($cols as $i => $vals) { if (!isset($head[$i])) continue; sort($vals); $txt .= trim($head[$i]) . ': ' . $vals[intdiv(count($vals), 2)] . "\n"; }
    return $txt . "\n" . $csv;
}
/**
 * Read an uploaded scan report. Returns ['text' => ..., 'via' => 'text|csv|pdf|photo', 'error' => ...].
 * Photos of a scanner screen are read by the garage-vision service (local vision model) when configured.
 */
function scan_upload_text(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) return ['text' => '', 'error' => 'The file did not upload.'];
    if ((int)$f['size'] > 15 * 1024 * 1024) return ['text' => '', 'error' => 'That file is over 15 MB.'];
    $bytes = (string)file_get_contents((string)$f['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if ($mime === 'application/pdf' || $ext === 'pdf') { $t = pdf_text($bytes); return ['text' => $t, 'via' => 'pdf', 'error' => trim($t) === '' ? 'Could not read text from that PDF (it may be a scanned image). Take a screenshot of the page and upload that instead.' : null]; }
    if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
        if (VISION_URL === '') return ['text' => '', 'error' => 'Reading photos needs the vision service, which is not set up here. Type the codes instead.'];
        if (MULTI_USER && !rate_ok('scanread:' . uid(), is_guest() ? 5 : LIMIT_VISION_PER_DAY, 86400)) return ['text' => '', 'error' => 'Daily limit for reading photos reached. Type the codes instead.'];
        $r = vision_call('POST', '/read-scan', $bytes, 120);
        if (!$r || $r['code'] !== 200) {
            $msg = $r ? (json_decode($r['body'], true)['error'] ?? '') : '';
            return ['text' => '', 'error' => 'Could not read the photo right now' . ($msg ? ' (' . $msg . ')' : '') . '. Type the codes instead.'];
        }
        $j = json_decode($r['body'], true) ?: [];
        return ['text' => (string)($j['text'] ?? ''), 'via' => 'photo', 'error' => null];
    }
    if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'csv', 'log', 'tsv'], true)) {
        $t = mb_convert_encoding($bytes, 'UTF-8', 'UTF-8, UTF-16, ISO-8859-1');
        return ['text' => $ext === 'csv' || substr_count(strtok($t, "\n") ?: '', ',') > 3 ? csv_to_freeze($t) : $t, 'via' => $ext === 'csv' ? 'csv' : 'text', 'error' => null];
    }
    return ['text' => '', 'error' => 'Unsupported file. Use a PDF, TXT/CSV export, or a photo/screenshot.'];
}

/* ---------- Running a diagnosis ---------- */

const DIAG_SYMPTOMS = [
    'flashing' => 'Check-engine light flashing', 'rough_idle' => 'Rough or shaky idle', 'hesitation' => 'Hesitates / no power', 'stall' => 'Stalls',
    'no_start' => 'Hard start or no start', 'overheat' => 'Running hot', 'fuel_smell' => 'Smells of fuel', 'smoke' => 'Smoke from exhaust',
    'economy' => 'Worse fuel economy', 'shifting' => 'Harsh or late shifts / slipping', 'noise' => 'Ticking, knocking or rattling', 'limp' => 'Reduced power / limp mode',
];
/** What symptoms add to the codes. */
function symptom_notes(array $sym, array $codes): array {
    $n = [];
    if (in_array('flashing', $sym, true)) $n[] = ['bad', 'A flashing check-engine light means an active misfire that can overheat and destroy the catalytic converter. Avoid driving, or drive gently to the shop.'];
    if (in_array('overheat', $sym, true)) $n[] = ['bad', 'Running hot: stop driving when the gauge climbs. Check coolant level when cold and whether the radiator fan runs.'];
    if (in_array('fuel_smell', $sym, true)) $n[] = ['bad', 'A raw fuel smell can mean a leak near hot parts (fire risk). Look under the car and around the fuel rail before driving.'];
    if (in_array('noise', $sym, true) && array_intersect($codes, ['P0016', 'P0017', 'P0018', 'P0019', 'P0011', 'P0021', 'P0008'])) $n[] = ['warn', 'Rattle plus timing codes: likely timing chain or tensioner. Check soon; a jumped chain can wreck the engine.'];
    if (in_array('no_start', $sym, true) && !$codes) $n[] = ['info', 'No-start with no codes: check battery voltage (12.4 V+), listen for the fuel pump at key-on, and whether the security light flashes (immobilizer).'];
    if (in_array('limp', $sym, true)) $n[] = ['info', 'Limp mode usually comes with a throttle, boost or transmission code; a restart often clears it temporarily, but the fault will return.'];
    if (in_array('smoke', $sym, true)) $n[] = ['info', 'Smoke colour matters: blue = burning oil, white and sweet-smelling = coolant (head gasket), black = running rich.'];
    if (in_array('shifting', $sym, true) && !array_filter($codes, fn($c) => str_starts_with($c, 'P07') || str_starts_with($c, 'P08'))) $n[] = ['info', 'Shifting complaints without transmission codes: the transmission module may hold codes a basic reader cannot see. Check fluid level and condition.'];
    return $n;
}

/**
 * Analyze codes (+ optional freeze-frame text and symptoms) for a vehicle. Pure function of its inputs plus cached
 * NHTSA data, so a saved report can always be re-rendered.
 */
function diag_analyze(array $veh, array $codes, string $freezeText = '', array $symptoms = [], bool $online = true): array {
    $codes = array_values(array_unique(array_filter(array_map('obd_norm', $codes))));
    $items = array_map(fn($c) => obd_lookup($c, $veh), $codes);
    usort($items, fn($a, $b) => $b['sev'] <=> $a['sev']);
    $fz = obd_freeze_parse($freezeText);
    $worstDrive = 'ok';
    $rank = ['ok' => 0, 'soon' => 1, 'careful' => 2, 'stop' => 3];
    foreach ($items as $it) { $d = $it['kb']['drive'] ?? 'soon'; if ($rank[$d] > $rank[$worstDrive]) $worstDrive = $d; }
    if (in_array('flashing', $symptoms, true) || in_array('overheat', $symptoms, true)) $worstDrive = 'stop';
    $complaints = $online ? vehicle_complaints($veh) : null;
    return [
        'codes' => $codes, 'items' => $items, 'combos' => obd_combo_insights($codes, $veh),
        'freeze' => $fz['values'], 'freeze_notes' => obd_freeze_notes($fz['values'], $veh, $fz['raw']),
        'symptom_notes' => symptom_notes($symptoms, $codes), 'drive' => $worstDrive,
        'complaints' => $complaints, 'complaint_focus' => complaint_components_for($codes),
        'cost' => $items ? [array_sum(array_map(fn($i) => $i['kb']['cost'][0] ?? 0, $items)), max(array_map(fn($i) => $i['kb']['cost'][1] ?? 0, $items))] : null,
    ];
}

/* ---------- Ownership for the account-level tables ---------- */

function owns_row(string $table, int $id): bool {
    if (in_array($table, ['job_lines', 'job_payments', 'job_auth', 'job_inspect'], true)) return $id > 0 && (bool)col("SELECT 1 FROM $table x JOIN jobs j ON j.id=x.job_id WHERE x.id=? AND j.user_id=?", [$id, uid()]);
    return in_array($table, ['customers', 'diag_reports', 'tool_links', 'jobs', 'canned_jobs', 'parts_catalog'], true) && $id > 0 && (bool)col("SELECT 1 FROM $table WHERE id=? AND user_id=?", [$id, uid()]);
}

/** Scanner and repair-information services owners and shops already pay for, grouped by brand, to open from the app. */
const TOOL_PRESETS = [
    'Repair information' => [['ALLDATA', 'https://my.alldata.com/'], ['Mitchell 1 ProDemand', 'https://www1.prodemand.com/'], ['Identifix Direct-Hit', 'https://www.identifix.com/'], ['Motor (MOTOR Information Systems)', 'https://www.motor.com/'], ['ALLDATA DIY', 'https://www.alldatadiy.com/'], ['ChiltonDIY', 'https://www.chiltondiy.com/']],
    'Scan tool clouds' => [['Autel (MaxiFix / cloud reports)', 'https://www.maxifix.com/'], ['Launch X431 (cloud reports)', 'https://www.x431.com/'], ['Topdon', 'https://www.topdon.com/'], ['Snap-on (ShopKey / SureTrack)', 'https://www.snapon.com/EN/US/Diagnostics'], ['BlueDriver', 'https://www.bluedriver.com/'], ['FIXD', 'https://www.fixdapp.com/']],
    'Factory service sites' => [['Ford (Motorcraft Service)', 'https://www.motorcraftservice.com/'], ['GM (ACDelco TDS)', 'https://www.acdelcotds.com/'], ['Toyota TIS', 'https://techinfo.toyota.com/'], ['Honda / Acura Service Express', 'https://techinfo.honda.com/'], ['Nissan / Infiniti TechInfo', 'https://www.nissan-techinfo.com/'], ['Stellantis (TechAuthority)', 'https://www.techauthority.com/'], ['Mercedes-Benz (XENTRY / STAR TekInfo)', 'https://www.startekinfo.com/'], ['BMW (ISTA / AOS)', 'https://aos.bmwgroup.com/'], ['Volkswagen / Audi (erWin)', 'https://erwin.vw.com/'], ['Hyundai (Hyundai TechInfo)', 'https://www.hyundaitechinfo.com/'], ['Kia (KiaTechInfo)', 'https://www.kiatechinfo.com/'], ['Subaru (STIS)', 'https://techinfo.subaru.com/']],
];

/** Email-confirmation link (shared by sign-up and "keep my guest garage"). */
function mail_verify(array $u): bool {
    $tok = bin2hex(random_bytes(20));
    q('UPDATE users SET verify_token=? WHERE id=?', [hash('sha256', $tok), $u['id']]);
    $link = base_url() . '/' . url('verify', ['t' => $tok]);
    return send_mail((string)$u['email'], 'Confirm your ' . APP_NAME . ' account',
        '<p>Welcome to ' . h(APP_NAME) . '.</p><p><a href="' . h($link) . '">Confirm your email</a> so you can reset your password if you ever forget it.</p><p>If you did not sign up, ignore this email.</p>');
}

/* ---------- YouTube repair videos ---------- */

/** Video search through the YouTube Data API (cached 7 days, ~100 quota units per uncached search). */
function youtube_search(string $q): array {
    $q = trim(preg_replace('/\s+/', ' ', mb_substr($q, 0, 120)));
    $fallback = 'https://www.youtube.com/results?search_query=' . urlencode($q);
    if ($q === '') return ['items' => [], 'fallback' => $fallback];
    if (YOUTUBE_KEY === '') return ['items' => [], 'fallback' => $fallback, 'nokey' => true];
    $key = 'yt:' . mb_strtolower($q);
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 7 * 86400) return ['items' => json_decode((string)$c['body'], true) ?: [], 'fallback' => $fallback];
    $j = http_get_json('https://www.googleapis.com/youtube/v3/search?' . http_build_query(['part' => 'snippet', 'type' => 'video', 'maxResults' => 8, 'q' => $q, 'safeSearch' => 'moderate', 'videoEmbeddable' => 'true', 'key' => YOUTUBE_KEY]));
    if (!$j) return ['items' => [], 'fallback' => $fallback, 'error' => true];
    $items = [];
    foreach ($j['items'] ?? [] as $it) {
        $id = (string)($it['id']['videoId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) continue;
        $items[] = ['id' => $id, 'title' => html_entity_decode((string)($it['snippet']['title'] ?? ''), ENT_QUOTES), 'channel' => (string)($it['snippet']['channelTitle'] ?? ''),
            'thumb' => (string)($it['snippet']['thumbnails']['medium']['url'] ?? "https://i.ytimg.com/vi/$id/mqdefault.jpg")];
    }
    q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, json_encode($items), time()]);
    return ['items' => $items, 'fallback' => $fallback];
}

/**
 * NHTSA names models its own way per database ("C-CLASS" for a C300, "3 SERIES" for a 335i...). Ask NHTSA for its
 * model list for this year+make and return the names that match ours, best first. Cached 30 days.
 */
function nhtsa_models(int $year, string $make, string $model, string $issue = 'c'): array {
    $norm = fn(string $s) => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    $want = $norm($model); if ($want === '' || $year < 1950) return [];
    $key = "nhtsamodels:$issue:$year:" . strtoupper($make);
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 30 * 86400) $list = json_decode((string)$c['body'], true) ?: [];
    else {
        $list = [];
        foreach (array_unique([strtoupper($make), str_replace('-', ' ', strtoupper($make))]) as $mk) {
            $j = http_get_json('https://api.nhtsa.gov/products/vehicle/models?' . http_build_query(['modelYear' => $year, 'make' => $mk, 'issueType' => $issue]));
            foreach ($j['results'] ?? [] as $r) $list[] = [(string)$r['make'], (string)$r['model']];
        }
        $list = array_values(array_unique($list, SORT_REGULAR));
        if ($list) q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, json_encode($list), time()]);
    }
    $score = [];
    $family = preg_match('/^([A-Z]{1,3})\s?-?\d{2,3}/', strtoupper($model), $fm) ? $fm[1] . 'CLASS' : null;  // Mercedes C300 -> CCLASS
    $series = preg_match('/^(\d)\d\d/', $want, $sm) ? $sm[1] . 'SERIES' : null;                            // BMW 335i -> 3SERIES
    foreach ($list as [$mk, $md]) {
        $n = $norm($md); $s = 0;
        if ($n === $want) $s = 100;
        elseif ($family && $n === $family) $s = 90;
        elseif ($series && $n === $series) $s = 85;
        elseif (str_starts_with($n, $want) || str_starts_with($want, $n)) $s = 70 - abs(strlen($n) - strlen($want));
        elseif (str_contains($n, $want)) $s = 50;
        if ($s > 0) $score[] = [$s, $mk, $md];
    }
    usort($score, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($x) => [$x[1], $x[2]], array_slice($score, 0, 3));
}
