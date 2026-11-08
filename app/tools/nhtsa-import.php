<?php
// Imports NHTSA's free flat files into DATA_DIR/nhtsa.sqlite (separate from the app database):
//   - manufacturer communications / technical service bulletins (TSBS_RECEIVED_<y1>-<y2>.zip, 5-year chunks)
//   - defect investigations (FLAT_INV.zip)
// Rows are folded to one document plus (make/model, year range) pairs, so the 2-3 GB of raw text becomes a few hundred MB.
// Builds into a new file and swaps it in when complete, so the app never sees a half-built database.
// Usage: php app/tools/nhtsa-import.php [--since=2005] [--keep]      (monthly from cron is plenty; NHTSA updates daily)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SCRIPT_FILENAME'] = __FILE__;
require dirname(__DIR__) . '/bootstrap.php';
@ini_set('memory_limit', '1024M'); set_time_limit(0);

$opt = getopt('', ['since::', 'keep']);
$since = max(1995, (int)($opt['since'] ?? 2005));
$keep = isset($opt['keep']);
$work = sys_get_temp_dir() . '/nhtsa-import-' . getmypid(); @mkdir($work, 0700, true);
$final = DATA_DIR . '/nhtsa.sqlite'; $tmp = $final . '.new'; @unlink($tmp);
$out = new PDO('sqlite:' . $tmp); $out->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$out->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;
  CREATE TABLE vm (id INTEGER PRIMARY KEY, make TEXT, model TEXT, mk TEXT, md TEXT, UNIQUE(mk, md));
  CREATE TABLE tsb (id INTEGER PRIMARY KEY, doc TEXT, date TEXT, type TEXT, comps TEXT, summary TEXT);
  CREATE TABLE tsb_vm (tsb INT, vm INT, y1 INT, y2 INT);
  CREATE TABLE inv (id TEXT PRIMARY KEY, opened TEXT, closed TEXT, comps TEXT, campaign TEXT, subject TEXT, summary TEXT);
  CREATE TABLE inv_vm (inv TEXT, vm INT, y1 INT, y2 INT);
  CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);');
$norm = fn(string $s) => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
$vmIds = [];
$vmId = function (string $make, string $model) use ($out, $norm, &$vmIds): int {
    $k = $norm($make) . '|' . $norm($model);
    if (isset($vmIds[$k])) return $vmIds[$k];
    $out->prepare('INSERT INTO vm (make, model, mk, md) VALUES (?,?,?,?)')->execute([trim($make), trim($model), $norm($make), $norm($model)]);
    return $vmIds[$k] = (int)$out->lastInsertId();
};
/** Contiguous year runs: [2009,2010,2011,2014] -> [[2009,2011],[2014,2014]]. 9999 (unknown) stays its own run. */
$runs = function (array $years): array {
    $years = array_values(array_unique(array_map('intval', $years))); sort($years); $r = []; $a = $b = null;
    foreach ($years as $y) { if ($a !== null && $y === $b + 1) { $b = $y; continue; } if ($a !== null) $r[] = [$a, $b]; $a = $b = $y; }
    if ($a !== null) $r[] = [$a, $b];
    return $r;
};
$fetch = function (string $url, string $to): bool {
    $c = curl_init($url); $f = fopen($to, 'w');
    curl_setopt_array($c, [CURLOPT_FILE => $f, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 1800, CURLOPT_USERAGENT => 'Garage (open-garage) NHTSA import', CURLOPT_FAILONERROR => true]);
    $ok = curl_exec($c) && curl_getinfo($c, CURLINFO_HTTP_CODE) === 200; fclose($f);
    if (!$ok) @unlink($to);
    return $ok;
};
/** Stream the rows of the (single) text file inside a zip. */
$rowsOf = function (string $zip): Generator {
    $p = popen('unzip -p ' . escapeshellarg($zip), 'r');
    while (($ln = fgets($p)) !== false) yield explode("\t", rtrim($ln, "\r\n"));
    pclose($p);
};
$log = fn(string $m) => fwrite(STDOUT, date('H:i:s') . ' ' . $m . "\n");

/* ---------- Service bulletins / manufacturer communications ---------- */
$year = (int)date('Y'); $docs = 0; $pairs = 0;
$insDoc = $out->prepare('INSERT OR REPLACE INTO tsb (id, doc, date, type, comps, summary) VALUES (?,?,?,?,?,?)');
$insVm = $out->prepare('INSERT INTO tsb_vm (tsb, vm, y1, y2) VALUES (?,?,?,?)');
for ($y1 = intdiv($since - 1995, 5) * 5 + 1995; $y1 <= $year; $y1 += 5) {
    $y2 = $y1 + 4; $zip = "$work/tsb-$y1.zip";
    // The newest chunk is named up to the current year (e.g. 2025-2026) until it is full.
    $names = array_unique(["TSBS_RECEIVED_$y1-$y2.zip", "TSBS_RECEIVED_$y1-" . min($y2, $year) . '.zip', "TSBS_RECEIVED_$y1-" . min($y2, $year + 1) . '.zip']);
    $got = false; foreach ($names as $n) if ($fetch('https://static.nhtsa.gov/odi/ffdd/tsbs/' . $n, $zip)) { $got = $n; break; }
    if (!$got) { $log("bulletins $y1-$y2: not found, skipped"); continue; }
    $log("bulletins: $got (" . round(filesize($zip) / 1048576, 1) . ' MB)');
    $out->beginTransaction();
    $cur = null; $d = null; $prod = [];
    $flush = function () use (&$cur, &$d, &$prod, $insDoc, $insVm, $runs, &$docs, &$pairs): void {
        if ($cur === null) return;
        $insDoc->execute([$cur, $d['doc'], $d['date'], $d['type'], implode(', ', array_keys($d['comps'])), mb_substr($d['summary'], 0, 4000)]);
        foreach ($prod as $vm => $ys) foreach ($runs($ys) as [$a, $b]) { $insVm->execute([$cur, $vm, $a, $b]); $pairs++; }
        $docs++; $cur = null; $prod = [];
    };
    foreach ($rowsOf($zip) as $r) {
        if (count($r) < 14 || !ctype_digit($r[0])) continue;
        $id = (int)$r[0];
        if ($id !== $cur) { $flush(); $cur = $id; $d = ['doc' => $r[3], 'date' => $r[4], 'type' => $r[6], 'comps' => [], 'summary' => $r[13]]; }
        foreach (array_map('trim', explode(',', $r[10])) as $c) if ($c !== '') $d['comps'][$c] = 1;
        if ($r[7] !== '' && $r[8] !== '') $prod[$vmId($r[7], $r[8])][] = (int)$r[9];
    }
    $flush();
    $out->commit();
    if (!$keep) @unlink($zip);
    $log("  $docs documents, $pairs vehicle ranges so far");
}

/* ---------- Defect investigations ---------- */
$zip = "$work/inv.zip"; $invN = 0;
if ($fetch('https://static.nhtsa.gov/odi/ffdd/inv/FLAT_INV.zip', $zip)) {
    $log('investigations: FLAT_INV.zip (' . round(filesize($zip) / 1048576, 1) . ' MB)');
    $out->beginTransaction();
    $insInv = $out->prepare('INSERT OR REPLACE INTO inv (id, opened, closed, comps, campaign, subject, summary) VALUES (?,?,?,?,?,?,?)');
    $insIvm = $out->prepare('INSERT INTO inv_vm (inv, vm, y1, y2) VALUES (?,?,?,?)');
    $all = [];
    foreach ($rowsOf($zip) as $r) {
        if (count($r) < 11 || trim($r[0]) === '') continue;
        $id = trim($r[0]);
        if (!isset($all[$id])) $all[$id] = ['opened' => $r[6], 'closed' => $r[7], 'camp' => trim($r[8]), 'subject' => $r[9], 'summary' => $r[10], 'comps' => [], 'prod' => []];
        if (trim($r[4]) !== '') $all[$id]['comps'][trim($r[4])] = 1;
        if (trim($r[1]) !== '' && trim($r[2]) !== '') $all[$id]['prod'][$vmId($r[1], $r[2])][] = (int)$r[3];
    }
    foreach ($all as $id => $x) {
        $insInv->execute([$id, $x['opened'], $x['closed'], implode(', ', array_keys($x['comps'])), $x['camp'], $x['subject'], mb_substr($x['summary'], 0, 6000)]);
        foreach ($x['prod'] as $vm => $ys) foreach ($runs($ys) as [$a, $b]) $insIvm->execute([$id, $vm, $a, $b]);
        $invN++;
    }
    $out->commit();
    if (!$keep) @unlink($zip);
    $log("  $invN investigations");
} else $log('investigations: download failed, skipped');

if ($docs === 0 && $invN === 0) { $log('Nothing imported; keeping the old database.'); @unlink($tmp); exit(1); }
$out->exec('CREATE INDEX tsb_vm_i ON tsb_vm (vm, y1, y2); CREATE INDEX inv_vm_i ON inv_vm (vm, y1, y2); CREATE INDEX vm_md ON vm (mk, md);');
$st = $out->prepare('INSERT INTO meta (key, value) VALUES (?,?)');
foreach (['imported_at' => date('c'), 'since' => (string)$since, 'tsb_docs' => (string)$docs, 'investigations' => (string)$invN] as $k => $v) $st->execute([$k, $v]);
$out->exec('ANALYZE'); $out = null;
rename($tmp, $final);
@rmdir($work);
$log('done: ' . $final . ' (' . round(filesize($final) / 1048576, 1) . ' MB)');
