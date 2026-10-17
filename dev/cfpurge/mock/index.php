<?php

/**
 * FAKE Cloudflare API v4 for the local test environment only (no real Cloudflare, no real
 * tokens). The web container resolves api.cloudflare.com to itself and Apache sends every
 * request here (see scripts/mock_cf_setup.sh).
 *
 * The token chooses the behaviour (it starts with "devtok-"):
 *   devtok-full-...       valid; Cache Purge + Zone Read
 *   devtok-purgeonly-...  valid; Cache Purge only (Zone Read refused with 403)
 *   devtok-noperm-...     valid and active, but no Cache Purge permission (purge refused with 403)
 *   devtok-inactive-...   token verify says "disabled"
 *   devtok-acct-...       account-owned token: /user/tokens/verify does not know it (401) but purge works
 *   devtok-probeonly-...  valid and verifies fine, but only the harmless test purge of the plugin
 *                         (address containing cfpurge-check-) succeeds: every real purge is refused (403)
 *   devtok-err500-...     every call answers 500
 *   devtok-slow-...       every call waits 20 seconds (timeout test)
 *   anything else         invalid token (401)
 *
 * Zones: 0123456789abcdef0123456789abcdef = cfdemo.localhost (the zone of the test site),
 *        fedcba9876543210fedcba9876543210 = other.example (a zone that does not cover the site),
 *        any other 32-hex id does not exist (404).
 * Every request is appended to /tmp/cfmock.log (the token is reduced to its behaviour name).
 */

const ZONES = [
    '0123456789abcdef0123456789abcdef' => 'cfdemo.localhost',
    'fedcba9876543210fedcba9876543210' => 'other.example',
];

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token  = preg_match('/^Bearer (\S+)$/', $auth, $m) ? $m[1] : '';
$kind   = preg_match('/^devtok-([a-z0-9]+)-/', $token, $m) ? $m[1] : 'invalid';
$body   = json_decode((string) file_get_contents('php://input'), true);

file_put_contents('/tmp/cfmock.log', json_encode([
    't'      => date('c'),
    'method' => $method,
    'path'   => $uri,
    'query'  => $_SERVER['QUERY_STRING'] ?? '',
    'token'  => $kind,
    'body'   => $body,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

function reply(int $code, $result = null, array $errors = []): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => $code < 300 && !$errors, 'errors' => $errors, 'messages' => [], 'result' => $result]);
    exit;
}

function fail(int $code, int $errno, string $message): never
{
    reply($code, null, [['code' => $errno, 'message' => $message]]);
}

if ($kind === 'slow') {
    sleep(20);
}

if ($kind === 'err500') {
    http_response_code(500);
    echo '<html><body>Internal Server Error</body></html>';
    exit;
}

if (!in_array($kind, ['full', 'purgeonly', 'noperm', 'inactive', 'acct', 'slow', 'probeonly'], true)) {
    fail(401, 1000, 'Invalid API Token');
}

$canZoneRead = $kind === 'full';

if ($method === 'GET' && $uri === '/client/v4/user/tokens/verify') {
    if ($kind === 'acct') {
        fail(401, 1000, 'Invalid API Token');
    }

    reply(200, ['id' => 'tokenid', 'status' => $kind === 'inactive' ? 'disabled' : 'active']);
}

if ($method === 'GET' && $uri === '/client/v4/zones') {
    if (!$canZoneRead) {
        fail(403, 9109, 'Unauthorized to access requested resource');
    }

    $name = $_GET['name'] ?? '';
    $list = [];

    foreach (ZONES as $id => $zname) {
        if ($zname === $name) {
            $list[] = ['id' => $id, 'name' => $zname];
        }
    }

    reply(200, $list);
}

if ($method === 'GET' && preg_match('#^/client/v4/zones/([0-9a-f]{32})$#', $uri, $m)) {
    if (!$canZoneRead) {
        fail(403, 9109, 'Unauthorized to access requested resource');
    }

    if (!isset(ZONES[$m[1]])) {
        fail(404, 7003, 'Could not route to /zones/' . $m[1] . ', perhaps your object identifier is invalid?');
    }

    reply(200, ['id' => $m[1], 'name' => ZONES[$m[1]]]);
}

if ($method === 'POST' && preg_match('#^/client/v4/zones/([0-9a-f]{32})/purge_cache$#', $uri, $m)) {
    if ($kind === 'noperm') {
        fail(403, 10000, 'Authentication error');
    }

    if ($kind === 'probeonly') {
        $files = $body['files'] ?? [];
        $probe = is_array($files) && $files !== [] && count(array_filter($files, fn ($f) => str_contains($f, 'cfpurge-check-'))) === count($files);

        if (!$probe) {
            fail(403, 10000, 'Authentication error');
        }
    }

    if (!isset(ZONES[$m[1]])) {
        fail(404, 7003, 'Could not route to /zones/' . $m[1] . '/purge_cache, perhaps your object identifier is invalid?');
    }

    $files = $body['files'] ?? null;

    if (($body['purge_everything'] ?? false) !== true && !is_array($files)) {
        fail(400, 1012, 'Request must contain one of "purge_everything", "files", "tags", "hosts" or "prefixes"');
    }

    if (is_array($files) && count($files) > 30) {
        fail(400, 1011, 'Too many URLs: at most 30 per request');
    }

    reply(200, ['id' => $m[1]]);
}

fail(404, 7000, 'No route for that URI');
