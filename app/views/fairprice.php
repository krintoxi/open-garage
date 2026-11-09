<?php
// Driver: "Is this quote fair?" Pick the job, type the price you were quoted, see the usual range and what to ask.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Is this quote fair?';
$jobs = fair_jobs();
$job = isset($_GET['job'], $jobs[$_GET['job']]) ? (string)$_GET['job'] : '';
$amt = isset($_GET['amount']) ? max(0, (float)preg_replace('/[^\d.]/', '', (string)$_GET['amount'])) : 0.0;
$V = $veh ?: [];
$R = $job ? fair_range($job, $V) : null;
$verdict = $R && $amt > 0 ? fair_verdict($amt, $R) : null;
$byCat = []; foreach ($jobs as $n => [$c]) $byCat[$c][] = $n;
$usd = fn($v) => '$' . number_format((float)$v);
?>
<div class="head"><div><h1>Is this quote fair?</h1><p>Pick the job and type the price you were quoted. You'll see the usual range<?= $V ? ' for a ' . h(vehicle_name($V)) : '' ?> and what to ask the shop. A rough guide, not a quote.</p></div></div>

<div class="grid g3">
  <div style="grid-column:span 2">
    <form method="get" class="card fp-form"><input type="hidden" name="p" value="fairprice">
      <label>What's the job?</label>
      <select name="job" required><option value="">Choose…</option><?php foreach ($byCat as $c => $ns): ?><optgroup label="<?= h($c) ?>"><?php foreach ($ns as $n): ?><option value="<?= h($n) ?>" <?= $n === $job ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
      <label>The price you were quoted (total, before tax)</label>
      <div class="row" style="flex-wrap:nowrap;gap:8px"><input type="number" name="amount" min="0" step="1" inputmode="decimal" placeholder="e.g. 540" value="<?= $amt > 0 ? h((string)round($amt)) : '' ?>"><button class="primary">Check</button></div>
      <p class="muted" style="font-size:12.5px;margin:8px 0 0">No quote yet? Leave the price empty to just see the usual range.</p>
    </form>

    <?php if ($R): ?>
    <section class="card fp-result<?= $verdict ? ' fp-' . h($verdict[0]) : '' ?>" style="margin-top:14px">
      <?php if ($verdict): ?><div class="fp-verdict"><span><?= ['low' => '🔎', 'fair' => '✅', 'high' => '🤔', 'very_high' => '⚠️'][$verdict[0]] ?></span><div><b><?= h($usd($amt)) ?>: <?= h(['low' => 'lower than usual', 'fair' => 'a fair price', 'high' => 'on the high side', 'very_high' => 'well above usual'][$verdict[0]]) ?></b><p><?= h($verdict[1]) ?></p></div></div><?php endif; ?>
      <h2><?= h($job) ?></h2>
      <?php $span = max(1, $R['hi'] * 1.6, $amt * 1.1); ?><div class="fp-bar"><div class="fp-range" style="left:<?= round($R['lo'] / $span * 100, 1) ?>%;right:<?= round(100 - $R['hi'] / $span * 100, 1) ?>%"></div><?php if ($amt > 0): ?><i class="fp-mark" style="left:<?= min(99, max(1, round($amt / $span * 100, 1))) ?>%"></i><?php endif; ?></div>
      <p class="fp-usual">Usual price: <b><?= h($usd($R['lo'])) ?> – <?= h($usd($R['hi'])) ?></b><?= $R['basis'] === 'shops' ? ' <small>(from ' . (int)$R['n'] . ' jobs at shops using ' . h(APP_NAME) . ')</small>' : '' ?></p>
      <?php if ($R['basis'] === 'typical'): ?><ul class="fp-parts">
        <?php if ($R['hours'] > 0): ?><li><b>Labor:</b> about <?= h((string)$R['hours']) ?> hours × $<?= FAIR_RATE[0] ?>–<?= FAIR_RATE[1] ?> an hour = <?= h($usd($R['labor'][0])) ?>–<?= h($usd($R['labor'][1])) ?></li><?php endif; ?>
        <?php if ($R['parts'][1] > 0): ?><li><b>Parts:</b> <?= h($usd($R['parts'][0])) ?>–<?= h($usd($R['parts'][1])) ?> (aftermarket quality; original-maker parts cost more)</li><?php endif; ?>
      </ul><?php endif; ?>
      <?php if ($R['premium']): ?><p class="muted" style="font-size:13px">Adjusted for <?= h((string)$V['make']) ?>: parts and labor usually cost more on this make.</p><?php endif; ?>
      <h3>Good questions to ask</h3>
      <ul class="fp-ask"><li>Can I have it itemized: parts, labor hours and the hourly rate?</li><li>Are the parts new, rebuilt or used, and which brand?</li><li>What is the warranty on parts and labor?</li><li>Is anything else included (diagnosis, disposal fees, alignment)?</li></ul>
      <p class="muted" style="font-size:12px">A rough guide: prices vary by region, by car and by parts brand, and dealers usually charge more. Tax is not included.</p>
    </section>
    <?php endif; ?>
  </div>
  <div class="aside">
    <div class="card"><h2>Why ask?</h2><p class="muted" style="font-size:14px">Most shops are honest, but prices for the same job can differ a lot. Knowing the usual range lets you ask the right questions without arguing.</p>
      <?php if ($V): ?><a class="btn sm" href="<?= url('send_shop') ?>">📤 Send a shop your car's details</a><?php endif; ?></div>
  </div>
</div>
