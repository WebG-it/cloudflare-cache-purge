<?php

/**
 * Dev-only: merges a JSON object into the params of a plugin (default: content/cfpurge),
 * or prints the params when called without JSON.
 *   php /dev-scripts/plugin_params.php '{"zone_id":"..."}' [folder element]
 */

$json    = $argv[1] ?? '';
$folder  = $argv[2] ?? 'content';
$element = $argv[3] ?? 'cfpurge';

$db = new mysqli('db', 'joomla', (string) getenv('DEV_DB_PASSWORD'), 'joomla');
$db->set_charset('utf8mb4');

$stmt = $db->prepare("SELECT extension_id, params FROM jdev_extensions WHERE type='plugin' AND folder=? AND element=?");
$stmt->bind_param('ss', $folder, $element);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    fwrite(STDERR, "plugin not found\n");
    exit(1);
}

$params = json_decode($row['params'] ?: '{}', true) ?: [];

if ($json === '') {
    // Never print the token.
    if (isset($params['api_token'])) {
        $params['api_token'] = '***' . strlen($params['api_token']) . ' chars***';
    }

    echo json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

foreach (json_decode($json, true) as $key => $value) {
    if ($value === null) {
        unset($params[$key]);
    } else {
        $params[$key] = $value;
    }
}

$new = json_encode($params, JSON_UNESCAPED_SLASHES);
$id  = (int) $row['extension_id'];
$upd = $db->prepare('UPDATE jdev_extensions SET params=? WHERE extension_id=?');
$upd->bind_param('si', $new, $id);
$upd->execute();
echo "ok\n";
