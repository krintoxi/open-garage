<?php
declare(strict_types=1);
// POST handlers for importing from another shop system (included from actions.php after the CSRF check).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$dir = DATA_DIR . '/imports'; if (!is_dir($dir)) { mkdir($dir, 0700, true); protect_dir($dir); }
$cur = (string)($_SESSION['import_file'] ?? '');
$curOk = $cur !== '' && str_starts_with($cur, $dir . '/u' . uid() . '-') && is_file($cur);
switch ($action) {
case 'import_upload':
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 20 * 1024 * 1024) { flash('Choose a CSV file (up to 20 MB).', 'err'); redirect(url('import')); }
    if ($curOk) @unlink($cur);
    $to = $dir . '/u' . uid() . '-' . bin2hex(random_bytes(6)) . '.csv';
    move_uploaded_file($f['tmp_name'], $to);
    $rows = import_rows($to, 3);
    if (count($rows) < 2) { @unlink($to); flash('That file has no rows to import. Export it from your old system as CSV.', 'err'); redirect(url('import')); }
    $_SESSION['import_file'] = $to; $_SESSION['import_source'] = mb_substr(post('source') ?: 'your old system', 0, 40);
    redirect(url('import', ['step' => 'map']));
case 'import_run':
    if (!$curOk) { flash('Upload the file again.', 'err'); redirect(url('import')); }
    $map = []; foreach (IMPORT_FIELDS as $k => $_) { $v = (string)($_POST['map'][$k] ?? ''); if ($v !== '' && ctype_digit($v)) $map[$k] = (int)$v; }
    if (!$map) { flash('Match at least one column.', 'err'); redirect(url('import', ['step' => 'map'])); }
    $rows = import_rows($cur); array_shift($rows);
    db()->beginTransaction();
    $n = import_apply($rows, $map, (string)($_SESSION['import_source'] ?? 'your old system'));
    db()->commit();
    @unlink($cur); unset($_SESSION['import_file']);
    flash('Imported: ' . $n['customers'] . ' new customers, ' . $n['vehicles'] . ' new vehicles, ' . $n['history'] . ' past repair orders' . ($n['skipped'] ? ' (' . $n['skipped'] . ' empty rows skipped)' : '') . '.');
    redirect(url('import'));
case 'import_cancel':
    if ($curOk) @unlink($cur); unset($_SESSION['import_file']); redirect(url('import'));
}
http_response_code(404); exit('Not found');
