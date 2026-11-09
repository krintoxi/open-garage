<?php
// Printable estimate / work order / invoice for the shop (signed in).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$jid = (int)($_GET['id'] ?? 0);
if (!owns_row('jobs', $jid)) { http_response_code(404); exit('Not found'); }
$job = row('SELECT * FROM jobs WHERE id=?', [$jid]);
$lines = job_lines($jid); $S = shop();
$doc = in_array($_GET['doc'] ?? '', ['estimate', 'workorder', 'invoice'], true) ? $_GET['doc'] : 'estimate';
$insp = rows('SELECT * FROM job_inspect WHERE job_id=? ORDER BY sort', [$jid]);
$interactive = false;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= h(ucfirst($doc) . ' ' . ($doc === 'invoice' ? ($job['inv_no'] ?: $job['est_no']) : $job['est_no'])) ?></title><link rel="stylesheet" href="assets/doc.css?v=7"></head><body>
<div class="bar"><a href="<?= url('job', ['id' => $jid]) ?>">← Back</a><button type="button" class="primary" onclick="print()">Print / save as PDF</button></div>
<div class="page"><?php require APP_ROOT . '/app/views/_jobdoc.php'; ?></div></body></html>
