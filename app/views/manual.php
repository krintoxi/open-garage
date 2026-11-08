<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = "Owner's manual";
$qs = trim((string)($_GET['q'] ?? ''));
$pageNo = (int)($_GET['page'] ?? 0);
$total = (int)col('SELECT COUNT(*) FROM manual_pages WHERE ' . vw());
$edition = col('SELECT edition FROM manual_pages WHERE ' . vw() . ' LIMIT 1');
$pdf = 'manual/MY12_C-Class_Sedan_Operator.pdf';
$hasPdf = is_file(APP_ROOT . '/' . $pdf);
$results = [];
if ($qs !== '' && $total) {
    $results = rows('SELECT page, text FROM manual_pages WHERE ' . vw() . ' AND text LIKE ? ORDER BY page LIMIT 60', ['%' . $qs . '%']);
}
$pageRow = $pageNo ? row('SELECT * FROM manual_pages WHERE ' . vw() . ' AND page=?', [$pageNo]) : null;
function snippet(string $text, string $q, int $w = 180): string {
    $pos = stripos($text, $q); if ($pos === false) return h(mb_strimwidth($text, 0, $w * 2, '…'));
    $s = max(0, $pos - $w); $out = mb_substr($text, $s, $w * 2 + strlen($q));
    $out = preg_replace('/\s+/', ' ', $out);
    return ($s ? '…' : '') . preg_replace('/(' . preg_quote(h($q), '/') . ')/i', '<mark style="background:rgba(225,6,0,.35);color:#fff;border-radius:3px;padding:0 2px">$1</mark>', h($out)) . '…';
}
$quick = ['oil level', 'wheel bolt', 'tire pressure', 'coolant', 'fuse', 'jump-start', 'towing', 'display message', 'ASSYST', 'check engine', 'SmartKey', 'brake fluid', 'battery', 'octane', 'MOExtended'];
?>
<div class="head"><div><h1>Owner's manual</h1><p><?= $total ? h($edition) . ' · ' . $total . ' pages indexed. Printed page numbers are PDF page minus 2.' : 'No manual indexed yet. Put the PDF and its text JSON in the manual/ folder and reseed.' ?></p></div><?php if ($hasPdf): ?><a class="btn" href="<?= $pdf ?>" target="_blank">Open the PDF ↗</a><?php endif; ?></div>
<?php if (str_contains((string)$edition, 'C-Class')): ?><div class="callout" style="font-size:13.5px">This is the MY2012 C-Class sedan edition from Mercedes-Benz USA (dated 15 March 2011). It is the closest official edition to a 2011 car: same W204 generation, same 272 engine entries and 7G-Tronic. Some equipment pages describe the 2012 facelift; the technical data, fluids, capacities and procedures apply.</div><?php endif; ?>
<form method="get" class="search"><input type="hidden" name="p" value="manual"><input type="search" name="q" value="<?= h($qs) ?>" placeholder="Search the manual: oil level, wheel bolt, fuse, tire pressure, display message…" autofocus><button type="submit" class="primary">Search</button></form>
<div class="row" style="margin-bottom:14px"><?php foreach ($quick as $k): ?><a class="btn sm ghost" href="<?= url('manual', ['q' => $k]) ?>"><?= h($k) ?></a><?php endforeach; ?></div>
<?php if ($pageRow): ?>
<div class="card" style="margin-bottom:16px"><div class="row between"><h2>PDF page <?= $pageRow['page'] ?> <small class="muted">(printed <?= $pageRow['page'] - 2 ?>)</small></h2><div class="row"><a class="btn sm" href="<?= url('manual', ['page' => $pageRow['page'] - 1, 'q' => $qs]) ?>">← prev</a><a class="btn sm" href="<?= url('manual', ['page' => $pageRow['page'] + 1, 'q' => $qs]) ?>">next →</a><?php if ($hasPdf): ?><a class="btn sm" href="<?= $pdf ?>#page=<?= $pageRow['page'] ?>" target="_blank">open in PDF ↗</a><?php endif; ?></div></div>
<pre style="white-space:pre-wrap;font:14px/1.5 var(--font);color:var(--text);margin-top:10px"><?= $qs ? preg_replace('/(' . preg_quote(h($qs), '/') . ')/i', '<mark style="background:rgba(225,6,0,.35);color:#fff;border-radius:3px;padding:0 2px">$1</mark>', h($pageRow['text'])) : h($pageRow['text']) ?></pre></div>
<?php endif; ?>
<?php if ($qs !== ''): ?>
<div class="card"><h2><?= count($results) ?> page<?= count($results) === 1 ? '' : 's' ?> mention "<?= h($qs) ?>"</h2>
<?php if (!$results): ?><div class="empty">Nothing found. Try a shorter word.</div><?php endif; ?>
<ul class="list"><?php foreach ($results as $r): ?><li><div class="grow"><a class="t" href="<?= url('manual', ['page' => $r['page'], 'q' => $qs]) ?>">PDF page <?= $r['page'] ?> <small class="muted">(printed <?= $r['page'] - 2 ?>)</small></a><div style="font-size:13.5px;color:var(--muted)"><?= snippet($r['text'], $qs) ?></div></div><?php if ($hasPdf): ?><a class="btn sm ghost" href="<?= $pdf ?>#page=<?= $r['page'] ?>" target="_blank">PDF ↗</a><?php endif; ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
