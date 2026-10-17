<?php

/**
 * Tiny helper for the local dev container: runs one SQL statement against the
 * dev database and prints the rows as tab separated text.
 * Usage: php /dev-scripts/sql.php "SELECT ..."
 *
 * Dev-only tool, never part of the plugin package.
 */

$sql = $argv[1] ?? '';

if ($sql === '') {
    fwrite(STDERR, "Usage: php sql.php \"SQL\"\n");
    exit(1);
}

$db = new mysqli('db', 'joomla', (string) getenv('DEV_DB_PASSWORD'), 'joomla');
$db->set_charset('utf8mb4');
$result = $db->query($sql);

if ($result === false) {
    fwrite(STDERR, $db->error . "\n");
    exit(1);
}

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        echo implode("\t", array_map(static fn ($v) => $v === null ? 'NULL' : str_replace(["\n", "\t"], ' ', (string) $v), $row)), "\n";
    }
} else {
    echo 'affected ' . $db->affected_rows . "\n";
}
