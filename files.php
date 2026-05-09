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
            $stmt = $pdo->prepare('INSERT INTO folders (name, parent_id, owner_id) VALUES (?, ?, ?)');
            $stmt->execute([$name, $current['id'] ?? null, $uid]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($pdo, $user, 'folder_create', 'folder', $newId, $name, ['parent_id'=>$current['id']??null]);
            flash_set('フォルダを作成しました。', 'success');
        }
    } elseif ($action === 'upload') {
        if (!$writable) {
            flash_set('このフォルダにアップロードする権限がありません。', 'error');
        } elseif (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            flash_set('ファイルが選択されていません。', 'error');
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('アップロードに失敗しました（コード: ' . (int)$_FILES['file']['error'] . '）。', 'error');
        } else {
            $orig = basename(str_replace('\\', '/', $_FILES['file']['name']));
            if ($orig === '' || strlen($orig) > 255) {
                flash_set('ファイル名が不正です。', 'error');
            } else {
                $stored = bin2hex(random_bytes(16));
                $dest = storage_dir() . '/' . $stored;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    $pdo->prepare('INSERT INTO files (name, folder_id, owner_id, size, mime_type, stored_name) VALUES (?,?,?,?,?,?)')
                        ->execute([$orig, $current['id'] ?? null, $uid, (int)$_FILES['file']['size'], $_FILES['file']['type'] ?: null, $stored]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $user, 'file_upload', 'file', $newId, $orig, ['size'=>(int)$_FILES['file']['size'], 'folder_id'=>$current['id']??null]);
                    flash_set('アップロードしました: ' . $orig, 'success');
                } else {
                    flash_set('保存に失敗しました。', 'error');
                }
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
            $parent = $f['folder_id'] ? fetch_folder($pdo,(int)$f['folder_id']) : null;
            $pmode = effective_mode_for($pdo, $user, $parent, $sharedModes, $aclModes);
            if (!$isAdmin && (int)$f['owner_id'] !== $uid && $pmode !== 'edit') {
                flash_set('削除権限がありません。', 'error');
            } else {
                $pdo->prepare('UPDATE files SET deleted_at = CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
                audit_log($pdo, $user, 'file_trash', 'file', $id, $f['name']);
                flash_set('ファイルをゴミ箱へ移動しました。', 'success');
            }
        }
    } elseif ($action === 'delete_folder') {
        $id = (int)($_POST['id'] ?? 0);
        $f = fetch_folder($pdo, $id);
        if (!$f) { flash_set('フォルダが見つかりません。','error'); }
        else {
            // 共有 root（share_mode が設定されているフォルダ自身）は所有者/admin のみ
            $isSharedRoot = !empty($f['share_mode']);
            $allowed = $isAdmin || (int)$f['owner_id'] === $uid;
            if (!$allowed && $isSharedRoot) {
                flash_set('共有 root は所有者または管理者のみ削除できます。', 'error');
            } elseif (!$allowed) {
                $parent = $f['parent_id'] ? fetch_folder($pdo,(int)$f['parent_id']) : null;
                $pmode = effective_mode_for($pdo, $user, $parent, $sharedModes, $aclModes);
                if ($pmode !== 'edit') {
                    flash_set('削除権限がありません。', 'error');
                    header('Location: files.php' . ($currentId?'?folder='.$currentId:'')); exit;
                }
                $allowed = true;
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
                $pdo->prepare("UPDATE folders SET deleted_at=CURRENT_TIMESTAMP WHERE id IN ($in)")->execute($allFolderIds);
                $pdo->prepare("UPDATE files SET deleted_at=CURRENT_TIMESTAMP WHERE folder_id IN ($in)")->execute($allFolderIds);
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
            $newMode = in_array($mode, ['view','edit'], true) ? $mode : null;
            $pdo->prepare('UPDATE folders SET share_mode=?, is_shared=? WHERE id=?')->execute([$newMode, $newMode?1:0, $id]);
            audit_log($pdo, $user, 'folder_share', 'folder', $id, $f['name'], ['mode'=>$newMode]);
            flash_set($newMode ? "全員に共有しました（{$newMode}）。" : '共有を解除しました。', 'success');
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

// 移動先候補（書込可な自分のフォルダ）
$moveTargets = [];
$mt = $pdo->prepare('SELECT id, name, parent_id, owner_id, share_mode FROM folders WHERE deleted_at IS NULL ORDER BY name');
$mt->execute();
foreach ($mt->fetchAll() as $f) {
    $m = effective_folder_mode($user, $f, $sharedModes, $aclModes);
    if ($m === 'edit') $moveTargets[] = $f;
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
      <?php if (!empty($c['share_mode'])): ?><span class="badge <?= $c['share_mode']==='view'?'shared-view':'shared' ?>">🌐 共有(<?= h($c['share_mode']) ?>)</span><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <p class="muted">
    <?php if ($isAdmin): ?>管理者: 全フォルダ・ファイルが見えます。
    <?php else: ?>一般ユーザ: 自分の作成 + 共有/ACL されたフォルダ配下を表示します。<?php endif; ?>
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
      $canDelete = $owns || ($parentMode === 'edit' && empty($f['share_mode']));
      $canRename = $owns || $parentMode === 'edit';
    ?>
      <tr>
        <td>📁 フォルダ</td>
        <td>
          <a href="files.php?folder=<?= (int)$f['id'] ?>"><?= h($f['name']) ?></a>
          <?php if (!empty($f['share_mode'])): ?>
            <span class="badge <?= $f['share_mode']==='view'?'shared-view':'shared' ?>">🌐 共有(<?= h($f['share_mode']) ?>)</span>
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
                <option value=""     <?= empty($f['share_mode'])?'selected':''         ?>>非共有</option>
                <option value="view" <?= ($f['share_mode']??'')==='view'?'selected':'' ?>>共有(view)</option>
                <option value="edit" <?= ($f['share_mode']??'')==='edit'?'selected':'' ?>>共有(edit)</option>
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
              <select name="dest" onchange="if(confirm('移動しますか？'))this.form.submit()">
                <option value="">移動先...</option>
                <option value="0">ルートへ</option>
                <?php foreach ($moveTargets as $mt): if ((int)$mt['id']===(int)$f['id']) continue; ?>
                  <option value="<?= (int)$mt['id'] ?>"><?= h($mt['name']) ?></option>
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
              <select name="dest" onchange="if(confirm('移動しますか？'))this.form.submit()">
                <option value="">移動先...</option>
                <option value="0">ルートへ</option>
                <?php foreach ($moveTargets as $mt): ?>
                  <option value="<?= (int)$mt['id'] ?>"><?= h($mt['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('ゴミ箱へ移動します。よろしいですか？');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_file">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn-danger" type="submit">削除</button>
            </form>
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
  <div class="card">
    <h3>ファイルアップロード</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <input type="file" name="file" required>
      <button type="submit">アップロード</button>
    </form>
  </div>
</div>
<?php elseif ($currentMode === 'view'): ?>
  <div class="card muted">このフォルダは閲覧専用です（書き込み権限がありません）。</div>
<?php else: ?>
  <div class="card muted">このフォルダは閲覧/編集権限がありません。</div>
<?php endif; ?>
<?php render_footer(); ?>
