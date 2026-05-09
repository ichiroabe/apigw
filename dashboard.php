<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];

$myFiles   = (int)$pdo->query('SELECT COUNT(*) c FROM files   WHERE deleted_at IS NULL AND owner_id = ' . $uid)->fetch()['c'];
$myFolders = (int)$pdo->query('SELECT COUNT(*) c FROM folders WHERE deleted_at IS NULL AND owner_id = ' . $uid)->fetch()['c'];

$stmt = $pdo->prepare(
    "SELECT p.*, s.username AS sender_username, s.display_name AS sender_display,
            r.username AS recipient_username, r.display_name AS recipient_display
     FROM posts p
     LEFT JOIN users s ON s.id = p.sender_id
     LEFT JOIN users r ON r.id = p.recipient_id
     WHERE p.deleted_at IS NULL AND p.parent_post_id IS NULL
       AND (p.recipient_id IS NULL OR p.recipient_id = ? OR p.sender_id = ?
            OR EXISTS (SELECT 1 FROM post_recipients pr WHERE pr.post_id=p.id AND (pr.user_id IS NULL OR pr.user_id=?)))
     ORDER BY p.created_at DESC LIMIT 5");
$stmt->execute([$uid, $uid, $uid]);
$posts = $stmt->fetchAll();

$unread = unread_count($pdo, $uid);

render_header('ホーム', $user);
?>
<div class="card">
  <h2>ようこそ <?= h(display_name($user)) ?> さん</h2>
  <p>権限: <span class="badge <?= $user['role']==='admin' ? 'admin':'' ?>"><?= h($user['role']) ?></span>
     <?php if ($unread): ?><span class="badge unread">未読 <?= (int)$unread ?></span><?php endif; ?></p>
  <p class="muted">
    <?php if ($user['role'] === 'admin'): ?>管理者として全ての操作が可能です。<?php else: ?>自分が作成したフォルダ・アップロードしたファイル + 共有/ACL されたフォルダのみ操作できます。<?php endif; ?>
  </p>
</div>

<div class="row">
  <div class="card">
    <h3>マイファイル</h3>
    <p>フォルダ: <?= $myFolders ?> 件 / ファイル: <?= $myFiles ?> 件</p>
    <a class="btn" href="files.php">ファイル管理へ</a>
  </div>
  <div class="card">
    <h3>掲示板（最新5件）</h3>
    <?php if (!$posts): ?><p class="muted">投稿はまだありません。</p>
    <?php else: ?>
      <ul style="padding-left:18px;">
        <?php foreach ($posts as $p): ?>
          <li>
            <a href="thread.php?id=<?= (int)$p['id'] ?>"><strong><?= h($p['subject']) ?></strong></a>
            <span class="muted">
              <?= h($p['sender_display'] ?: $p['sender_username']) ?> →
              <?= $p['recipient_id'] === null ? '<em>全員</em>' : h($p['recipient_display'] ?: $p['recipient_username']) ?>
              （<?= h($p['created_at']) ?>）
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <a class="btn" href="board.php">掲示板へ</a>
  </div>
</div>
<?php render_footer(); ?>
