<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';

$mode = $_GET['mode'] ?? 'all';
$startId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;

if ($mode === 'all' && !$isAdmin) {
    http_response_code(403); exit('全体バックアップは管理者のみ可能です。');
}
if ($mode === 'folder' && $startId === null) {
    http_response_code(400); exit('フォルダIDが必要です。');
}

$startFolder = null;
if ($mode === 'folder') {
    $sharedModes = shared_folder_modes($pdo);
    $aclModes    = $isAdmin ? [] : acl_folder_modes($pdo, $uid);
    $st = $pdo->prepare('SELECT * FROM folders WHERE id=? AND deleted_at IS NULL');
    $st->execute([$startId]);
    $startFolder = $st->fetch();
    if (!$startFolder) { http_response_code(404); exit('フォルダが見つかりません。'); }
    if (effective_folder_mode($user, $startFolder, $sharedModes, $aclModes) === null) {
        http_response_code(403); exit('権限がありません。');
    }
}

$folders = $pdo->query('SELECT id, name, parent_id, owner_id, is_shared, share_mode FROM folders WHERE deleted_at IS NULL ORDER BY id')->fetchAll();
$foldersById = [];
foreach ($folders as $f) $foldersById[(int)$f['id']] = $f;

// ユーザ・グループ名解決
$usersById = [];
foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) $usersById[(int)$u['id']] = $u['username'];
$groupsById = [];
foreach ($pdo->query('SELECT id, name FROM groups')->fetchAll() as $g) $groupsById[(int)$g['id']] = $g['name'];

$sanitize = function (string $s): string {
    $s = preg_replace('#[\\\\/:*?"<>|]#', '_', $s);
    $s = preg_replace('#[\\x00-\\x1F]#', '_', $s);
    if ($s === '' || $s === '.' || $s === '..') $s = '_';
    return $s;
};

$pathCache = [];
$siblings = [];

if ($mode === 'all') {
    $build = null;
    $build = function (int $id) use (&$build, &$foldersById, &$pathCache, &$siblings, $sanitize): string {
        if (isset($pathCache[$id])) return $pathCache[$id];
        $f = $foldersById[$id] ?? null;
        if (!$f) return '';
        $parentPath = $f['parent_id'] !== null ? $build((int)$f['parent_id']) : '';
        $key = $f['parent_id'] === null ? 'ROOT' : (string)$f['parent_id'];
        if (!isset($siblings[$key])) $siblings[$key] = [];
        $name = $sanitize((string)$f['name']);
        $b = $name; $i = 2;
        while (isset($siblings[$key][$name])) { $name = $b . " ($i)"; $i++; }
        $siblings[$key][$name] = true;
        $pathCache[$id] = ($parentPath === '' ? '' : $parentPath . '/') . $name;
        return $pathCache[$id];
    };
    foreach (array_keys($foldersById) as $id) $build($id);
    $inScope = array_keys($foldersById);
} else {
    $inScope = [$startId];
    $i = 0;
    while ($i < count($inScope)) {
        $cur = $inScope[$i++];
        foreach ($foldersById as $fid => $row) {
            if ($row['parent_id'] !== null && (int)$row['parent_id'] === $cur) $inScope[] = $fid;
        }
    }
    $build = null;
    $build = function (int $id) use (&$build, &$foldersById, &$pathCache, &$siblings, $sanitize, $startId): string {
        if (isset($pathCache[$id])) return $pathCache[$id];
        $f = $foldersById[$id] ?? null; if (!$f) return '';
        if ($id === $startId) {
            $pathCache[$id] = $sanitize($f['name']);
            return $pathCache[$id];
        }
        $parentPath = $build((int)$f['parent_id']);
        $key = (string)$f['parent_id'];
        if (!isset($siblings[$key])) $siblings[$key] = [];
        $name = $sanitize((string)$f['name']);
        $b = $name; $i = 2;
        while (isset($siblings[$key][$name])) { $name = $b . " ($i)"; $i++; }
        $siblings[$key][$name] = true;
        $pathCache[$id] = $parentPath . '/' . $name;
        return $pathCache[$id];
    };
    foreach ($inScope as $id) $build($id);
}

if ($mode === 'all') {
    $files = $pdo->query('SELECT id,name,folder_id,owner_id,size,stored_name,mime_type,created_at FROM files WHERE deleted_at IS NULL ORDER BY folder_id, name')->fetchAll();
} else {
    $in = implode(',', array_map('intval', $inScope));
    $files = $pdo->query("SELECT id,name,folder_id,owner_id,size,stored_name,mime_type,created_at FROM files WHERE deleted_at IS NULL AND folder_id IN ($in) ORDER BY folder_id, name")->fetchAll();
}

$dirSeen = [];

$entries = []; // [zipPath, physicalPath OR null, mtime, contentInline OR null]
$manifestFiles = []; // [path, owner, mime, created_at]
foreach ($files as $f) {
    $folderId = $f['folder_id'] !== null ? (int)$f['folder_id'] : null;
    if ($folderId !== null && !isset($pathCache[$folderId])) continue;

    if ($folderId === null) {
        if ($mode === 'folder') continue;
        $ownerName = $sanitize($usersById[(int)$f['owner_id']] ?? 'unknown');
        $dir = '_root_files/' . $ownerName;
    } else {
        $dir = $pathCache[$folderId];
    }
    if (!isset($dirSeen[$dir])) $dirSeen[$dir] = [];
    $fname = $sanitize($f['name']);
    $base = $fname; $i = 2;
    while (isset($dirSeen[$dir][$fname])) {
        $dot = strrpos($base, '.');
        $fname = ($dot !== false && $dot > 0)
            ? substr($base, 0, $dot) . " ($i)" . substr($base, $dot)
            : $base . " ($i)";
        $i++;
    }
    $dirSeen[$dir][$fname] = true;

    $physical = storage_dir() . '/' . $f['stored_name'];
    if (!is_file($physical)) continue;
    $zipPath = $dir . '/' . $fname;
    $entries[] = [$zipPath, $physical, strtotime($f['created_at']) ?: time(), null];
    $manifestFiles[] = [
        'path' => $zipPath,
        'owner' => $usersById[(int)$f['owner_id']] ?? null,
        'mime' => $f['mime_type'],
        'created_at' => $f['created_at'],
        'name' => $f['name'],
    ];
}

if (!$entries) { http_response_code(404); exit('対象のファイルがありません。'); }

// === manifest 構築 ===
$manifestFolders = [];
foreach ($inScope as $fid) {
    $f = $foldersById[$fid] ?? null;
    if (!$f || !isset($pathCache[$fid])) continue;
    $aclSt = $pdo->prepare('SELECT mode, user_id, group_id FROM folder_acl WHERE folder_id = ?');
    $aclSt->execute([$fid]);
    $acl = [];
    foreach ($aclSt->fetchAll() as $a) {
        $entry = ['mode' => $a['mode']];
        if ($a['user_id']  !== null) $entry['user']  = $usersById[(int)$a['user_id']]  ?? null;
        if ($a['group_id'] !== null) $entry['group'] = $groupsById[(int)$a['group_id']] ?? null;
        if (!empty($entry['user']) || !empty($entry['group'])) $acl[] = $entry;
    }
    $manifestFolders[] = [
        'path'        => $pathCache[$fid],
        'name'        => $f['name'],
        'owner'       => $usersById[(int)$f['owner_id']] ?? null,
        'share_mode'  => $f['share_mode'] ?: null,
        'is_shared'   => (int)$f['is_shared'] === 1,
        'acl'         => $acl,
    ];
}

$manifest = [
    '_format'      => 'apiphp-backup',
    'version'      => 1,
    'exported_at'  => date('c'),
    'exporter'     => $user['username'] ?? null,
    'mode'         => $mode,
    'start_folder' => $startId,
    'folders'      => $manifestFolders,
    'files'        => $manifestFiles,
];
$manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$entries[] = ['__manifest__.json', null, time(), $manifestJson];

// === ZIP 生成 ===
$basename = ($mode === 'all'
    ? 'apiphp-backup-all'
    : 'apiphp-backup-' . preg_replace('#[\\\\/:*?"<>|\\s]#', '_', $startFolder['name'] ?? ('folder'.$startId)))
    . '-' . date('Ymd-His') . '.zip';

audit_log($pdo, $user, 'backup_zip', $mode==='all'?'system':'folder', $startId, $startFolder['name'] ?? null, [
    'mode'=>$mode, 'count'=>count($entries) - 1, 'manifest'=>true
]);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($basename));
header('X-Content-Type-Options: nosniff');

function dos_datetime(int $ts): array {
    $t = getdate($ts);
    $year = max($t['year'], 1980);
    $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | (intval($t['seconds'] / 2));
    $date = (($year - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    return [$time, $date];
}

if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'apiphp_bk_');
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit('ZIP 作成失敗。');
    }
    foreach ($entries as [$zipPath, $physical, $mtime, $inline]) {
        if ($inline !== null) {
            $zip->addFromString($zipPath, $inline);
        } else {
            $zip->addFile($physical, $zipPath);
        }
    }
    $zip->close();
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// === 純 PHP 実装 ===
$cdRecords = [];
$offset = 0;

foreach ($entries as [$zipPath, $physical, $mtime, $inline]) {
    if ($inline !== null) {
        $content = $inline;
    } else {
        $content = file_get_contents($physical);
        if ($content === false) continue;
    }
    $crc = crc32($content);
    $uncompressedSize = strlen($content);
    $deflated = function_exists('gzdeflate') ? gzdeflate($content, 6) : false;
    if ($deflated !== false && strlen($deflated) < $uncompressedSize) {
        $method = 8; $data = $deflated; $compressedSize = strlen($deflated);
    } else {
        $method = 0; $data = $content; $compressedSize = $uncompressedSize;
    }
    [$dosTime, $dosDate] = dos_datetime($mtime);
    $nameUtf8 = $zipPath;
    $nameLen = strlen($nameUtf8);
    $flag = 0x0800;

    $lfh = pack('VvvvvvVVVvv',
        0x04034b50, 20, $flag, $method, $dosTime, $dosDate,
        $crc, $compressedSize, $uncompressedSize, $nameLen, 0
    ) . $nameUtf8;
    echo $lfh;
    echo $data;

    $cdRecords[] = [
        'name'=>$nameUtf8,'flag'=>$flag,'method'=>$method,
        'dosTime'=>$dosTime,'dosDate'=>$dosDate,'crc'=>$crc,
        'compressedSize'=>$compressedSize,'uncompressedSize'=>$uncompressedSize,
        'offset'=>$offset,
    ];
    $offset += strlen($lfh) + strlen($data);
}

$cdStart = $offset;
$cdSize = 0;
foreach ($cdRecords as $r) {
    $nameLen = strlen($r['name']);
    $cd = pack('VvvvvvvVVVvvvvvVV',
        0x02014b50, 0x031E, 20, $r['flag'], $r['method'],
        $r['dosTime'], $r['dosDate'], $r['crc'],
        $r['compressedSize'], $r['uncompressedSize'],
        $nameLen, 0, 0, 0, 0, 0x81A40000, $r['offset']
    ) . $r['name'];
    echo $cd;
    $cdSize += strlen($cd);
}
$total = count($cdRecords);
$eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $total, $total, $cdSize, $cdStart, 0);
echo $eocd;
exit;
