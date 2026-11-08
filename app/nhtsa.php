<?php
// Known problems for one vehicle from NHTSA's free data (2026-11-08, roadmap #3): manufacturer service bulletins and
// warranty extensions and defect investigations (imported monthly into DATA_DIR/nhtsa.sqlite by
// app/tools/nhtsa-import.php), plus owner complaints (live API, cached a month).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

function nhtsa_db(): ?PDO {
    static $p = false;
    if ($p !== false) return $p;
    $f = DATA_DIR . '/nhtsa.sqlite';
    if (!is_file($f)) return $p = null;
    $flags = class_exists('Pdo\\Sqlite') ? [\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY] : [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
    $p = new PDO('sqlite:' . $f, null, null, $flags);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $p;
}
function nhtsa_norm(string $s): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)); }

/**
 * The make/model rows in NHTSA's files that mean this vehicle: the same model written any way ("C300", "C 300",
 * "C300 4MATIC"), and the family name NHTSA uses for some years ("C-CLASS"). Hybrids and other variants that only
 * share a prefix are left out when an exact match exists.
 */
function nhtsa_vm_ids(array $v): array {
    $db = nhtsa_db(); $mk = nhtsa_norm((string)($v['make'] ?? '')); $md = nhtsa_norm((string)($v['model'] ?? ''));
    if (!$db || $mk === '' || $md === '') return [];
    $cands = [$md];
    if (preg_match('/^([A-Z]{1,3})\s?-?\d{2,3}/', strtoupper((string)$v['model']), $m)) $cands[] = $m[1] . 'CLASS';        // Mercedes C300 -> C-CLASS
    if (preg_match('/^(\d)\d\d/', $md, $m)) $cands[] = $m[1] . 'SERIES';                                                    // BMW 335i -> 3 SERIES
    $in = implode(',', array_fill(0, count($cands), '?'));
    $st = $db->prepare("SELECT id, md FROM vm WHERE mk=? AND (md IN ($in) OR md LIKE ?)");
    $st->execute(array_merge([$mk], $cands, [$md . '%']));
    $rows = $st->fetchAll();
    $exact = array_filter($rows, fn($r) => in_array($r['md'], $cands, true));
    // Drive variants ("C300 4MATIC") belong to the car; "CAMRY HYBRID" does not belong to a Camry.
    $variants = array_filter($rows, fn($r) => !in_array($r['md'], $cands, true) && preg_match('/^' . preg_quote($md, '/') . '(4MATIC|XDRIVE|QUATTRO|AWD|4WD|FWD|RWD|4X4|SPORT|COUPE|SEDAN|WAGON|CONVERTIBLE|CABRIOLET)$/', $r['md']));
    return array_map(fn($r) => (int)$r['id'], $exact || $variants ? array_merge($exact, $variants) : $rows);
}

/** Service bulletins and other manufacturer communications for this year/make/model, newest first. */
function bulletins_for(array $v): array {
    $ids = nhtsa_vm_ids($v); $y = (int)($v['year'] ?? 0);
    if (!$ids || !$y) return [];
    $st = nhtsa_db()->prepare('SELECT DISTINCT t.* FROM tsb_vm p JOIN tsb t ON t.id=p.tsb WHERE p.vm IN (' . implode(',', $ids) . ') AND ? BETWEEN p.y1 AND p.y2 ORDER BY t.date DESC LIMIT 400');
    $st->execute([$y]);
    return $st->fetchAll();
}
function investigations_for(array $v): array {
    $ids = nhtsa_vm_ids($v); $y = (int)($v['year'] ?? 0);
    if (!$ids || !$y) return [];
    $st = nhtsa_db()->prepare('SELECT DISTINCT i.* FROM inv_vm p JOIN inv i ON i.id=p.inv WHERE p.vm IN (' . implode(',', $ids) . ') AND ? BETWEEN p.y1 AND p.y2 ORDER BY i.opened DESC LIMIT 50');
    $st->execute([$y]);
    return $st->fetchAll();
}
/** Owner complaints filed with NHTSA (live API, cached 30 days). null = could not reach NHTSA. */
function complaints_for(array $v): ?array {
    $y = (int)($v['year'] ?? 0); $make = strtoupper(trim((string)($v['make'] ?? ''))); $model = trim((string)($v['model'] ?? ''));
    if ($y < 1950 || $make === '' || $model === '') return [];
    $key = 'cmpl:' . $y . ':' . $make . ':' . strtoupper($model);
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 30 * 86400) return json_decode((string)$c['body'], true) ?: [];
    $pairs = [[$make, $model]];
    if (function_exists('nhtsa_models')) $pairs = array_merge(nhtsa_models($y, $make, $model, 'c'), $pairs);
    $reached = false; $out = [];
    foreach ($pairs as [$mk, $md]) {
        $j = http_get_json('https://api.nhtsa.gov/complaints/complaintsByVehicle?' . http_build_query(['make' => $mk, 'model' => $md, 'modelYear' => $y]));
        if (!is_array($j)) continue;
        $reached = true;
        if (!empty($j['results'])) {
            foreach ($j['results'] as $r) $out[] = ['date' => (string)($r['dateOfIncident'] ?? ''), 'comps' => (string)($r['components'] ?? ''), 'summary' => mb_substr((string)($r['summary'] ?? ''), 0, 1200),
                'crash' => !empty($r['crash']), 'fire' => !empty($r['fire']), 'injuries' => (int)($r['numberOfInjuries'] ?? 0)];
            break;
        }
    }
    if (!$reached) return null;
    q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, json_encode($out), time()]);
    return $out;
}

/** "ENGINE AND ENGINE COOLING:ENGINE" -> "Engine and engine cooling". */
function nhtsa_comp(string $c): string { $c = trim(explode(':', $c)[0]); return $c === '' ? 'Other' : ucfirst(strtolower($c)); }
/** Short counts for a vehicle (bulletins / warranty extensions / investigations), from the local file only (fast). */
function bulletin_counts(array $v): array {
    $b = bulletins_for($v);
    return ['all' => count($b), 'warranty' => count(array_filter($b, fn($x) => stripos((string)$x['type'], 'warranty') !== false)), 'inv' => count(investigations_for($v))];
}
