<?php
// Free notifications on the phone (2026-11-09): standard Web Push, no paid texting service. Each message is encrypted for
// the one device (RFC 8291, aes128gcm) and signed with this server's own key (VAPID, RFC 8292); the browser's push
// service (Google, Apple, Mozilla) only relays the sealed message. Works in Chrome / Edge / Firefox on Android and
// computers, and on iPhone when the app is added to the Home Screen (iOS 16.4+).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const PUSH_ACTIONS = ['push_subscribe', 'push_unsubscribe', 'push_test'];

function migrate_push(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subs (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, endpoint TEXT UNIQUE, p256dh TEXT, auth TEXT, ua TEXT, created_at TEXT, last_ok TEXT, fails INT DEFAULT 0)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_user ON push_subs (user_id)');
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
/** P-256 public point (65 bytes, uncompressed) as an OpenSSL key. */
function ec_pub_key(string $raw) { return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n"); }
function ec_pub_raw($key): string { $d = openssl_pkey_get_details($key)['ec']; return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT); }
function ec_new() { return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); }

/** This server's VAPID key pair, made once and kept (0600) next to the database. ['private' => PEM, 'public' => base64url point]. */
function vapid_keys(): array {
    static $k = null; if ($k) return $k;
    $f = DATA_DIR . '/vapid.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($j) || empty($j['private']) || empty($j['public'])) {
        $key = ec_new(); openssl_pkey_export($key, $pem);
        $j = ['private' => $pem, 'public' => b64u(ec_pub_raw($key)), 'created' => date('c')];
        file_put_contents($f, json_encode($j)); @chmod($f, 0600);
    }
    return $k = $j;
}

/** RFC 8291 body for one subscription: salt | record size | key id length | server public key | AES-128-GCM(payload | 0x02). */
function push_encrypt(string $uaPub, string $authSecret, string $payload): string {
    $as = ec_new(); $asPub = ec_pub_raw($as);
    $ecdh = openssl_pkey_derive(ec_pub_key($uaPub), $as);
    if ($ecdh === false || strlen($ecdh) !== 32) throw new RuntimeException('ECDH failed');
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPub . $asPub, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}
/** VAPID JWT (ES256) for the push service's origin. */
function vapid_jwt(string $endpoint): string {
    $u = parse_url($endpoint); $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $sub = base_url() !== '' && str_starts_with(base_url(), 'https://') ? base_url() : 'mailto:' . (preg_match('/<([^>]+)>/', (string)MAIL_FROM, $m) ? $m[1] : 'admin@localhost');
    $in = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    openssl_sign($in, $der, openssl_pkey_get_private(vapid_keys()['private']), OPENSSL_ALGO_SHA256);
    // DER SEQUENCE { INTEGER r, INTEGER s } -> 64 raw bytes.
    $o = 2; $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl; $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $in . '.' . b64u($raw);
}
/** Send one notification to one stored subscription. Returns the HTTP status (0 = network error). Gone ones are removed. */
function push_one(array $sub, array $msg): int {
    try { $body = push_encrypt(b64u_dec($sub['p256dh']), b64u_dec($sub['auth']), json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
    catch (Throwable $e) { return 0; }
    $c = curl_init($sub['endpoint']);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: ' . ($msg['urgent'] ?? false ? 'high' : 'normal'),
            'Authorization: vapid t=' . vapid_jwt($sub['endpoint']) . ', k=' . vapid_keys()['public']]]);
    curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    if (in_array($code, [404, 410], true)) q('DELETE FROM push_subs WHERE id=?', [$sub['id']]);
    elseif ($code >= 200 && $code < 300) q('UPDATE push_subs SET last_ok=?, fails=0 WHERE id=?', [now(), $sub['id']]);
    else q('UPDATE push_subs SET fails=fails+1 WHERE id=?', [$sub['id']]);
    return $code;
}
/**
 * Notify every device an account turned notifications on for. Quietly does nothing when there are none.
 * $url is a page in this app (index.php?...); $urgent for things like do-not-drive recalls.
 */
function push_send(int $userId, string $title, string $body, string $url = 'index.php', bool $urgent = false, string $tag = ''): int {
    if ($userId <= 0 || !function_exists('openssl_pkey_derive')) return 0;
    $n = 0;
    foreach (rows('SELECT * FROM push_subs WHERE user_id=? AND fails < 5', [$userId]) as $s) {
        $code = push_one($s, ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 240), 'url' => $url, 'tag' => $tag, 'urgent' => $urgent]);
        if ($code >= 200 && $code < 300) $n++;
    }
    return $n;
}
function push_on_for(int $userId): int { return (int)col('SELECT COUNT(*) FROM push_subs WHERE user_id=?', [$userId]); }

/** The "turn on notifications" card (one per settings page). */
function push_card(string $what): string {
    return '<div class="card push-card" data-push data-vapid="' . h(vapid_keys()['public']) . '"><h2>🔔 Notifications on this phone</h2>'
        . '<p class="muted" style="font-size:13.5px;margin-top:-6px">' . h($what) . ' Free: no texting service, nothing to install.</p>'
        . '<p class="push-state" data-push-state></p>'
        . '<div class="row" style="gap:8px;flex-wrap:wrap"><button type="button" class="primary sm" data-push-on hidden>Turn on</button><button type="button" class="sm" data-push-test hidden>Send me a test</button><button type="button" class="sm ghost" data-push-off hidden>Turn off on this phone</button></div>'
        . '<p class="muted push-ios" data-push-ios hidden style="font-size:12.5px">On iPhone: tap Share → <b>Add to Home Screen</b>, open the app from there, then turn notifications on.</p></div>';
}
