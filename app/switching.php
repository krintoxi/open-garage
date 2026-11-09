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
    if (!in_array('review_asked_at', array_column($pdo->query('PRAGMA table_info(jobs)')->fetchAll(), 'name'), true)) $pdo->exec('ALTER TABLE jobs ADD COLUMN review_asked_at TEXT');
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

/* ---------- Accounting exports (roadmap #16): QuickBooks Online and Xero import files, payments, sales summary ---------- */

const ACCT_KINDS = ['qbo_invoices' => 'QuickBooks Online: invoices', 'qbo_customers' => 'QuickBooks Online: customers', 'xero_invoices' => 'Xero: sales invoices', 'payments' => 'Payments received', 'summary' => 'Sales summary (any accountant)'];
/** Period keys shared by "How you compare" and the exports: [from Y-m-d, to (exclusive) Y-m-d, label]. */
function period_range(string $per): array {
    return match ($per) {
        'last' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-01'), date('F Y', strtotime('first day of last month'))],
        '90' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('+1 day')), 'Last 90 days'],
        'year' => [date('Y-01-01'), date('Y-m-d', strtotime('+1 day')), date('Y') . ' so far'],
        default => [date('Y-m-01'), date('Y-m-d', strtotime('+1 day')), date('F Y') . ' so far'],
    };
}
/** Stream one export as CSV. Invoices = repair orders invoiced in the period (approved lines only). */
function acct_csv(string $kind, string $from, string $to): void {
    $S = shop(); $us = fn(?string $d) => $d ? date('m/d/Y', strtotime($d)) : '';
    $o = fopen('php://output', 'w'); $put = fn(array $r) => fputcsv($o, $r, ',', '"', '');
    $jobs = rows("SELECT j.*, c.name cname, c.email cemail, c.phone cphone, v.year vy, v.make vm, v.model vmo, v.vin vvin FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
        WHERE j.user_id=? AND j.stage IN ('invoiced','paid') AND j.invoiced_at >= ? AND j.invoiced_at < ? ORDER BY j.invoiced_at", [uid(), $from, $to]);
    $item = ['labor' => 'Labor', 'part' => 'Parts', 'fee' => 'Fees', 'sublet' => 'Sublet', 'core' => 'Core charge'];
    $memo = fn(array $j) => trim(trim($j['vy'] . ' ' . $j['vm'] . ' ' . $j['vmo']) . ($j['vvin'] ? ' VIN ' . $j['vvin'] : '') . ' - RO ' . $j['est_no']);
    $num = fn(array $j) => (string)($j['inv_no'] ?: $j['est_no']);
    // Lines as the invoice shows them, plus shop supplies and the discount as their own lines.
    $linesOf = function (array $j) use ($item): array {
        $ls = job_lines((int)$j['id']); $t = job_totals($j, $ls, 'approved'); $out = [];
        foreach ($ls as $l) if ($l['status'] === 'approved' && isset($item[$l['kind']])) $out[] = [$item[$l['kind']], trim(($l['grp'] && $l['grp'] !== 'General' ? $l['grp'] . ': ' : '') . $l['description']), (float)$l['qty'], (float)$l['price'], round((float)$l['qty'] * (float)$l['price'], 2), (bool)$l['taxable']];
        if ($t['supplies'] > 0) $out[] = ['Fees', 'Shop supplies', 1, round($t['supplies'], 2), round($t['supplies'], 2), true];
        if (!empty($t['discount'])) $out[] = ['Discount', 'Discount', 1, -round($t['discount'], 2), -round($t['discount'], 2), false];
        return [$out, $t];
    };
    switch ($kind) {
        case 'qbo_invoices':
            $put(['InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate', 'Terms', 'Memo', 'Item(Product/Service)', 'ItemDescription', 'ItemQuantity', 'ItemRate', 'ItemAmount', 'ItemTaxCode', 'Service Date']);
            foreach ($jobs as $j) { [$ls] = $linesOf($j); foreach ($ls as [$it, $d, $q, $r, $a, $tx]) $put([$num($j), $j['cname'] ?: 'Walk-in', $us($j['invoiced_at']), $us($j['invoiced_at']), 'Due on receipt', $memo($j), $it, $d, $q, $r, $a, $tx ? 'TAX' : 'NON', $us($j['invoiced_at'])]); }
            break;
        case 'xero_invoices':
            $put(['*ContactName', 'EmailAddress', '*InvoiceNumber', 'Reference', '*InvoiceDate', '*DueDate', 'InventoryItemCode', '*Description', '*Quantity', '*UnitAmount', 'Discount', '*AccountCode', '*TaxType', 'Currency']);
            foreach ($jobs as $j) { [$ls] = $linesOf($j); foreach ($ls as [$it, $d, $q, $r, $a, $tx]) $put([$j['cname'] ?: 'Walk-in', $j['cemail'], $num($j), $memo($j), $us($j['invoiced_at']), $us($j['invoiced_at']), '', $it . ': ' . $d, $q, $r, '', '200', $tx ? 'Tax on Sales' : 'Tax Exempt', strtoupper((string)($S['currency_code'] ?: 'usd'))]); }
            break;
        case 'qbo_customers':
            $put(['Name', 'Email', 'Phone', 'Notes']);
            foreach (rows('SELECT name, email, phone, notes FROM customers WHERE user_id=? ORDER BY name', [uid()]) as $c) $put([$c['name'], $c['email'], $c['phone'], preg_replace('/\s+/', ' ', (string)$c['notes'])]);
            break;
        case 'payments':
            $put(['Date', 'Invoice', 'Customer', 'Method', 'Amount', 'Reference', 'Note']);
            foreach (rows("SELECT p.*, j.inv_no, j.est_no, c.name cname FROM job_payments p JOIN jobs j ON j.id=p.job_id LEFT JOIN customers c ON c.id=j.customer_id WHERE j.user_id=? AND p.paid_on >= ? AND p.paid_on < ? ORDER BY p.paid_on, p.id", [uid(), $from, $to]) as $p)
                $put([$us($p['paid_on']), $p['inv_no'] ?: $p['est_no'], $p['cname'] ?: 'Walk-in', PAY_METHODS[$p['method']] ?? $p['method'], round((float)$p['amount'], 2), $p['ext_ref'] ?? '', $p['note']]);
            break;
        default: // summary
            $put(['Date', 'Invoice', 'Customer', 'Vehicle', 'Labor', 'Parts', 'Fees', 'Sublet', 'Core charges', 'Shop supplies', 'Discount', 'Tax', 'Total', 'Paid', 'Balance', 'Parts cost', 'Gross profit']);
            foreach ($jobs as $j) { [, $t] = $linesOf($j); $put([$us($j['invoiced_at']), $num($j), $j['cname'] ?: 'Walk-in', trim($j['vy'] . ' ' . $j['vm'] . ' ' . $j['vmo']), round($t['labor'], 2), round($t['parts'], 2), round($t['fees'], 2), round($t['sublet'], 2), round($t['core'], 2), round($t['supplies'], 2), round((float)($t['discount'] ?? 0), 2), round($t['tax'], 2), round($t['total'], 2), round((float)$j['paid'], 2), round($t['total'] - (float)$j['paid'], 2), round($t['cost'], 2), round($t['profit'], 2)]); }
    }
    fclose($o);
}

/** The review request the shop sends from its own phone or email (to every paying customer, never filtered). */
function review_text(array $S, string $customer = ''): string {
    $first = $customer !== '' ? strtok($customer, ' ') : '';
    return 'Hi' . ($first ? ' ' . $first : '') . ', thanks for choosing ' . ($S['name'] ?: 'us') . '! If you have a minute, a quick review helps a lot: ' . $S['review_url'];
}
/** Text / Email buttons for a review request; tapping one marks the job as asked. */
function review_buttons(array $job, array $S, ?string $phone, ?string $email, string $customer = ''): string {
    if (empty($S['review_url'])) return '';
    $t = review_text($S, $customer); $ph = preg_replace('/[^\d+]/', '', (string)$phone); $id = (int)$job['id'];
    return ($ph !== '' ? '<a class="btn sm" href="sms:' . h($ph) . '?&amp;body=' . h(rawurlencode($t)) . '" data-review-ask="' . $id . '">💬 Text</a>' : '')
        . ($email ? '<a class="btn sm" href="mailto:' . h((string)$email) . '?subject=' . h(rawurlencode('Thanks from ' . ($S['name'] ?: 'us'))) . '&amp;body=' . h(rawurlencode($t)) . '" data-review-ask="' . $id . '">✉️ Email</a>' : '');
}

/* ---------- How the shop works (roadmap #19): mobile mechanics, signatures on the screen ---------- */

function migrate_modes(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('location', $cols('jobs'), true)) $pdo->exec('ALTER TABLE jobs ADD COLUMN location TEXT');
    if (!in_array('address', $cols('appointments'), true)) $pdo->exec('ALTER TABLE appointments ADD COLUMN address TEXT');
    if (!in_array('sig', $cols('job_auth'), true)) $pdo->exec('ALTER TABLE job_auth ADD COLUMN sig TEXT');
}
function maps_url(string $address): string { return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address); }
/** Google Maps directions through the day's addresses in time order (from wherever the phone is now). */
function route_url(array $addresses): string {
    $a = array_values(array_unique(array_filter(array_map('trim', $addresses))));
    if (!$a) return '';
    $dest = array_pop($a);
    return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode($dest) . ($a ? '&waypoints=' . rawurlencode(implode('|', array_slice($a, 0, 9))) : '');
}
/** A signature drawn on the screen (PNG data URL) saved as a private file. Returns the stored name or ''. */
function save_signature(string $dataUrl, int $jobId): string {
    if (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', $dataUrl, $m) || strlen($m[1]) > 400000) return '';
    $png = base64_decode($m[1], true);
    if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return '';
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    $name = 'sig-' . $jobId . '-' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents(UPLOAD_DIR . '/' . $name, $png);
    return $name;
}
/** The signature as an inline image for printed documents (no extra file route needed). */
function signature_img(?string $name): string {
    $f = $name ? realpath(UPLOAD_DIR . '/' . basename($name)) : false;
    if (!$f || !str_starts_with($f, realpath(UPLOAD_DIR) . DIRECTORY_SEPARATOR) || !is_file($f)) return '';
    return '<img class="sig-img" alt="Customer signature" src="data:image/png;base64,' . base64_encode((string)file_get_contents($f)) . '">';
}

/* ---------- PartsTech (parts ordering inside a repair order) ----------
 * Applied for partner access 2026-11-09 (PartsTech-Partner-API@oeconnection.com). Until PartsTech issues partner
 * credentials (PARTSTECH_PARTNER_ID / _KEY in config) this only keeps each shop's own PartsTech Username + API Key
 * (labelled exactly as PartsTech requires; the key is never shown back) and an HTTPS endpoint for PartsTech's cart /
 * purchase callbacks, which keeps each payload against the shop for per-order accounting only (their API terms forbid
 * caching catalog data). The punchout session call is written once their current documentation arrives. */
function migrate_partstech(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS partstech_events (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, job_id INT, action TEXT, session_id TEXT, payload TEXT, received_at TEXT)');
}
function partstech_ready(): bool { return PARTSTECH_PARTNER_ID !== '' && PARTSTECH_PARTNER_KEY !== ''; }
/** The shop's private callback address for PartsTech (made once). */
function partstech_callback_url(): string {
    $S = shop(); $t = (string)($S['pt_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) { $t = bin2hex(random_bytes(16)); shop_save(['pt_token' => $t]); }
    return base_url() . '/' . url('pt_callback', ['s' => $t]);
}
