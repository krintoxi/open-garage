<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Vehicle & settings';
$mechs = rows('SELECT * FROM mechanics WHERE user_id=? ORDER BY is_primary DESC, name', [uid()]);
$editM = (int)($_GET['mech'] ?? 0); $em = $editM ? row('SELECT * FROM mechanics WHERE id=? AND user_id=?', [$editM, uid()]) : null;
$hasPw = (bool)col("SELECT value FROM settings WHERE key='password_hash'");
?>
<div class="head"><div><h1>Vehicle & settings</h1><p>Facts about this vehicle, your mechanics, and app settings. Add or switch vehicles in the <a href="<?= url('garage') ?>">Garage</a>.</p></div><?php if (!MULTI_USER): ?><a class="btn" href="<?= url('export') ?>">Download database backup</a><?php endif; ?></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="action" value="vehicle_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
      <h2>This vehicle</h2>
      <div class="form-grid">
        <div><label>Type</label><select name="kind"><?php foreach (VEHICLE_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= ($veh['kind'] ?: 'car') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div><div><label>Nickname</label><input type="text" name="nickname" value="<?= h($veh['nickname'] ?? '') ?>"></div>
        <div><label>Year</label><input type="number" name="year" value="<?= h((string)$veh['year']) ?>"></div><div><label>Make</label><input type="text" name="make" value="<?= h($veh['make']) ?>"></div>
        <div><label>Model</label><input type="text" name="model" value="<?= h($veh['model']) ?>"></div><div><label>Trim</label><input type="text" name="trim" value="<?= h($veh['trim']) ?>"></div>
        <div><label>Engine</label><input type="text" name="engine" value="<?= h($veh['engine']) ?>"></div><div><label>Transmission</label><input type="text" name="transmission" value="<?= h($veh['transmission']) ?>"></div>
        <div><label>VIN</label><input type="text" name="vin" value="<?= h($veh['vin']) ?>" class="mono"></div><div><label>Plate</label><input type="text" name="plate" value="<?= h($veh['plate']) ?>"></div>
        <div class="full"><label>Color / finish</label><input type="text" name="color" value="<?= h($veh['color']) ?>"></div>
        <div class="full"><label>The build (one mod per line)</label><textarea name="mods"><?= h($veh['mods']) ?></textarea></div>
        <div class="full"><label>Notes</label><textarea name="notes"><?= h($veh['notes']) ?></textarea></div>
        <div class="full" id="photo"><label>Main photo (the dashboard showcase<?= $veh['model3d'] ? ', behind the "Photo" button' : '' ?>)</label><?php if (!empty($veh['photo_credit'])): ?><small class="muted" style="display:block;margin-bottom:6px"><?= h($veh['photo_credit']) ?>. Upload your own to replace it.</small><?php endif; ?><?php if ($veh['photo']): ?><img src="<?= h(photo_url($veh['photo'])) ?>" data-zoom alt="" style="max-height:160px;border-radius:10px;display:block;margin-bottom:8px;cursor:zoom-in"><?php endif; ?><input type="file" name="photo" accept="image/*,.heic"></div>
      </div>
      <div class="row end" style="margin-top:14px"><button class="primary" type="submit">Save vehicle</button></div>
    </form>
    <div class="row" style="margin-top:8px;gap:8px">
    <?php if ($veh['photo']): ?><form method="post"><input type="hidden" name="action" value="vehicle_photo_clear"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Clear main photo</button></form><?php endif; ?>
    <?php if (VISION_URL !== '' && $veh['photo'] && !$veh['photo_credit']): ?><form method="post"><input type="hidden" name="action" value="vision_redo"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm"><?= $veh['hero_cutout'] ? 'Redo showroom photo' : 'Make showroom photo' ?></button></form><?php if ($veh['hero_cutout']): ?><form method="post"><input type="hidden" name="action" value="vision_original"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Show my original photo instead</button></form><?php endif; ?><?php endif; ?>
    <?php if (!$veh['photo']): ?><form method="post"><input type="hidden" name="action" value="vehicle_stock_photo"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Find a stand-in photo online</button></form><?php endif; ?>
    </div>
    <?php if (count(vehicles()) > 1): ?>
    <form method="post" class="card" id="danger" style="margin-top:18px;border-color:rgba(239,68,68,.35)"><input type="hidden" name="action" value="vehicle_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
      <h3>Remove this vehicle</h3>
      <p class="muted" style="font-size:13.5px">Deletes <?= h(vehicle_name($veh)) ?> with all its issues, visits, checks, maintenance, parts and photos. This cannot be undone; download a backup first. Type the model name (<b><?= h($veh['model']) ?></b>) to confirm.</p>
      <div class="row"><input type="text" name="confirm_name" autocomplete="off" style="max-width:240px"><button class="sm danger" data-confirm="Delete this vehicle and everything recorded for it?">Delete vehicle</button></div>
    </form>
    <?php endif; ?>
  </div>
  <div>
    <div class="card">
      <h2>Mechanics</h2>
      <ul class="list"><?php foreach ($mechs as $m): ?><li><div class="grow"><span class="t"><?= h($m['name']) ?></span><?= $m['is_primary'] ? ' <span class="st st-done">primary</span>' : '' ?><small><?= h($m['address']) ?><?= $m['phone'] ? ' · ' . h($m['phone']) : '' ?><?= $m['maps_url'] ? ' · <a href="' . h($m['maps_url']) . '" target="_blank" rel="noopener">map ↗</a>' : '' ?></small><?php if ($m['notes']): ?><small class="dim"><?= h($m['notes']) ?></small><?php endif; ?></div><a class="btn sm ghost" href="<?= url('vehicle', ['mech' => $m['id']]) ?>#mech">edit</a></li><?php endforeach; ?></ul>
      <form method="post" id="mech" style="margin-top:10px"><input type="hidden" name="action" value="mechanic_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $em['id'] ?? '' ?>">
        <h3><?= $em ? 'Edit mechanic' : 'Add mechanic' ?></h3>
        <label>Name</label><input type="text" name="name" value="<?= h($em['name'] ?? '') ?>" required>
        <label>Phone</label><input type="text" name="phone" value="<?= h($em['phone'] ?? '') ?>">
        <label>Email</label><input type="text" name="email" value="<?= h($em['email'] ?? '') ?>">
        <label>Google Maps link</label><input type="url" name="maps_url" value="<?= h($em['maps_url'] ?? '') ?>">
        <label>Address / area</label><input type="text" name="address" value="<?= h($em['address'] ?? '') ?>">
        <label>Notes</label><textarea name="notes" style="min-height:60px"><?= h($em['notes'] ?? '') ?></textarea>
        <label class="inline"><input type="checkbox" name="is_primary" value="1" <?= ($em['is_primary'] ?? 0) ? 'checked' : '' ?>> Primary mechanic</label>
        <div class="row end" style="margin-top:10px"><?php if ($em): ?><a class="btn ghost" href="<?= url('vehicle') ?>">Cancel</a><?php endif; ?><button class="primary" type="submit">Save</button></div>
      </form>
      <?php if ($em): ?><form method="post" class="right" style="margin-top:8px"><input type="hidden" name="action" value="mechanic_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $em['id'] ?>"><button class="sm ghost danger" data-confirm="Remove this mechanic?">Remove</button></form><?php endif; ?>
    </div>
    <?php if (MULTI_USER): $me = current_user(); ?>
    <div class="card" style="margin-top:18px" id="access">
      <h2>Your account</h2>
      <p class="muted" style="font-size:13.5px">Signed in as <b><?= h($me['email'] ?? '') ?></b><?= empty($me['verified_at']) ? ' (email not confirmed yet)' : '' ?>.</p>
      <form method="post"><input type="hidden" name="action" value="account_password"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required>
        <label>New password (at least 8 characters)</label><input type="password" name="password" autocomplete="new-password" minlength="8" required>
        <label>New password again</label><input type="password" name="password2" autocomplete="new-password" minlength="8" required>
        <div class="row end" style="margin-top:8px"><button type="submit">Change password</button></div></form>
      <div class="row" style="margin-top:10px;gap:8px"><a class="btn sm" href="<?= url('export') ?>">Download all my data</a><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Log out</button></form></div>
      <details id="account" style="margin-top:14px"><summary class="muted">Delete my account</summary>
        <form method="post" style="margin-top:8px"><input type="hidden" name="action" value="account_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><p class="muted" style="font-size:13px">Deletes every vehicle, record and photo in this account. It cannot be undone.</p><label>Password</label><input type="password" name="current_password" required><div class="row end" style="margin-top:8px"><button class="sm danger" data-confirm="Delete your account and everything in it?">Delete account</button></div></form>
      </details>
    </div>
    <?php else: ?>
    <div class="card" style="margin-top:18px" id="access">
      <h2>Access</h2>
      <p class="muted" style="font-size:13.5px"><?= $hasPw ? 'Password protection is ON for ' . h((string)($_SERVER['HTTP_HOST'] ?? 'this site')) . '. Changing it signs out your other devices. Log out is also in the menu (top right on a phone).' : 'No password set: the app only opens on this computer. Set one before hosting it.' ?></p>
      <form method="post"><input type="hidden" name="action" value="set_password"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <?php if ($hasPw): ?><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required><?php endif; ?>
        <label>New password (at least 8 characters)</label><input type="password" name="password" autocomplete="new-password" minlength="8" required>
        <label>New password again</label><input type="password" name="password2" autocomplete="new-password" minlength="8" required>
        <div class="row end" style="margin-top:8px"><button type="submit"><?= $hasPw ? 'Change password' : 'Set password' ?></button></div></form>
      <?php if ($hasPw): ?><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Log out</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
