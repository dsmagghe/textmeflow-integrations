<?php
// Tiny stand-in for api.textmeflow.eu/v1 (and the /connect page) for local module tests.
// Run: php -S 0.0.0.0:8099 mock-api.php  (see README.md, "Lokaal testen").
// Helpers: GET /mock/scan marks WhatsApp as linked, GET /mock/reset clears the state.
$log = __DIR__ . '/requests.jsonl';
$state = __DIR__ . '/state.json';
$st = is_file($state) ? json_decode(file_get_contents($state), true) : null;
$st = is_array($st) ? $st : ['paired' => false, 'polls' => 0, 'revoked' => false];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
if ($path === '/mock/requests') {
    // The request log, read by e2e.py (not logged itself).
    header('Content-Type: application/x-ndjson');
    echo is_file($log) ? file_get_contents($log) : '';
    exit;
}
file_put_contents($log, json_encode([
    'ts' => date('c'), 'method' => $method, 'path' => $path, 'query' => $_GET,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'body' => $body,
]) . "\n", FILE_APPEND);

function out(int $code, $data = null): void
{
    http_response_code($code);
    if ($data !== null) {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
    exit;
}

function save(array $st): void
{
    file_put_contents(__DIR__ . '/state.json', json_encode($st));
}

$qr = 'data:image/png;base64,' . base64_encode((string) @file_get_contents(__DIR__ . '/qr.png'));

if ($path === '/mock/scan') {
    $st['paired'] = true;
    save($st);
    out(200, ['ok' => true]);
}
if ($path === '/mock/revoke' || $path === '/mock/unrevoke') {
    // Simulate a token revoked in the TextMeFlow portal (every authenticated call gets 401).
    $st['revoked'] = $path === '/mock/revoke';
    save($st);
    out(200, ['ok' => true]);
}
if ($path === '/mock/reset') {
    save(['paired' => false, 'polls' => 0, 'revoked' => false]);
    @unlink($log);
    out(200, ['ok' => true]);
}
if ($method === 'GET' && $path === '/connect') {
    // Fake approval: straight back to the shop.
    $ret = $_GET['return'];
    $sep = str_contains($ret, '?') ? '&' : '?';
    header('Location: ' . $ret . $sep . http_build_query(['code' => 'mockcode123', 'state' => $_GET['state']]));
    http_response_code(302);
    exit;
}
if ($method === 'POST' && $path === '/v1/connect/token') {
    $in = json_decode($body, true) ?: [];
    if (($in['code'] ?? '') !== 'mockcode123' || empty($in['return'])) {
        out(400, ['error' => 'invalid_code']);
    }
    $st['revoked'] = false;
    save($st);
    out(200, ['token' => 'tmfc_mocktoken', 'access_token' => 'tmfc_mocktoken', 'token_type' => 'Bearer', 'account_email' => 'shop@example.com', 'plan' => 'free', 'connected_numbers' => 0]);
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer tmfc_mocktoken' || !empty($st['revoked'])) {
    out(401, ['error' => 'unauthorized']);
}
if ($method === 'DELETE' && $path === '/v1/connect/token') {
    $st['paired'] = false;
    $st['revoked'] = true;
    save($st);
    out(204);
}
if ($method === 'GET' && $path === '/v1/accounts/me') {
    $sessions = $st['paired'] ? [['status' => 'connected', 'phone_number' => '+32470123456']] : [];
    out(200, ['email' => 'shop@example.com', 'plan_code' => 'free', 'wa_sessions' => $sessions]);
}
if ($method === 'POST' && $path === '/v1/accounts/me/pair') {
    $st['polls'] = 0;
    save($st);
    out(200, ['pair_id' => 'pair_1', 'qr_png_b64' => $qr, 'status' => 'pending', 'expires_at' => date('c', time() + 120)]);
}
if ($method === 'GET' && $path === '/v1/accounts/me/pair/pair_1') {
    $st['polls']++;
    save($st);
    out(200, ['status' => $st['paired'] ? 'connected' : 'pending', 'qr_png_b64' => $qr, 'expires_at' => date('c', time() + 120)]);
}
if ($method === 'POST' && $path === '/v1/messages') {
    $in = json_decode($body, true) ?: [];
    if (!preg_match('/^\+[1-9]\d{6,14}$/', $in['to'] ?? '')) {
        out(422, ['error' => 'invalid_recipient', 'reason' => 'must_be_e164']);
    }
    out(202, ['message_id' => 'msg_' . bin2hex(random_bytes(4)), 'status' => 'queued', 'recipient' => $in['to']]);
}
out(404, ['error' => 'not_found']);
