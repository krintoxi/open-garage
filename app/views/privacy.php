<?php
// The privacy promise in plain words, with export and delete one tap away (public page).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$signedIn = !empty($authed);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your data · <?= h(APP_NAME) ?></title><link rel="stylesheet" href="assets/doc.css?v=7"></head><body>
<div class="page privacy">
  <header class="doc-head"><div><b class="shop-name"><?= h(APP_NAME) ?></b><p class="mut" style="margin:4px 0 0">Your data, in plain words</p></div><div class="doc-id"><b>🔒 Privacy</b><small>Updated <?= h(date('M j, Y', filemtime(__FILE__))) ?></small></div></header>
  <section class="pv-promise"><h1>We never sell your data or share it with insurers, data brokers or advertisers.</h1>
    <p>Your vehicles, repairs, photos and customers belong to you. We keep them so the app works for you, and for nothing else.</p></section>
  <h2>What we keep</h2>
  <ul><li>What you enter: vehicles, mileage, problems, services, photos, and (for shops) customers, estimates and invoices.</li><li>Your account email, so you can sign in and get the reminders you switch on.</li><li>Basic server logs (IP address, time, page) to keep the service safe from attacks.</li></ul>
  <h2>Who else sees anything</h2>
  <ul>
    <li><b>Photos you ask us to read</b> (VIN, plate, warning lights, scan screenshots) are read by our own model on our own server. They are not sent to an outside AI company.</li>
    <li><b>NHTSA</b> (the US safety agency) gets the VIN or the year, make and model when we look up the vehicle, recalls, bulletins and complaints. Nothing about you.</li>
    <li><b>Email</b> goes out through our email provider only to send the messages you asked for.</li>
    <li><b>Notifications</b> you turn on are sealed (encrypted) for your phone only; your browser's push service (Google, Apple or Mozilla) delivers them but can't read them.</li>
    <li><b>Shops:</b> only what you choose to send with "Send to a shop", and you can turn a link off any time. Card payments go through the shop's own Stripe account.</li>
    <li>No ad trackers and no analytics companies. Some pages load fonts from Google Fonts and the barcode scanner from the jsDelivr code library, which see your IP address like any website you visit.</li>
  </ul>
  <h2>Take it with you, or delete it</h2>
  <ul><li><b>Download everything</b> (all records as spreadsheets and data, plus your photos) any time, no fee.</li><li><b>Delete your account</b> and everything in it, permanently, from Vehicle &amp; settings.</li></ul>
  <?php if ($signedIn): ?><p style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn primary" href="<?= h(url('export', ['zip' => 1])) ?>">⬇ Download everything</a><a class="btn" href="<?= h(url('vehicle')) ?>#account">Delete my account</a><a class="btn" href="<?= h(url('dashboard')) ?>">Back to my garage</a></p>
  <?php else: ?><p><a class="btn" href="<?= h(url('login')) ?>">Back</a></p><?php endif; ?>
  <p class="mut" style="font-size:13px">Questions: reply to any email from us. <?= h(APP_NAME) ?> is also open source, so anyone can check what it does with data.</p>
</div></body></html>
