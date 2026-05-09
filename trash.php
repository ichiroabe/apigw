<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $tbl = ['file'=>'files','folder'=>'folders','post'=>'posts'][$type] ?? null;
    if (!$tbl) { flash_set('種別が不正です。','error'); header('Location: trash.php'); exit; }
    $st = $pdo->prepare("SELECT * FROM $tbl WHERE id=? AND deleted_at IS NOT NULL");
    $st->execute([$id]); $row = $st->fetch();
    if (!$row) { flash_set('対象が見つかりません。','error'); }
    else {
        $owner = (int)($row['owner_id'] ?? $row['sender_id'] ?? 0);
        if (!$isAdmin && $owner !== $uid) {
            flash_set('権限がありません。','error');
        } elseif ($action === 'restore') {
            $pdo->prepare("UPDATE $tbl SET deleted_at=NULL WHERE id=?")->execute([$id]);
            // フォルダ復元時は配下も一緒に
            if ($type === 'folder') {
                $toRestore = [$id]; $i = 0;
                while ($i < count($toRestore)) {
                    $cur = $toRestore[$i++];
                    $cs = $pdo->prepare('SELECT id FROM folders WHERE parent_id=?');
                    $cs->execute([$cur]);
                    foreach ($cs->fetchAll() as $r) $toRestore[] = (int)$r['id'];
                }
                $in = implode(',', array_fill(0, count($toRestore), '?'));
                $pdo->prepare("UPDATE folders SET deleted_at=NULL WHERE id IN ($in)")->execute($toRestore);
                $pdo->prepare("UPDATE files   SET deleted_at=NULL WHERE folder_id IN ($in)")->execute($toRestore);
            } elseif ($type === 'post' && $row['parent_post_id'] === null) {
                $pdo->prepare('UPDATE posts SET deleted_at=NULL WHERE parent_post_id=?')->execute([$id]);
            }
            audit_log($pdo, $user, 'restore_'.$type, $type, $id, $row['name']??$row['subject']??null);
            flash_set('復元しました。','success');
        } elseif ($action === 'purge') {
            // 物理削除
            if ($type === 'file') {
                $path = storage_dir() . '/' . $row['stored_name'];
                if (file_exists($path)) @unlink($path);
                $pdo->prepare('DELETE FROM files WHERE id=?')->execute([$id]);
            } elseif ($type === 'folder') {
                // 配下のファイル実体も削除
                $toDelete = [$id]; $i = 0;
                while ($i < count($toDelete)) {
                    $cur = $toDelete[$i++];
                    $cs = $pdo->prepare('SELECT id FROM folders WHERE parent_id=?');
                    $cs->execute([$cur]);
                    foreach ($cs->fetchAll() as $r) $toDelete[] = (int)$r['id'];
                }
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $files = $pdo->prepare("SELECT stored_name FROM files WHERE folder_id IN ($in)");
                $files->execute($toDelete);
                foreach ($files->fetchAll() as $r) {
                    $path = storage_dir() . '/' . $r['stored_name'];
                    if (file_exists($path)) @unlink($path);
                }
                $pdo->prepare("DELETE FROM folders WHERE id=?")->execute([$id]); // CASCADE で配下も
            } else {
                $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
            }
            audit_log($pdo, $user, 'purge_'.$type, $type, $id, $row['name']??$row['subject']??null);
            flash_set('完全削除しました。','success');
        }
    }
    header('Location: trash.php'); exit;
}

// 一覧（自分の物。admin は全件）
$ownerCond = $isAdmin ? '' : ' AND owner_id = ?';
$senderCond = $isAdmin ? '' : ' AND sender_id = ?';
$p = $isAdmin ? [] : [$uid];

$folders = $pdo->prepare("SELECT id, name, deleted_at, owner_id FROM folders WHERE deleted_at IS NOT NULL $ownerCond ORDER BY deleted_at DESC");
$folders->execute($p); $folders = $folders->fetchAll();
$files   = $pdo->prepare("SELECT id, name, size, deleted_at, owner_id FROM files WHERE deleted_at IS NOT NULL $ownerCond ORDER BY deleted_at DESC");
$files->execute($p); $files = $files->fetchAll();
$posts   = $pdo->prepare("SELECT id, subject, parent_post_id, deleted_at, sender_id FROM posts WHERE deleted_at IS NOT NULL $senderCond ORDER BY deleted_at DESC");
$posts->execute($p); $posts = $posts->fetchAll();

render_header('ゴミ箱', $user);
?>
<div class="card">
  <h2>ゴミ箱</h2>
  <p class="muted"><?= $isAdmin ? '管理者: 全ユーザのゴミ箱を表示' : '自分が削除した項目のみ表示' ?></p>

  <h3>フォルダ（<?= count($folders) ?>）</h3>
  <table>
    <thead><tr><th>名前</th><th>削除日時</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$folders): ?><tr><td colspan="3" class="muted">なし</td></tr><?php endif; ?>
    <?php foreach ($folders as $f): ?>
      <tr><td><?= h($f['name']) ?></td><td class="muted"><?= h($f['deleted_at']) ?></td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="type" value="folder">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button>復元</button>
        </form>
        <form method="post" class="inline" onsubmit="return confirm('完全削除します。本当に？');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="purge">
          <input type="hidden" name="type" value="folder">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button class="btn-danger">完全削除</button>
        </form>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h3>ファイル（<?= count($files) ?>）</h3>
  <table>
    <thead><tr><th>名前</th><th>サイズ</th><th>削除日時</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$files): ?><tr><td colspan="4" class="muted">なし</td></tr><?php endif; ?>
    <?php foreach ($files as $f): ?>
      <tr><td><?= h($f['name']) ?></td><td><?= number_format((int)$f['size']) ?> B</td><td class="muted"><?= h($f['deleted_at']) ?></td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="type" value="file">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button>復元</button>
        </form>
        <form method="post" class="inline" onsubmit="return confirm('完全削除します。本当に？');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="purge">
          <input type="hidden" name="type" value="file">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button class="btn-danger">完全削除</button>
        </form>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h3>投稿（<?= count($posts) ?>）</h3>
  <table>
    <thead><tr><th>件名</th><th>種別</th><th>削除日時</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$posts): ?><tr><td colspan="4" class="muted">なし</td></tr><?php endif; ?>
    <?php foreach ($posts as $f): ?>
      <tr><td><?= h($f['subject']?:'(返信)') ?></td><td><?= $f['parent_post_id']===null?'スレッド':'返信' ?></td><td class="muted"><?= h($f['deleted_at']) ?></td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="type" value="post">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button>復元</button>
        </form>
        <form method="post" class="inline" onsubmit="return confirm('完全削除します。本当に？');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="purge">
          <input type="hidden" name="type" value="post">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button class="btn-danger">完全削除</button>
        </form>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
