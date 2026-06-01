<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';

function fetch_folder(PDO $pdo, int $id, bool $includeDeleted = false): ?array {
    $sql = 'SELECT * FROM folders WHERE id = ?' . ($includeDeleted ? '' : ' AND deleted_at IS NULL');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function build_breadcrumb(PDO $pdo, ?int $folderId): array {
    $crumbs = [];
    while ($folderId) {
        $f = fetch_folder($pdo, $folderId, true);
        if (!$f) break;
        $crumbs[] = $f;
        $folderId = $f['parent_id'] ? (int)$f['parent_id'] : null;
    }
    return array_reverse($crumbs);
}

$currentId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
$current = $currentId ? fetch_folder($pdo, $currentId) : null;
if ($currentId && !$current) {
    flash_set('フォルダが見つかりません。', 'error');
    header('Location: files.php'); exit;
}

$sharedModes = shared_folder_modes($pdo);
$aclModes    = $isAdmin ? [] : acl_folder_modes($pdo, $uid);

function effective_mode_for(PDO $pdo, array $user, ?array $folder, array $sharedModes, array $aclModes): ?string {
    return effective_folder_mode($user, $folder, $sharedModes, $aclModes);
}
$currentMode = effective_mode_for($pdo, $user, $current, $sharedModes, $aclModes);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $writable = ($currentMode === 'edit'); // 現在のフォルダで書込可？

    if ($action === 'create_folder') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || preg_match('#[\\\\/:*?"<>|]#', $name)) {
            flash_set('フォルダ名が不正です。', 'error');
        } elseif (!$writable) {
            flash_set('このフォルダ内に作成する権限がありません。', 'error');
        } else {
            $stmt = $pdo->prepare('INSERT INTO folders (name, parent_id, owner_id, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $current['id'] ?? null, $uid, now_jst()]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, $user, 'folder_create', 'folder', $newId, $name, ['parent_id'=>$current['id']??null]);
            flash_set('フォルダを作成しました。', 'success');
        }
    } elseif ($action === 'upload') {
        if (!$writable) {
            flash_set('このフォルダにアップロードする権限がありません。', 'error');
        } elseif (empty($_FILES['files']['name'][0])) {
            flash_set('ファイルが選択されていません。', 'error');
        } else {
            $successCount = 0;
            $failCount = 0;
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                    $failCount++;
                    continue;
                }
                $orig = basename(str_replace('\\', '/', $_FILES['files']['name'][$i]));
                if ($orig === '' || strlen($orig) > 255) {
                    $failCount++;
                    continue;
                }
                $stored = bin2hex(random_bytes(16));
                $dest = storage_dir() . '/' . $stored;
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) {
                    $pdo->prepare('INSERT INTO files (name, folder_id, owner_id, size, mime_type, stored_name, created_at) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$orig, $current['id'] ?? null, $uid, (int)$_FILES['files']['size'][$i], $_FILES['files']['type'][$i] ?: null, $stored, now_jst()]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $user, 'file_upload', 'file', $newId, $orig, ['size'=>(int)$_FILES['files']['size'][$i], 'folder_id'=>$current['id']??null]);
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            if ($successCount > 0 && $failCount === 0) {
                flash_set("{$successCount}件のファイルをアップロードしました。", 'success');
            } elseif ($successCount > 0 && $failCount > 0) {
                flash_set("{$successCount}件のアップロードに成功し、{$failCount}件失敗しました。", 'warn');
            } elseif ($failCount > 0) {
                flash_set("アップロードに失敗しました（{$failCount}件）。", 'error');
            } else {
                flash_set('有効なファイルがアップロードされませんでした。', 'error');
            }
        }
    } elseif ($action === 'rename_file' || $action === 'rename_folder') {
        $id = (int)($_POST['id'] ?? 0);
        $newName = trim($_POST['name'] ?? '');
        if ($newName === '' || preg_match('#[\\\\/:*?"<>|]#', $newName)) {
            flash_set('名前が不正です。', 'error');
        } else {
            $isFolder = $action === 'rename_folder';
            $tbl = $isFolder ? 'folders' : 'files';
            $st = $pdo->prepare("SELECT * FROM $tbl WHERE id=? AND deleted_at IS NULL");
            $st->execute([$id]); $row = $st->fetch();
            if (!$row) { flash_set('対象が見つかりません。', 'error'); }
            else {
                // 編集権限: 所有者 or admin or 親フォルダが edit 共有
                $parentFolder = $isFolder
                    ? ($row['parent_id'] ? fetch_folder($pdo, (int)$row['parent_id']) : null)
                    : ($row['folder_id'] ? fetch_folder($pdo, (int)$row['folder_id']) : null);
                $parentMode = effective_mode_for($pdo, $user, $parentFolder, $sharedModes, $aclModes);
                $allowed = $isAdmin || (int)$row['owner_id'] === $uid || $parentMode === 'edit';
                if (!$allowed) {
                    flash_set('リネーム権限がありません。', 'error');
                } else {
                    $pdo->prepare("UPDATE $tbl SET name=? WHERE id=?")->execute([$newName, $id]);
                    audit_log($pdo, $user, $action, $isFolder?'folder':'file', $id, $newName, ['old'=>$row['name']]);
                    flash_set('リネームしました。', 'success');
                }
            }
        }
    } elseif ($action === 'move_file' || $action === 'move_folder') {
        $id = (int)($_POST['id'] ?? 0);
        $destRaw = $_POST['dest'] ?? '';
        $dest = ($destRaw === '' || $destRaw === '0') ? null : (int)$destRaw;
        $isFolder = $action === 'move_folder';
        $tbl = $isFolder ? 'folders' : 'files';
        $st = $pdo->prepare("SELECT * FROM $tbl WHERE id=? AND deleted_at IS NULL");
        $st->execute([$id]); $row = $st->fetch();
        if (!$row) { flash_set('対象が見つかりません。', 'error'); header('Location: files.php' . ($currentId?'?folder='.$currentId:'')); exit; }
        // 元の場所と移動先で書込可必要
        $srcParent = $isFolder
            ? ($row['parent_id'] ? fetch_folder($pdo, (int)$row['parent_id']) : null)
            : ($row['folder_id'] ? fetch_folder($pdo, (int)$row['folder_id']) : null);
        $dstParent = $dest ? fetch_folder($pdo, $dest) : null;
        $srcMode = effective_mode_for($pdo, $user, $srcParent, $sharedModes, $aclModes);
        $dstMode = effective_mode_for($pdo, $user, $dstParent, $sharedModes, $aclModes);
        $allowed = $isAdmin || ((int)$row['owner_id'] === $uid && ($dstParent === null || $dstMode === 'edit'))
                   || ($srcMode === 'edit' && ($dstParent === null || $dstMode === 'edit'));
        if (!$allowed) {
            flash_set('移動権限がありません。', 'error');
        } elseif ($isFolder && $dest !== null) {
            // 循環防止: 自分自身 / 自分の子孫を親にできない
            $cur = $dest;
            $cycle = false;
            while ($cur !== null) {
                if ($cur === $id) { $cycle = true; break; }
                $f = fetch_folder($pdo, $cur, true);
                if (!$f) break;
                $cur = $f['parent_id'] ? (int)$f['parent_id'] : null;
            }
            if ($cycle) { flash_set('循環するため移動できません。', 'error'); }
            else {
                $pdo->prepare("UPDATE folders SET parent_id=? WHERE id=?")->execute([$dest, $id]);
                audit_log($pdo, $user, 'folder_move', 'folder', $id, $row['name'], ['from'=>$row['parent_id'],'to'=>$dest]);
                flash_set('移動しました。', 'success');
            }
        } else {
            $col = $isFolder ? 'parent_id' : 'folder_id';
            $pdo->prepare("UPDATE $tbl SET $col=? WHERE id=?")->execute([$dest, $id]);
            audit_log($pdo, $user, $action, $isFolder?'folder':'file', $id, $row['name'], ['from'=>$row[$col],'to'=>$dest]);
            flash_set('移動しました。', 'success');
        }
    } elseif ($action === 'delete_file') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM files WHERE id=? AND deleted_at IS NULL');
        $st->execute([$id]); $f = $st->fetch();
        if (!$f) { flash_set('ファイルが見つかりません。','error'); }
        else {
            if (!$isAdmin && (int)$f['owner_id'] !== $uid) {
                flash_set('削除権限がありません（所有者のみ削除可能です）。', 'error');
            } else {
                $pdo->prepare('UPDATE files SET deleted_at = ? WHERE id=?')->execute([now_jst(), $id]);
                audit_log($pdo, $user, 'file_trash', 'file', $id, $f['name']);
                flash_set('ファイルをゴミ箱へ移動しました。', 'success');
            }
        }
    } elseif ($action === 'delete_folder') {
        $id = (int)($_POST['id'] ?? 0);
        $f = fetch_folder($pdo, $id);
        if (!$f) { flash_set('フォルダが見つかりません。','error'); }
        else {
            $allowed = $isAdmin || (int)$f['owner_id'] === $uid;
            if (!$allowed) {
                flash_set('削除権限がありません（所有者のみ削除可能です）。', 'error');
                header('Location: files.php' . ($currentId?'?folder='.$currentId:'')); exit;
            }
            if ($allowed) {
                // 配下の他ユーザコンテンツ件数を数える（警告用 meta）
                $toScan = [(int)$f['id']]; $allFolderIds = $toScan;
                $i = 0;
                while ($i < count($toScan)) {
                    $cur = $toScan[$i++];
                    $s = $pdo->prepare('SELECT id FROM folders WHERE parent_id = ? AND deleted_at IS NULL');
                    $s->execute([$cur]);
                    foreach ($s->fetchAll() as $r) { $allFolderIds[] = (int)$r['id']; $toScan[] = (int)$r['id']; }
                }
                $in = implode(',', array_fill(0, count($allFolderIds), '?'));
                $cnt = $pdo->prepare("SELECT COUNT(*) c FROM files WHERE owner_id <> ? AND folder_id IN ($in) AND deleted_at IS NULL");
                $cnt->execute(array_merge([$uid], $allFolderIds));
                $other = (int)$cnt->fetch()['c'];
                // 論理削除（カスケード）
                $jstNow = now_jst();
                $pdo->prepare("UPDATE folders SET deleted_at=? WHERE id IN ($in)")->execute(array_merge([$jstNow], $allFolderIds));
                $pdo->prepare("UPDATE files SET deleted_at=? WHERE folder_id IN ($in)")->execute(array_merge([$jstNow], $allFolderIds));
                audit_log($pdo, $user, 'folder_trash', 'folder', $id, $f['name'], ['cascade_folders'=>count($allFolderIds), 'others_files'=>$other]);
                flash_set('フォルダをゴミ箱へ移動しました' . ($other ? "（他ユーザのファイル {$other} 件含む）" : '') . '。', 'success');
            }
        }
    } elseif ($action === 'set_share') {
        $id = (int)($_POST['id'] ?? 0);
        $mode = $_POST['share_mode'] ?? '';
        $f = fetch_folder($pdo, $id);
        if (!$f) { flash_set('フォルダが見つかりません。','error'); }
        elseif (!$isAdmin && (int)$f['owner_id'] !== $uid) { flash_set('共有設定変更は所有者または管理者のみです。','error'); }
        else {
            $newMode = in_array($mode, ['view','edit','private'], true) ? $mode : null;
            $isShared = in_array($newMode, ['view','edit'], true) ? 1 : 0;
            $pdo->prepare('UPDATE folders SET share_mode=?, is_shared=? WHERE id=?')->execute([$newMode, $isShared, $id]);
            audit_log($pdo, $user, 'folder_share', 'folder', $id, $f['name'], ['mode'=>$newMode]);
            $msg = $newMode === 'private' ? '非共有（遮断）に設定しました。' : ($newMode ? "全員に共有しました（{$newMode}）。" : '共有を解除しました（継承）。');
            flash_set($msg, 'success');
        }
    }
    header('Location: files.php' . ($currentId ? ('?folder=' . $currentId) : ''));
    exit;
}

// 表示権限チェック
if ($current && $currentMode === null) {
    flash_set('このフォルダを表示する権限がありません。', 'error');
    header('Location: files.php'); exit;
}

// 一覧取得（論理削除を除外）
$folderQ = 'SELECT f.*, u.username AS owner_username, u.display_name AS owner_display
            FROM folders f LEFT JOIN users u ON u.id = f.owner_id
            WHERE f.deleted_at IS NULL AND ' . ($currentId ? 'f.parent_id = ?' : 'f.parent_id IS NULL');
$fileQ   = 'SELECT f.*, u.username AS owner_username, u.display_name AS owner_display
            FROM files f LEFT JOIN users u ON u.id = f.owner_id
            WHERE f.deleted_at IS NULL AND ' . ($currentId ? 'f.folder_id = ?' : 'f.folder_id IS NULL');
$params = []; if ($currentId) $params[] = $currentId;

if (!$isAdmin) {
    $visibleFolderIds = array_unique(array_merge(array_keys($sharedModes), array_keys($aclModes)));
    $extra = [];
    $folderClauses = ['f.owner_id = ?']; $extra[] = $uid;
    $fileClauses   = ['f.owner_id = ?'];
    if ($visibleFolderIds) {
        $in = implode(',', array_map('intval', $visibleFolderIds));
        $folderClauses[] = "f.id IN ($in)";
        $fileClauses[]   = "f.folder_id IN ($in)";
    }
    $folderQ .= ' AND (' . implode(' OR ', $folderClauses) . ')';
    $fileQ   .= ' AND (' . implode(' OR ', $fileClauses) . ')';
    $params[] = $uid;
}
$folderQ .= ' ORDER BY f.name';
$fileQ   .= ' ORDER BY f.name';

$f1 = $pdo->prepare($folderQ); $f1->execute($params); $folders = $f1->fetchAll();
$f2 = $pdo->prepare($fileQ);   $f2->execute($params); $files   = $f2->fetchAll();

// 移動先候補を階層（フルパス）で構築
$allFolders = $pdo->query('SELECT id, name, parent_id, owner_id, share_mode FROM folders WHERE deleted_at IS NULL')->fetchAll();
$folderById = [];
foreach ($allFolders as $f) {
    $f['children'] = [];
    $folderById[$f['id']] = $f;
}
$roots = [];
foreach ($allFolders as $f) {
    if ($f['parent_id'] && isset($folderById[$f['parent_id']])) {
        $folderById[$f['parent_id']]['children'][] = $f['id'];
    } else {
        $roots[] = $f['id'];
    }
}
$sortByName = function($aId, $bId) use (&$folderById) {
    return strcasecmp($folderById[$aId]['name'], $folderById[$bId]['name']);
};
usort($roots, $sortByName);
foreach ($folderById as &$f) {
    usort($f['children'], $sortByName);
}
unset($f);

$moveTargets = [];
$traverse = function($id, $pathStr) use (&$traverse, &$folderById, &$moveTargets, $user, $sharedModes, $aclModes) {
    $f = $folderById[$id];
    $m = effective_folder_mode($user, $f, $sharedModes, $aclModes);
    $currentPath = $pathStr === '' ? $f['name'] : ($pathStr . ' / ' . $f['name']);
    
    if ($m === 'edit') {
        $f['display_path'] = $currentPath;
        $moveTargets[] = $f;
    }
    foreach ($f['children'] as $childId) {
        $traverse($childId, $currentPath);
    }
};
foreach ($roots as $rootId) {
    $traverse($rootId, '');
}

$crumbs = build_breadcrumb($pdo, $currentId);

// 各 crumb の表示可否（祖先非表示）
$crumbAccess = [];
foreach ($crumbs as $i => $c) {
    $m = effective_folder_mode($user, $c, $sharedModes, $aclModes);
    $crumbAccess[$i] = $m !== null;
}

render_header('ファイル管理', $user);
?>
<script>
function apiphpRename(btn) {
  var current = btn.getAttribute('data-name') || '';
  var n = prompt('新しい名前', current);
  if (n === null || n === '') return;
  var fm = document.getElementById(btn.getAttribute('data-target'));
  if (!fm) { alert('対象フォームが見つかりません'); return; }
  fm.elements['name'].value = n;
  fm.submit();
}
</script>
<div class="card">
  <div class="breadcrumb">
    <a href="files.php">ルート</a>
    <?php foreach ($crumbs as $i => $c): ?>
      /
      <?php if ($crumbAccess[$i]): ?>
        <a href="files.php?folder=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
      <?php else: ?>
        <span class="crumb-locked"><?= h($c['name']) ?></span>
      <?php endif; ?>
      <?php if (($c['share_mode']??'') === 'view' || ($c['share_mode']??'') === 'edit'): ?><span class="badge <?= $c['share_mode']==='view'?'shared-view':'shared' ?>">🌐 共有(<?= h($c['share_mode']) ?>)</span><?php elseif (($c['share_mode']??'') === 'private'): ?><span class="badge" style="background-color:#666;color:#fff;">🔒 非共有</span><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <p class="muted">
    <?php if ($isAdmin): ?>管理者: 全フォルダ・ファイルが見えます。
    <?php else: ?>一般ユーザ: 自分が作成したフォルダ、および「共有」や「個別アクセス権」が付与されたフォルダを表示します。<?php endif; ?>
  </p>
  <div style="margin-bottom:8px;">
    <?php if ($isAdmin): ?>
      <a class="btn" href="backup.php?mode=all" onclick="return confirm('全フォルダ・ファイルを ZIP でダウンロードします。サイズが大きいと時間がかかります。続行しますか？');">📦 全体 ZIP バックアップ</a>
      <a class="btn btn-warn" href="restore.php">↩️ ZIP リストア</a>
    <?php endif; ?>
    <?php if ($current && $currentMode !== null): ?>
      <a class="btn btn-secondary" href="backup.php?mode=folder&folder=<?= (int)$current['id'] ?>">📦 このフォルダを ZIP</a>
    <?php endif; ?>
  </div>
  <table>
    <thead><tr><th>種別</th><th>名前</th><th>所有者</th><th>サイズ</th><th>作成日</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$folders && !$files): ?>
      <tr><td colspan="6" class="muted">（このフォルダは空です）</td></tr>
    <?php endif; ?>
    <?php foreach ($folders as $f):
      $owns = (int)$f['owner_id'] === $uid || $isAdmin;
      $parentMode = $current ? $currentMode : 'edit';
      $canDelete = $owns;
      $canRename = $owns || $parentMode === 'edit';
    ?>
      <tr>
        <td>📁 フォルダ</td>
        <td>
          <a href="files.php?folder=<?= (int)$f['id'] ?>"><?= h($f['name']) ?></a>
          <?php if (($f['share_mode']??'') === 'view' || ($f['share_mode']??'') === 'edit'): ?>
            <span class="badge <?= $f['share_mode']==='view'?'shared-view':'shared' ?>">🌐 共有(<?= h($f['share_mode']) ?>)</span>
          <?php elseif (($f['share_mode']??'') === 'private'): ?>
            <span class="badge" style="background-color:#666;color:#fff;">🔒 非共有</span>
          <?php endif; ?>
        </td>
        <td><?= h($f['owner_display'] ?: $f['owner_username']) ?></td>
        <td>-</td>
        <td class="muted"><?= h($f['created_at']) ?></td>
        <td>
          <?php if ($owns): ?>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_share">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <select name="share_mode" onchange="this.form.submit()">
                <option value=""        <?= empty($f['share_mode'])?'selected':''           ?>>継承(未設定)</option>
                <option value="private" <?= ($f['share_mode']??'')==='private'?'selected':'' ?>>非共有(遮断)</option>
                <option value="view"    <?= ($f['share_mode']??'')==='view'?'selected':''   ?>>共有(view)</option>
                <option value="edit"    <?= ($f['share_mode']??'')==='edit'?'selected':''   ?>>共有(edit)</option>
              </select>
            </form>
          <?php endif; ?>
          <?php if ($canRename): ?>
            <button class="btn-secondary" type="button"
                    data-target="rn-f<?= (int)$f['id'] ?>"
                    data-name="<?= h($f['name']) ?>"
                    onclick="apiphpRename(this)">改名</button>
            <form method="post" class="inline" id="rn-f<?= (int)$f['id'] ?>">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename_folder">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="name" value="">
            </form>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="move_folder">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <select name="dest" onchange="if(confirm('移動しますか？'))this.form.submit()" style="max-width:200px;">
                <option value="">移動先...</option>
                <option value="0">ルートへ</option>
                <?php foreach ($moveTargets as $mt): if ((int)$mt['id']===(int)$f['id']) continue; ?>
                  <option value="<?= (int)$mt['id'] ?>"><?= h($mt['display_path']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
          <?php if ($canDelete): ?>
            <form method="post" class="inline" onsubmit="return confirm('フォルダと配下をゴミ箱へ移動します。よろしいですか？');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_folder">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn-danger" type="submit">削除</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($files as $f):
      $owns = (int)$f['owner_id'] === $uid || $isAdmin;
      $parentMode = $current ? $currentMode : 'edit';
      $canEdit = $owns || $parentMode === 'edit';
    ?>
      <tr>
        <td>📄 ファイル</td>
        <td><?= h($f['name']) ?></td>
        <td><?= h($f['owner_display'] ?: $f['owner_username']) ?></td>
        <td><?= number_format((int)$f['size']) ?> B</td>
        <td class="muted"><?= h($f['created_at']) ?></td>
        <td>
          <a class="btn btn-secondary" href="download.php?id=<?= (int)$f['id'] ?>">DL</a>
          <?php if ($canEdit): ?>
            <button class="btn-secondary" type="button"
                    data-target="rn-x<?= (int)$f['id'] ?>"
                    data-name="<?= h($f['name']) ?>"
                    onclick="apiphpRename(this)">改名</button>
            <form method="post" class="inline" id="rn-x<?= (int)$f['id'] ?>">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="rename_file">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="name" value="">
            </form>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="move_file">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <select name="dest" onchange="if(confirm('移動しますか？'))this.form.submit()" style="max-width:200px;">
                <option value="">移動先...</option>
                <option value="0">ルートへ</option>
                <?php foreach ($moveTargets as $mt): ?>
                  <option value="<?= (int)$mt['id'] ?>"><?= h($mt['display_path']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php if ($owns): ?>
            <form method="post" class="inline" onsubmit="return confirm('ゴミ箱へ移動します。よろしいですか？');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_file">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn-danger" type="submit">削除</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($currentMode === 'edit'): ?>
<div class="row">
  <div class="card">
    <h3>新規フォルダ作成</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_folder">
      <input type="text" name="name" placeholder="フォルダ名" required style="width:60%">
      <button type="submit">作成</button>
    </form>
  </div>
  <div class="card" id="drop-zone" style="border: 2px dashed var(--input-border); border-radius: 6px; text-align: center; padding: 30px 20px; transition: background-color 0.2s; cursor: pointer;">
    <h3 style="margin-top:0;">ファイルアップロード</h3>
    <p class="muted">ここにファイルをドラッグ＆ドロップ、またはクリックして選択してください（複数可）</p>
    <form method="post" enctype="multipart/form-data" id="upload-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <input type="file" name="files[]" id="file-input" multiple style="display:none;">
    </form>
  </div>
  <script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const uploadForm = document.getElementById('upload-form');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, preventDefaults, false);
      document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }

    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, () => {
        dropZone.style.borderColor = 'var(--link)';
        dropZone.style.backgroundColor = 'rgba(0,0,0,0.05)';
      }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, () => {
        dropZone.style.borderColor = 'var(--input-border)';
        dropZone.style.backgroundColor = '';
      }, false);
    });

    dropZone.addEventListener('drop', (e) => {
      let dt = e.dataTransfer;
      let files = dt.files;
      if (files && files.length > 0) {
        fileInput.files = files;
        uploadForm.submit();
      }
    });

    dropZone.addEventListener('click', () => {
      fileInput.click();
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files.length > 0) {
        uploadForm.submit();
      }
    });
  </script>
</div>
<?php elseif ($currentMode === 'view'): ?>
  <div class="card muted">このフォルダは閲覧専用です（書き込み権限がありません）。</div>
<?php else: ?>
  <div class="card muted">このフォルダは閲覧/編集権限がありません。</div>
<?php endif; ?>
<?php render_footer(); ?>
