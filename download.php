<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$f = $stmt->fetch();
if (!$f) { http_response_code(404); exit('ファイルが見つかりません。'); }

if ($user['role'] !== 'admin' && (int)$f['owner_id'] !== $uid) {
    $sharedModes = shared_folder_modes($pdo);
    $aclModes    = acl_folder_modes($pdo, $uid);
    $allowed = false;
    if ($f['folder_id']) {
        $fid = (int)$f['folder_id'];
        $allowed = isset($sharedModes[$fid]) || isset($aclModes[$fid]);
    }
    if (!$allowed) { http_response_code(403); exit('権限がありません。'); }
}

$path = storage_dir() . '/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('実体ファイルが見つかりません。'); }

audit_log($pdo, $user, 'file_download', 'file', (int)$f['id'], $f['name']);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (int)$f['size']);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($f['name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
