<?php
require_once __DIR__ . '/includes/layout.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') { flash_set('グループ名は必須です。','error'); }
        else {
            try {
                $pdo->prepare('INSERT INTO groups (name, description, created_at) VALUES (?,?,?)')->execute([$name,$desc?:null,now_jst()]);
                $gid = (int)$pdo->lastInsertId();
                audit_log($pdo,$me,'group_create','group',$gid,$name);
                flash_set('グループを作成しました。','success');
            } catch (PDOException $e) {
                flash_set('同名グループが存在します。','error');
            }
        }
    } elseif ($action === 'delete_group') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT name FROM groups WHERE id=?'); $st->execute([$id]); $g = $st->fetch();
        $pdo->prepare('DELETE FROM groups WHERE id=?')->execute([$id]);
        if ($g) audit_log($pdo,$me,'group_delete','group',$id,$g['name']);
        flash_set('グループを削除しました。','success');
    } elseif ($action === 'add_member') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('INSERT OR IGNORE INTO user_groups (group_id,user_id) VALUES (?,?)')->execute([$gid,$uid]);
        audit_log($pdo,$me,'group_add_member','group',$gid,null,['user_id'=>$uid]);
        flash_set('メンバーを追加しました。','success');
    } elseif ($action === 'remove_member') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM user_groups WHERE group_id=? AND user_id=?')->execute([$gid,$uid]);
        audit_log($pdo,$me,'group_remove_member','group',$gid,null,['user_id'=>$uid]);
        flash_set('メンバーを削除しました。','success');
    }
    header('Location: groups.php'); exit;
}

$groups = $pdo->query('SELECT * FROM groups ORDER BY name')->fetchAll();
$users  = $pdo->query('SELECT id, username, display_name FROM users WHERE is_active=1 ORDER BY username')->fetchAll();

render_header('グループ管理', $me);
?>
<div class="card">
  <h2>グループ管理</h2>
  <p class="muted">グループは「個別アクセス権」と組み合わせて、フォルダの限定共有（特定のグループのみ閲覧/編集）に使えます。</p>
  <table>
    <thead><tr><th>ID</th><th>名前</th><th>説明</th><th>メンバー</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$groups): ?><tr><td colspan="5" class="muted">グループがありません。</td></tr><?php endif; ?>
    <?php foreach ($groups as $g):
      $m = $pdo->prepare('SELECT u.id, u.username, u.display_name FROM user_groups ug JOIN users u ON u.id=ug.user_id WHERE ug.group_id=? ORDER BY u.username');
      $m->execute([$g['id']]); $members = $m->fetchAll();
    ?>
      <tr>
        <td><?= (int)$g['id'] ?></td>
        <td><?= h($g['name']) ?></td>
        <td class="muted"><?= h($g['description']??'') ?></td>
        <td>
          <?php foreach ($members as $u): ?>
            <span class="badge"><?= h($u['display_name']?:$u['username']) ?>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="remove_member">
                <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button title="削除" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:0 0 0 4px;">×</button>
              </form>
            </span>
          <?php endforeach; ?>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_member">
            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
            <select name="user_id"><option value="">＋追加</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name']?:$u['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <button>追加</button>
          </form>
        </td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm('グループを削除しますか？');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <button class="btn-danger">削除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="card">
  <h3>新規グループ作成</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_group">
    <div class="row">
      <div><label>名前</label><input type="text" name="name" required style="width:100%"></div>
      <div><label>説明</label><input type="text" name="description" style="width:100%"></div>
    </div>
    <div style="margin-top:10px;"><button>作成</button></div>
  </form>
</div>
<?php render_footer(); ?>
