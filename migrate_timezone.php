<?php
// One-time migration: convert all UTC timestamps to JST (UTC+9)
// Access via browser: https://your-domain/migrate_timezone.php
// DELETE THIS FILE AFTER RUNNING

$dbFile = __DIR__ . '/data/app.db';
if (!file_exists($dbFile)) {
    die('DB not found: ' . $dbFile);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$queries = [
    "UPDATE users SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE folders SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE folders SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL",
    "UPDATE files SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE files SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL",
    "UPDATE posts SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE posts SET updated_at = datetime(updated_at, '+9 hours') WHERE updated_at IS NOT NULL",
    "UPDATE posts SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL",
    "UPDATE post_reads SET read_at = datetime(read_at, '+9 hours') WHERE read_at IS NOT NULL",
    "UPDATE groups SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE audit_log SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL",
    "UPDATE login_attempts SET attempted_at = datetime(attempted_at, '+9 hours') WHERE attempted_at IS NOT NULL",
];

echo "<pre>\n";
echo "=== Timezone Migration: UTC -> JST (UTC+9) ===\n\n";

$pdo->exec('BEGIN');
try {
    foreach ($queries as $sql) {
        $affected = $pdo->exec($sql);
        echo "OK ($affected rows): $sql\n";
    }
    $pdo->exec('COMMIT');
    echo "\n=== DONE - All timestamps converted to JST ===\n";
    echo "\n*** DELETE THIS FILE NOW: migrate_timezone.php ***\n";
} catch (Exception $e) {
    $pdo->exec('ROLLBACK');
    echo "\n!!! ERROR - ROLLED BACK: " . $e->getMessage() . "\n";
}
echo "</pre>\n";
