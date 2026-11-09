<?php
// Easy to switch in and out (2026-11-08, roadmap #11). In: a CSV from the shop's old system (Tekmetric, Shopmonkey,
// Mitchell 1, Shop-Ware, a spreadsheet...) with customers, vehicles and/or past repair orders; columns are matched by
// name and can be changed before importing. Past repair orders land as read-only "history from your old system".
// Out: everything in one ZIP (data as JSON and CSV, plus photos), any time.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const IMPORT_ACTIONS = ['import_upload', 'import_run', 'import_cancel'];
/** Target fields and the column names that usually mean them (lower-case, punctuation removed). */
const IMPORT_FIELDS = [
    'first' => ['First name', ['firstname', 'first', 'customerfirstname', 'fname', 'givenname']],
    'last' => ['Last name', ['lastname', 'last', 'customerlastname', 'lname', 'surname', 'familyname']],
    'name' => ['Customer name', ['name', 'customer', 'customername', 'fullname', 'client', 'clientname', 'owner', 'company', 'customercompany']],
    'phone' => ['Phone', ['phone', 'phonenumber', 'mobile', 'mobilephone', 'cell', 'cellphone', 'primaryphone', 'customerphone', 'telephone', 'homephone', 'workphone']],
    'email' => ['Email', ['email', 'emailaddress', 'customeremail', 'mail']],
    'address' => ['Address', ['address', 'address1', 'street', 'streetaddress', 'customeraddress']],
    'city' => ['City', ['city', 'town']],
    'state' => ['State', ['state', 'province', 'region']],
    'zip' => ['ZIP', ['zip', 'zipcode', 'postalcode', 'postcode']],
    'year' => ['Vehicle year', ['year', 'vehicleyear', 'modelyear', 'yr']],
    'make' => ['Make', ['make', 'vehiclemake', 'manufacturer']],
    'model' => ['Model', ['model', 'vehiclemodel']],
    'vehicle' => ['Vehicle (one column)', ['vehicle', 'vehicledescription', 'yearmakemodel', 'ymm', 'car']],
    'vin' => ['VIN', ['vin', 'vinnumber', 'vehiclevin', 'serialnumber']],
    'plate' => ['Plate', ['plate', 'licenseplate', 'license', 'licenceplate', 'tag', 'platenumber', 'licenseplatenumber']],
    'miles' => ['Mileage', ['mileage', 'odometer', 'miles', 'odometerin', 'mileagein', 'odometerreading']],
    'ro' => ['RO / invoice number', ['ro', 'ronumber', 'repairorder', 'repairordernumber', 'invoice', 'invoicenumber', 'invoiceno', 'workorder', 'workordernumber', 'ordernumber', 'estimatenumber', 'ticket']],
    'date' => ['Date', ['date', 'invoicedate', 'rodate', 'createddate', 'datecreated', 'posteddate', 'closeddate', 'completeddate', 'servicedate', 'visitdate']],
    'desc' => ['Work / description', ['description', 'services', 'service', 'work', 'workperformed', 'jobs', 'job', 'concern', 'complaint', 'notes', 'lineitems', 'details', 'summary']],
    'total' => ['Total', ['total', 'grandtotal', 'amount', 'invoicetotal', 'rototal', 'totalamount', 'totalsales', 'sales', 'subtotal']],
];

function migrate_switching(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS legacy_ros (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, customer_id INT, vehicle_id INT, ro_no TEXT, ro_date TEXT, miles INT, description TEXT, total REAL, source TEXT, created_at TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_legacy_veh ON legacy_ros (vehicle_id)');
}
function import_norm(string $h): string { return preg_replace('/[^a-z0-9]/', '', strtolower($h)); }
/** Best guess of field => column index for a header row. */
function import_guess(array $head): array {
    $map = [];
    foreach ($head as $i => $h) {
        $n = import_norm((string)$h);
        foreach (IMPORT_FIELDS as $f => [, $syn]) if (!isset($map[$f]) && in_array($n, $syn, true)) { $map[$f] = $i; continue 2; }
    }
    // Looser second pass: "Customer Phone #", "Vehicle VIN" and the like.
    foreach ($head as $i => $h) {
        if (in_array($i, $map, true)) continue;
        $n = import_norm((string)$h);
        foreach (IMPORT_FIELDS as $f => [, $syn]) if (!isset($map[$f])) foreach ($syn as $s) if (strlen($s) >= 4 && str_contains($n, $s)) { $map[$f] = $i; continue 3; }
    }
    return $map;
}
/** Read a CSV / TSV (delimiter sniffed from the first line). */
function import_rows(string $file, int $max = 20000): array {
    $fh = fopen($file, 'r'); if (!$fh) return [];
    $first = (string)fgets($fh); rewind($fh);
    $del = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
    $out = [];
    while (($r = fgetcsv($fh, 0, $del, '"', '')) !== false && count($out) <= $max) {
        if ($r === [null]) continue;
        if (!$out && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]);   // Excel's BOM
        $out[] = array_map(fn($c) => trim((string)$c), $r);
    }
    fclose($fh);
    return $out;
}
/** Import rows with a field => column map. Returns counts. */
function import_apply(array $rows, array $map, string $source): array {
    $get = fn(array $r, string $f) => isset($map[$f]) && $map[$f] !== '' ? trim((string)($r[(int)$map[$f]] ?? '')) : '';
    $digits = fn(string $p) => substr(preg_replace('/\D/', '', $p), -10);
    $n = ['customers' => 0, 'vehicles' => 0, 'history' => 0, 'skipped' => 0];
    $custCache = []; $vehCache = [];
    foreach ($rows as $r) {
        $name = $get($r, 'name') ?: trim($get($r, 'first') . ' ' . $get($r, 'last'));
        $email = filter_var($get($r, 'email'), FILTER_VALIDATE_EMAIL) ? strtolower($get($r, 'email')) : '';
        $phone = $get($r, 'phone');
        // Customer: same email or phone (last 10 digits) already on file, else new.
        $cid = 0; $ck = $email ?: ($digits($phone) ?: strtolower($name));
        if ($ck !== '' && isset($custCache[$ck])) $cid = $custCache[$ck];
        elseif ($name !== '' || $email !== '' || $phone !== '') {
            if ($email) $cid = (int)col('SELECT id FROM customers WHERE user_id=? AND lower(email)=?', [uid(), $email]);
            if (!$cid && strlen($digits($phone)) >= 7) foreach (rows('SELECT id, phone FROM customers WHERE user_id=?', [uid()]) as $c) if ($digits((string)$c['phone']) === $digits($phone)) { $cid = (int)$c['id']; break; }
            if (!$cid && !$email && strlen($digits($phone)) < 7 && $name !== '') $cid = (int)col('SELECT id FROM customers WHERE user_id=? AND lower(name)=lower(?)', [uid(), $name]);
            if (!$cid) {
                $addr = trim(implode(', ', array_filter([$get($r, 'address'), $get($r, 'city'), trim($get($r, 'state') . ' ' . $get($r, 'zip'))])));
                $cid = insert('customers', ['user_id' => uid(), 'name' => mb_substr($name ?: ($email ?: $phone), 0, 80), 'phone' => mb_substr($phone, 0, 40), 'email' => $email, 'notes' => trim(($addr ? $addr . "\n" : '') . 'Imported from ' . $source . '.'), 'created_at' => now()]);
                $n['customers']++;
            }
            if ($ck !== '') $custCache[$ck] = $cid;
        }
        // Vehicle: same VIN, else same customer + year/make/model, else new.
        $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $get($r, 'vin'))); $vin = preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) ? $vin : '';
        [$y, $mk, $md] = [(int)$get($r, 'year'), $get($r, 'make'), $get($r, 'model')];
        if (!$mk && $get($r, 'vehicle') !== '' && preg_match('/^\s*((?:19|20)\d\d)?\s*(\S+)\s+(.+)$/', $get($r, 'vehicle'), $m)) [$y, $mk, $md] = [(int)($m[1] ?: $y), $m[2], trim($m[3])];
        $vehId = 0; $vk = $vin ?: ($mk !== '' ? $cid . '|' . $y . '|' . strtolower($mk . ' ' . $md) : '');
        if ($vk !== '' && isset($vehCache[$vk])) $vehId = $vehCache[$vk];
        elseif ($vk !== '') {
            if ($vin) $vehId = (int)col('SELECT id FROM vehicle WHERE user_id=? AND vin=?', [uid(), $vin]);
            if (!$vehId && $mk !== '') $vehId = (int)col('SELECT id FROM vehicle WHERE user_id=? AND customer_id IS ? AND COALESCE(year,0)=? AND lower(make)=lower(?) AND lower(model)=lower(?)', [uid(), $cid ?: null, $y, $mk, $md]);
            if (!$vehId) {
                $vehId = insert('vehicle', ['user_id' => uid(), 'customer_id' => $cid ?: null, 'kind' => 'car', 'year' => $y ?: null, 'make' => mb_substr(ucwords(strtolower($mk)), 0, 40), 'model' => mb_substr($md, 0, 60),
                    'vin' => $vin, 'plate' => strtoupper(mb_substr($get($r, 'plate'), 0, 12)), 'mileage' => (int)preg_replace('/\D/', '', $get($r, 'miles')), 'mileage_updated' => today(), 'notes' => '', 'mods' => '',
                    'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM vehicle')]);
                $n['vehicles']++;
            }
            $vehCache[$vk] = $vehId;
        }
        // A past repair order: anything with a date, number, total or description.
        $ro = $get($r, 'ro'); $date = $get($r, 'date'); $desc = $get($r, 'desc'); $total = $get($r, 'total');
        if ($ro !== '' || $desc !== '' || ($date !== '' && $total !== '')) {
            $ts = $date !== '' ? strtotime(str_replace('.', '/', $date)) : false;
            $amt = $total !== '' ? (float)preg_replace('/[^\d.\-]/', '', $total) : null;
            $dup = $ro !== '' && col('SELECT 1 FROM legacy_ros WHERE user_id=? AND ro_no=? AND COALESCE(vehicle_id,0)=?', [uid(), $ro, $vehId]);
            if (!$dup) { insert('legacy_ros', ['user_id' => uid(), 'customer_id' => $cid ?: null, 'vehicle_id' => $vehId ?: null, 'ro_no' => mb_substr($ro, 0, 40), 'ro_date' => $ts ? date('Y-m-d', $ts) : null,
                'miles' => (int)preg_replace('/\D/', '', $get($r, 'miles')) ?: null, 'description' => mb_substr($desc, 0, 4000), 'total' => $amt, 'source' => $source, 'created_at' => now()]); $n['history']++; }
        } elseif (!$cid && !$vehId) $n['skipped']++;
    }
    return $n;
}

/** Everything for one account in a ZIP: JSON, CSVs a spreadsheet opens, and the photos. Returns the temp file path. */
function export_zip(int $userId): string {
    $data = account_export($userId);
    $f = tempnam(sys_get_temp_dir(), 'gexp'); $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
    $z->addFromString('everything.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $csv = function (string $name, array $rows) use ($z): void {
        if (!$rows) return;
        $h = fopen('php://temp', 'r+'); fputcsv($h, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $r) fputcsv($h, array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $r), ',', '"', '');
        rewind($h); $z->addFromString($name, (string)stream_get_contents($h)); fclose($h);
    };
    $csv('customers.csv', rows('SELECT id, name, phone, email, notes, created_at FROM customers WHERE user_id=?', [$userId]));
    $csv('vehicles.csv', rows('SELECT v.id, c.name customer, v.year, v.make, v.model, v.trim, v.engine, v.vin, v.plate, v.color, v.mileage FROM vehicle v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.user_id=?', [$userId]));
    $csv('repair_orders.csv', rows('SELECT j.id, j.est_no, j.inv_no, j.stage, c.name customer, v.year, v.make, v.model, v.vin, j.title, j.complaint, j.cause, j.correction, j.odometer_in, j.odometer_out, j.total, j.paid, j.created_at, j.invoiced_at FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id WHERE j.user_id=?', [$userId]));
    $csv('repair_order_lines.csv', rows('SELECT l.job_id, j.est_no, l.grp service, l.kind, l.description, l.part_no, l.brand, l.qty, l.cost, l.price, l.status FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=?', [$userId]));
    $csv('history_from_old_system.csv', rows('SELECT * FROM legacy_ros WHERE user_id=?', [$userId]));
    $paths = array_merge(array_column(rows('SELECT p.path FROM photos p JOIN vehicle v ON v.id=p.vehicle_id WHERE v.user_id=?', [$userId]), 'path'), array_column(rows("SELECT photo FROM vehicle WHERE user_id=? AND photo<>''", [$userId]), 'photo'));
    foreach (array_unique($paths) as $p) { $file = realpath(UPLOAD_DIR . '/' . basename((string)$p)); if ($file && str_starts_with($file, realpath(UPLOAD_DIR) . DIRECTORY_SEPARATOR) && is_file($file)) $z->addFile($file, 'photos/' . basename($file)); }
    $z->addFromString('README.txt', "Everything from your " . APP_NAME . " account, exported " . date('Y-m-d H:i') . ".\neverything.json has every record; the .csv files open in Excel or Google Sheets; photos/ has your photos.\n");
    $z->close();
    return $f;
}
