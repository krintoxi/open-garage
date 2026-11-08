<?php
// "Can I keep driving?" (2026-11-08, roadmap #2): one plain answer everywhere a driver meets a problem. Three levels:
// stop (don't drive it), book (drive gently, get it fixed soon), watch (fine to drive, keep an eye on it).
// Always followed by likely causes and "confirm with a technician": a code or a light points to a system, not a part.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const VERDICTS = [
    'stop' => ['🔴', 'Stop driving', 'Pull over when it is safe and switch off. Driving on can cause serious damage or be unsafe. Have it checked (or towed) first.'],
    'book' => ['🟠', 'Book a repair soon', 'You can drive it, gently and only as needed. Get it looked at soon so it does not get worse or more expensive.'],
    'watch' => ['🟢', 'OK to drive', 'Nothing urgent. Keep an eye on it and mention it at your next service.'],
];
const VERDICT_RANK = ['watch' => 0, 'book' => 1, 'stop' => 2];

/** Diagnosis knowledge base levels (ok / soon / careful / stop) to the three answers. */
function verdict_from_drive(string $d): string { return ['stop' => 'stop', 'careful' => 'book', 'soon' => 'book', 'ok' => 'watch'][$d] ?? 'book'; }
/** An owner-logged problem: severity 5 stop, 4-3 book, below that watch; problems being watched are "watch". */
function verdict_from_issue(array $i): string {
    $s = (int)$i['severity'];
    if (($i['status'] ?? '') === 'monitoring') return $s >= 5 ? 'book' : 'watch';
    return $s >= 5 ? 'stop' : ($s >= 3 ? 'book' : 'watch');
}
function verdict_worse(string $a, string $b): string { return VERDICT_RANK[$b] > VERDICT_RANK[$a] ? $b : $a; }

/** The answer as a box. $why: one line on what it's about; $todo: the first thing to do. */
function verdict_box(string $v, string $why = '', string $todo = '', bool $confirm = true): string {
    [$ico, $head, $text] = VERDICTS[$v] ?? VERDICTS['book'];
    return '<div class="verdict verdict-' . h($v) . '"><span class="vd-ico" aria-hidden="true">' . $ico . '</span><div><small>Can I keep driving?</small><b>' . h($head) . '</b>'
        . '<p>' . h($why !== '' ? $why : $text) . '</p>' . ($todo !== '' ? '<p class="vd-todo"><b>First:</b> ' . h($todo) . '</p>' : '')
        . ($confirm ? '<p class="vd-note">A code or a light points to a system, not a part. Confirm with a technician before buying parts.</p>' : '') . '</div></div>';
}
function verdict_pill(string $v, string $label = ''): string { [$ico, $head] = VERDICTS[$v] ?? VERDICTS['book']; return '<span class="vd-pill verdict-' . h($v) . '">' . $ico . ' ' . h($label !== '' ? $label : $head) . '</span>'; }

/** The whole vehicle: the worst of its open problems, active codes and "do not drive" recalls, with the reasons. */
function vehicle_verdict(int $vehId): array {
    $level = 'watch'; $why = [];
    foreach (rows("SELECT * FROM recalls WHERE vehicle_id=? AND status='open' AND park_it=1", [$vehId]) as $r) { $level = 'stop'; $why[] = ['stop', 'Do-not-drive safety recall: ' . $r['component'], url('recalls', ['v' => $vehId])]; }
    foreach (rows("SELECT * FROM issues WHERE vehicle_id=? AND status IN ('open','monitoring','scheduled') ORDER BY severity DESC", [$vehId]) as $i) { $l = verdict_from_issue($i); $level = verdict_worse($level, $l); $why[] = [$l, $i['title'], url('issue', ['id' => $i['id']])]; }
    $veh = row('SELECT * FROM vehicle WHERE id=?', [$vehId]) ?? [];
    foreach (rows("SELECT DISTINCT code FROM code_events WHERE vehicle_id=? AND status='active'", [$vehId]) as $c) {
        $k = obd_lookup((string)$c['code'], $veh); $l = verdict_from_drive((string)($k['kb']['drive'] ?? 'soon'));
        $level = verdict_worse($level, $l); $why[] = [$l, $c['code'] . ': ' . $k['title'], url('diagnose', ['codes' => $c['code']])];
    }
    usort($why, fn($a, $b) => VERDICT_RANK[$b[0]] <=> VERDICT_RANK[$a[0]]);
    return ['level' => $level, 'why' => $why];
}

/**
 * Dashboard warning lights: [name, emoji, usual colour, answer, what it means, what to do first].
 * The keys are what the photo reader (garage-vision /read-id?kind=light) answers with.
 */
const DASH_LIGHTS = [
    'oil_pressure' => ['Oil pressure', '🛢️', 'red', 'stop', 'The engine is not getting enough oil pressure. Minutes of driving like this can destroy the engine.', 'Stop and switch off now. Check the oil level when cool; do not drive on even if topping up seems to fix it.'],
    'coolant_temp' => ['Engine overheating', '🌡️', 'red', 'stop', 'The engine is too hot.', 'Pull over and switch off. Do not open the coolant cap while hot. Check for leaks and the coolant level once cool.'],
    'brake' => ['Brake system', '⚠️', 'red', 'stop', 'Low brake fluid, a brake fault, or the parking brake is still on.', 'Make sure the parking brake is fully released. If the light stays on, do not drive: check the brake fluid level and have the brakes inspected.'],
    'charging' => ['Battery / charging', '🔋', 'red', 'book', 'The alternator is not charging the battery. The car runs on the battery and may stall within about 30 minutes.', 'Drive straight to a shop or home, avoid switching the engine off, and turn off heated seats, rear defrost and the radio.'],
    'airbag' => ['Airbag', '🎈', 'red', 'book', 'A fault in the airbag system: the airbags may not deploy in a crash.', 'Safe to drive, but get it checked soon. Check for an airbag recall on the Recalls page.'],
    'check_engine' => ['Check engine', '🔧', 'amber', 'book', 'The engine computer found a fault. Steady: usually safe to drive. Flashing: a misfire that can overheat the catalytic converter.', 'If it is flashing, slow down and stop driving. Otherwise read the code (any parts store will, free) and look it up here.'],
    'reduced_power' => ['Reduced engine power', '🐢', 'amber', 'book', 'The car limited its own power to protect the engine or transmission ("limp mode").', 'Drive gently to a shop. Read the codes; it is often a throttle, sensor or transmission fault.'],
    'transmission' => ['Transmission', '⚙️', 'amber', 'book', 'A transmission fault or high transmission temperature.', 'If it says the transmission is hot, stop and let it cool. Otherwise drive gently and have the codes read soon.'],
    'abs' => ['ABS', '🅰️', 'amber', 'book', 'The anti-lock braking system is off. Normal braking still works, but wheels can lock in a hard stop.', 'Drive carefully and leave more room. Have it checked soon (often a wheel speed sensor).'],
    'traction' => ['Traction / stability control', '🚗', 'amber', 'watch', 'Flashing while driving means it is working on a slippery road. Staying on means it is switched off or has a fault.', 'If it stays on, try switching the car off and on. If it remains, have it checked; drive carefully on wet roads.'],
    'esp_off' => ['Stability control off', '🚫', 'amber', 'watch', 'Traction / stability control has been switched off.', 'Press the ESP/TCS button to turn it back on.'],
    'tpms' => ['Tire pressure', '🛞', 'amber', 'book', 'One or more tires are low. Flashing for a minute then steady: a sensor fault.', 'Check all four tire pressures today (the right pressure is on the driver door sticker) and look for a nail.'],
    'power_steering' => ['Power steering', '🧭', 'amber', 'book', 'The power steering has a fault; steering may become heavy.', 'Drive carefully, especially at low speed and when parking. Have it checked soon.'],
    'brake_wear' => ['Brake pads worn', '🟤', 'amber', 'book', 'The brake pad wear sensor has been reached.', 'Book a brake job within a couple of weeks. Grinding noises mean sooner.'],
    'oil_level' => ['Oil level low', '🛢️', 'amber', 'book', 'The engine oil level is low (amber light, not the red pressure light).', 'Top up with the oil type in your manual soon, and watch for leaks or burning.'],
    'coolant_level' => ['Coolant level low', '💧', 'amber', 'book', 'The coolant is low, usually from a small leak.', 'Top up when the engine is cold and watch the temperature gauge. Look for drips under the car.'],
    'glow_plug' => ['Glow plugs (diesel)', '〰️', 'amber', 'watch', 'On at start-up: wait until it goes out before starting. Flashing while driving: an engine fault.', 'If it flashes while driving, have the codes read soon.'],
    'dpf' => ['Diesel particulate filter', '🌫️', 'amber', 'book', 'The particulate filter is getting full.', 'Drive at a steady highway speed for 15-20 minutes so it can clean itself. If it stays on, see a shop.'],
    'adblue' => ['AdBlue / DEF', '💠', 'amber', 'book', 'The diesel exhaust fluid is low or has a fault.', 'Refill soon: when it runs out, many cars will not restart.'],
    'hybrid_system' => ['Hybrid / EV system', '⚡', 'red', 'book', 'A fault in the hybrid or electric drive system.', 'If power drops, pull over safely. Otherwise drive to a shop that works on hybrids soon.'],
    'service' => ['Service due', '🔩', 'amber', 'watch', 'A scheduled service is due (oil change, inspection).', 'Book the service. Log it here when done so your reminders stay right.'],
    'low_fuel' => ['Low fuel', '⛽', 'amber', 'watch', 'Fuel is low, usually 30-50 miles left.', 'Fill up soon.'],
    'washer_fluid' => ['Washer fluid low', '🧴', 'amber', 'watch', 'The windshield washer fluid is low.', 'Top it up.'],
    'seatbelt' => ['Seatbelt', '🪢', 'red', 'watch', 'Someone is not buckled up.', 'Buckle up.'],
    'door_open' => ['Door / trunk open', '🚪', 'red', 'watch', 'A door, the hood or the trunk is not fully closed.', 'Close it firmly.'],
    'key' => ['Key / immobilizer', '🔑', 'amber', 'watch', 'The key battery is low, or the car did not recognise the key.', 'Replace the key fob battery. If the car will not start, hold the key against the start button.'],
    'lane_assist' => ['Driver assist unavailable', '🛣️', 'amber', 'watch', 'A camera or radar is blocked or a driver-assist feature is off.', 'Clean the windshield camera area and front sensors. If it stays on, have it checked.'],
    'high_beam' => ['High beams', '🔦', 'blue', 'watch', 'The high beams are on. Just an indicator.', 'Dip them for oncoming traffic.'],
    'cold_engine' => ['Engine cold', '❄️', 'blue', 'watch', 'The engine is still cold. Just an indicator.', 'Drive gently until it goes out.'],
    'cruise' => ['Cruise control on', '🟢', 'green', 'watch', 'Cruise control is active. Just an indicator.', 'Nothing to do.'],
];

/** Light keys from the vision text ("check_engine:amber" lines); unknown names are dropped. */
function lights_from_text(string $text): array {
    $out = [];
    foreach (preg_split('/[\r\n,;]+/', strtolower($text)) as $ln) {
        $k = trim(explode(':', trim($ln, " -*•\t"))[0]);
        $k = str_replace([' ', '-'], '_', $k);
        if (isset(DASH_LIGHTS[$k]) && !in_array($k, $out, true)) $out[] = $k;
    }
    usort($out, fn($a, $b) => VERDICT_RANK[DASH_LIGHTS[$b][3]] <=> VERDICT_RANK[DASH_LIGHTS[$a][3]]);
    return $out;
}

/** Read the lit warning lights in a dashboard photo (M4 vision). ['lights' => keys] or ['error' => text]. */
function light_scan(?array $file, string $rateKey = ''): array {
    if (VISION_URL === '') return ['error' => 'The photo reader is off on this server. Pick the light from the list instead.'];
    $max = str_starts_with($rateKey, 'ip:') ? 8 : 40;
    if (!rate_ok('light:' . ($rateKey ?: (string)uid()), $max, 86400)) return ['error' => 'That is a lot of photos for one day. Pick the light from the list instead.'];
    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || $file['size'] > 12 * 1024 * 1024) return ['error' => 'Choose a photo (up to 12 MB).'];
    $r = vision_call('POST', '/read-id?kind=light', (string)file_get_contents($file['tmp_name']), 120);
    if (!$r || $r['code'] !== 200) return ['error' => $r && $r['code'] === 503 ? 'The photo reader is busy right now. Try again in a minute, or pick the light from the list.' : 'The photo could not be read. Pick the light from the list instead.'];
    $j = json_decode($r['body'], true) ?: [];
    return ['lights' => lights_from_text((string)($j['text'] ?? '')), 'raw' => mb_substr((string)($j['text'] ?? ''), 0, 400)];
}
