<?php
require_once __DIR__ . '/includes/layout.php';
$me = require_admin();
$pdo = db();

/**
 * 純 PHP の ZIP 読み出し（ZipArchive 拡張がないサーバ対応）
 * @return array<int, array{name:string, content:string|false, method:int}>
 */
function read_zip_entries(string $zipPath): array {
    $fh = @fopen($zipPath, 'rb');
    if (!$fh) return [];
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh);
    if ($size < 22) { fclose($fh); return []; }
    $scanFrom = max(0, $size - 65557);
    fseek($fh, $scanFrom);
    $tail = fread($fh, $size - $scanFrom);
    $eocdRel = strrpos($tail, "\x50\x4b\x05\x06");
    if ($eocdRel === false) { fclose($fh); return []; }
    $eocd = substr($tail, $eocdRel, 22);
    $e = unpack('Vsig/vdisk/vcddisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLen', $eocd);
    if ($e === false) { fclose($fh); return []; }
    fseek($fh, $e['cdOffset']);
    $cd = fread($fh, $e['cdSize']);

    $entries = [];
    $pos = 0;
    while ($pos + 46 <= strlen($cd)) {
        $h = unpack('Vsig/vverMade/vverNeed/vflag/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdiskStart/viattr/Veattr/Voffset',
                    substr($cd, $pos, 46));
        if ($h === false || $h['sig'] !== 0x02014b50) break;
        $name = substr($cd, $pos + 46, $h['nameLen']);
        if (!($h['flag'] & 0x0800) && function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($name, 'UTF-8', 'SJIS-win,CP932,UTF-8');
            if ($conv !== false) $name = $conv;
        }
        $entries[] = [
            'name' => $name,
            'method' => $h['method'],
            'compSize' => $h['compSize'],
            'uncompSize' => $h['uncompSize'],
            'localOffset' => $h['offset'],
        ];
        $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
    }

    foreach ($entries as &$ent) {
        fseek($fh, $ent['localOffset']);
        $lhRaw = fread($fh, 30);
        if (strlen($lhRaw) < 30) { $ent['content'] = false; continue; }
        $lh = unpack('Vsig/vverNeed/vflag/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', $lhRaw);
        if ($lh === false || $lh['sig'] !== 0x04034b50) { $ent['content'] = false; continue; }
        fseek($fh, $ent['localOffset'] + 30 + $lh['nameLen'] + $lh['extraLen']);
        $data = fread($fh, $lh['compSize']);
        if ($lh['method'] === 0) {
            $ent['content'] = $data;
        } elseif ($lh['method'] === 8 && function_exists('gzinflate')) {
            $inflated = @gzinflate($data);
            $ent['content'] = $inflated === false ? false : $inflated;
        } else {
            $ent['content'] = false;
        }
    }
    unset($ent);
    fclose($fh);
    return $entries;
}

function ensure_folder_simple(PDO $pdo, string $path, int $ownerId, array &$cache, PDOStatement $insert): int {
    if (isset($cache[$path])) return $cache[$path];
    $parts = explode('/', $path);
    $name = array_pop($parts);
    $parentPath = implode('/', $parts);
    $parentId = $parentPath === '' ? null : ensure_folder_simple($pdo, $parentPath, $ownerId, $cache, $insert);
    $insert->execute([$name, $parentId, $ownerId, 0, null]);
    $id = (int)$pdo->lastInsertId();
    $cache[$path] = $id;
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (($_POST['confirm'] ?? '') !== 'RESTORE') {
        flash_set('確認文字列「RESTORE」を正しく入力してください。', 'error');
        header('Location: restore.php'); exit;
    }
    if (empty($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        flash_set('ZIP ファイルがアップロードされていません。', 'error');
        header('Location: restore.php'); exit;
    }

    $tmpZip = $_FILES['zip']['tmp_name'];
    $entriesRaw = read_zip_entries($tmpZip);
    if (!$entriesRaw) {
        flash_set('ZIP の解析に失敗しました。', 'error');
        header('Location: restore.php'); exit;
    }

    // manifest と通常エントリを分離 + 検証
    $manifest = null;
    $files = [];
    foreach ($entriesRaw as $e) {
        $name = str_replace('\\', '/', $e['name']);
        $name = preg_replace('#/+#', '/', $name);
        $name = ltrim($name, '/');
        if ($name === '' || substr($name, -1) === '/') continue;
        if (preg_match('#(^|/)\\.\\.(/|$)#', $name) || strpos($name, "\0") !== false) {
            flash_set('不正なパスを含んでいます: ' . $name, 'error');
            header('Location: restore.php'); exit;
        }
        if ($e['content'] === false) {
            flash_set('展開できないエントリがあります: ' . $name, 'error');
            header('Location: restore.php'); exit;
        }
        if ($name === '__manifest__.json') {
            $decoded = json_decode($e['content'], true);
            if (is_array($decoded) && ($decoded['_format'] ?? '') === 'apiphp-backup') {
                $manifest = $decoded;
            }
            continue;
        }
        $e['name'] = $name;
        $files[] = $e;
    }
    if (!$files && !$manifest) {
        flash_set('ZIP に有効なファイルが含まれていません。', 'error');
        header('Location: restore.php'); exit;
    }

    $adminId = (int)$me['id'];

    // username/groupname → id 解決テーブル
    $userIds = [];
    foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
        $userIds[$u['username']] = (int)$u['id'];
    }
    $groupIds = [];
    foreach ($pdo->query('SELECT id, name FROM groups')->fetchAll() as $g) {
        $groupIds[$g['name']] = (int)$g['id'];
    }

    $stats = [
        'manifest' => $manifest !== null,
        'folders' => 0, 'files' => 0,
        'acl_restored' => 0, 'acl_skipped' => 0,
        'owner_fallback' => 0,
        'physical_wiped' => 0,
    ];

    $pdo->beginTransaction();
    try {
        // === 全消去 ===
        foreach (glob(storage_dir() . '/*') as $f) {
            if (is_file($f)) { @unlink($f); $stats['physical_wiped']++; }
        }
        $pdo->exec('DELETE FROM files');
        $pdo->exec('DELETE FROM folders');
        // folder_acl は folders の CASCADE で消えるが、孤児があった場合に備え明示削除
        $pdo->exec('DELETE FROM folder_acl');

        $pathToId = []; // zip path => folder_id
        $folderInsertFull = $pdo->prepare('INSERT INTO folders (name, parent_id, owner_id, is_shared, share_mode) VALUES (?,?,?,?,?)');
        $aclInsert = $pdo->prepare('INSERT INTO folder_acl (folder_id, user_id, group_id, mode) VALUES (?,?,?,?)');
        $fileInsert = $pdo->prepare('INSERT INTO files (name, folder_id, owner_id, size, mime_type, stored_name, created_at) VALUES (?,?,?,?,?,?,?)');

        // === manifest がある場合: フォルダを先に元の構成で作成 ===
        if ($manifest !== null && !empty($manifest['folders'])) {
            $manifestFolders = $manifest['folders'];
            // 親が先に作られるよう深さ順
            usort($manifestFolders, function($a, $b) {
                return substr_count((string)($a['path']??''), '/') - substr_count((string)($b['path']??''), '/');
            });
            foreach ($manifestFolders as $mf) {
                $path = (string)($mf['path'] ?? '');
                if ($path === '') continue;
                if (isset($pathToId[$path])) continue;
                $parts = explode('/', $path);
                $name = $mf['name'] ?? array_pop($parts);
                if (!isset($mf['name'])) {
                    // name 未指定なら最終セグメントを使う
                } else {
                    array_pop($parts); // path の親側を取り出すため
                }
                $parentPath = implode('/', $parts);
                $parentId = $parentPath === '' ? null : ($pathToId[$parentPath] ?? null);
                $ownerName = $mf['owner'] ?? null;
                $ownerId = $ownerName !== null ? ($userIds[$ownerName] ?? null) : null;
                if ($ownerId === null) { $ownerId = $adminId; if ($ownerName) $stats['owner_fallback']++; }
                $shareMode = $mf['share_mode'] ?? null;
                $isShared = !empty($mf['is_shared']) || !empty($shareMode);
                $folderInsertFull->execute([$name, $parentId, $ownerId, $isShared ? 1 : 0, $shareMode]);
                $fid = (int)$pdo->lastInsertId();
                $pathToId[$path] = $fid;
                $stats['folders']++;
                // ACL 復元
                foreach ((array)($mf['acl'] ?? []) as $aclEntry) {
                    $uId = isset($aclEntry['user'])  ? ($userIds[$aclEntry['user']]   ?? null) : null;
                    $gId = isset($aclEntry['group']) ? ($groupIds[$aclEntry['group']] ?? null) : null;
                    if (($uId !== null || $gId !== null) && in_array($aclEntry['mode'] ?? '', ['view','edit'], true)) {
                        $aclInsert->execute([$fid, $uId, $gId, $aclEntry['mode']]);
                        $stats['acl_restored']++;
                    } else {
                        $stats['acl_skipped']++;
                    }
                }
            }
        }

        // === ファイル復元 ===
        // manifest がある場合は path => meta マップを作る
        $fileMetaByPath = [];
        if ($manifest !== null) {
            foreach ((array)($manifest['files'] ?? []) as $mfi) {
                if (isset($mfi['path'])) $fileMetaByPath[$mfi['path']] = $mfi;
            }
        }

        foreach ($files as $e) {
            $parts = explode('/', $e['name']);
            $fileName = array_pop($parts);
            $meta = $fileMetaByPath[$e['name']] ?? null;
            if ($meta && isset($meta['name'])) $fileName = $meta['name']; // 元ファイル名（衝突回避前）

            if (count($parts) >= 2 && $parts[0] === '_root_files') {
                $ownerName = $meta['owner'] ?? $parts[1];
                $ownerId = $userIds[$ownerName] ?? null;
                if ($ownerId === null) { $ownerId = $adminId; if ($ownerName) $stats['owner_fallback']++; }
                $folderId = null;
                $extra = array_slice($parts, 2);
                if ($extra) $fileName = implode('_', $extra) . '_' . $fileName;
            } elseif (count($parts) === 0) {
                $ownerName = $meta['owner'] ?? null;
                $ownerId = $ownerName !== null ? ($userIds[$ownerName] ?? null) : null;
                if ($ownerId === null) { $ownerId = $adminId; if ($ownerName) $stats['owner_fallback']++; }
                $folderId = null;
            } else {
                $folderPath = implode('/', $parts);
                if (isset($pathToId[$folderPath])) {
                    $folderId = $pathToId[$folderPath];
                } else {
                    // manifest に無いフォルダ。admin 所有・非共有で作成
                    $folderId = ensure_folder_simple($pdo, $folderPath, $adminId, $pathToId, $folderInsertFull);
                    $stats['folders']++;
                }
                $ownerName = $meta['owner'] ?? null;
                $ownerId = $ownerName !== null ? ($userIds[$ownerName] ?? null) : null;
                if ($ownerId === null) { $ownerId = $adminId; if ($ownerName) $stats['owner_fallback']++; }
            }

            $stored = bin2hex(random_bytes(16));
            $dest = storage_dir() . '/' . $stored;
            file_put_contents($dest, $e['content']);
            $size = filesize($dest);
            $mime = $meta['mime'] ?? (function_exists('mime_content_type') ? (mime_content_type($dest) ?: null) : null);
            $createdAt = $meta['created_at'] ?? date('Y-m-d H:i:s');
            $fileInsert->execute([$fileName, $folderId, $ownerId, $size, $mime, $stored, $createdAt]);
            $stats['files']++;
        }

        $pdo->commit();
        audit_log($pdo, $me, 'restore_zip', null, null, null, $stats);
        $msg = "リストア完了" . ($stats['manifest'] ? '（manifest 使用）' : '（パス情報のみ）')
            . ": フォルダ {$stats['folders']} 件、ファイル {$stats['files']} 件";
        if ($stats['manifest']) {
            $msg .= "、ACL 復元 {$stats['acl_restored']}";
            if ($stats['acl_skipped'])    $msg .= "（{$stats['acl_skipped']} スキップ）";
            if ($stats['owner_fallback']) $msg .= "、所有者不在 {$stats['owner_fallback']} 件は admin に置換";
        }
        $msg .= "。元ファイル {$stats['physical_wiped']} 件を物理削除しました。";
        flash_set($msg, 'success');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        audit_log($pdo, $me, 'restore_zip_failed', null, null, null, ['error' => $ex->getMessage()]);
        flash_set('リストア失敗: ' . $ex->getMessage(), 'error');
    }
    header('Location: files.php'); exit;
}

$counts = [
    'folders' => (int)$pdo->query('SELECT COUNT(*) FROM folders WHERE deleted_at IS NULL')->fetchColumn(),
    'files'   => (int)$pdo->query('SELECT COUNT(*) FROM files   WHERE deleted_at IS NULL')->fetchColumn(),
    'storage' => count(glob(storage_dir() . '/*')),
];

render_header('ZIP リストア', $me);
?>
<div class="card" style="max-width:780px;">
  <h2>ZIP リストア（完全上書き）</h2>
  <div class="flash error">
    ⚠️ <strong>警告</strong>: 実行すると、現在のすべてのフォルダ・ファイル（および物理保管ファイル）が削除され、ZIP の内容で完全置換されます。
    元に戻せません。実行前に「全体 ZIP バックアップ」で現状をエクスポートしておくことを推奨します。
  </div>
  <p>現在の状態</p>
  <ul>
    <li>フォルダ: <strong><?= $counts['folders'] ?></strong> 件</li>
    <li>ファイル: <strong><?= $counts['files'] ?></strong> 件</li>
    <li>_storage 内物理ファイル: <strong><?= $counts['storage'] ?></strong> 件</li>
  </ul>
  <hr>
  <h3>リストア仕様</h3>
  <ul class="muted">
    <li>ZIP に <code>__manifest__.json</code> が含まれる場合（apiphp 自身のバックアップ ZIP）：
      <ul>
        <li>フォルダの所有者・<code>share_mode</code>・<code>folder_acl</code> をすべて元通りに復元</li>
        <li>ユーザ・グループ名で照合 → 存在しない名前は admin に置換、ACL エントリはスキップ</li>
        <li>ファイルの所有者・<code>mime_type</code>・<code>created_at</code> も復元</li>
      </ul>
    </li>
    <li>外部 ZIP（manifest なし）の場合：パス階層と <code>_root_files/&lt;user&gt;/</code> による所有者推定のみ。share_mode・ACL は復元されません</li>
    <li>掲示板投稿の添付ファイル参照は無効化されます（投稿本体は残ります）</li>
    <li>ユーザ・グループ・監査ログ・テーマ等は影響を受けません</li>
  </ul>
  <hr>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>ZIP ファイル</label>
    <input type="file" name="zip" accept=".zip,application/zip" required>
    <label>確認: 続行するには <code>RESTORE</code> と入力してください</label>
    <input type="text" name="confirm" required pattern="RESTORE" placeholder="RESTORE" autocomplete="off">
    <div style="margin-top:14px;">
      <button type="submit" class="btn-danger" onclick="return confirm('完全上書きを実行します。元に戻せません。本当に続行しますか？');">完全上書きでリストア実行</button>
      <a class="btn btn-secondary" href="files.php">キャンセル</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
