<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];

$threadId = (int)($_GET['id'] ?? 0);
if ($threadId <= 0) { flash_set('スレッドIDが不正です。', 'error'); header('Location: board.php'); exit; }

$stmt = $pdo->prepare(
    'SELECT p.*, s.username AS sender_username, s.display_name AS sender_display,
            r.username AS recipient_username, r.display_name AS recipient_display
     FROM posts p
     LEFT JOIN users s ON s.id = p.sender_id
     LEFT JOIN users r ON r.id = p.recipient_id
     WHERE p.id = ? AND p.parent_post_id IS NULL AND p.deleted_at IS NULL'
);
$stmt->execute([$threadId]);
$thread = $stmt->fetch();
if (!$thread) { flash_set('スレッドが見つかりません。', 'error'); header('Location: board.php'); exit; }

// 可視性: 全員 OR primary OR additional recipient OR sender OR admin
$rs = $pdo->prepare('SELECT user_id FROM post_recipients WHERE post_id = ?');
$rs->execute([$threadId]);
$extraRcps = array_column($rs->fetchAll(), 'user_id');
$extraHasAll = in_array(null, $extraRcps, true) || in_array(0, array_map('intval', array_filter($extraRcps,fn($v)=>$v!==null)), true);
$extraHasMe  = in_array($uid, array_map('intval', array_filter($extraRcps,fn($v)=>$v!==null)), true);
$canSee = $thread['recipient_id'] === null
    || (int)$thread['recipient_id'] === $uid
    || (int)$thread['sender_id'] === $uid
    || $user['role'] === 'admin'
    || $extraHasAll || $extraHasMe;
if (!$canSee) { flash_set('このスレッドを閲覧する権限がありません。', 'error'); header('Location: board.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body === '') {
            flash_set('返信本文は必須です。','error');
        } else {
            // 添付（任意）
            $attachId = null;
            if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $orig = basename(str_replace('\\','/',$_FILES['attachment']['name']));
                if ($orig !== '' && strlen($orig) <= 255) {
                    $stored = bin2hex(random_bytes(16));
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], storage_dir().'/'.$stored)) {
                        $pdo->prepare('INSERT INTO files (name, folder_id, owner_id, size, mime_type, stored_name) VALUES (?,?,?,?,?,?)')
                            ->execute([$orig, null, $uid, (int)$_FILES['attachment']['size'], $_FILES['attachment']['type'] ?: null, $stored]);
                        $attachId = (int)$pdo->lastInsertId();
                    }
                }
            }
            $pdo->prepare('INSERT INTO posts (sender_id, recipient_id, subject, body, parent_post_id, attachment_file_id) VALUES (?,?,?,?,?,?)')
                ->execute([$uid, $thread['recipient_id'], '', $body, $thread['id'], $attachId]);
            $rid = (int)$pdo->lastInsertId();
            audit_log($pdo, $user, 'post_reply', 'post', $rid, $thread['subject']);
            flash_set('返信を投稿しました。','success');
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        $newSub = isset($_POST['subject']) ? trim($_POST['subject']) : null;
        $st = $pdo->prepare('SELECT * FROM posts WHERE id=? AND deleted_at IS NULL');
        $st->execute([$id]); $p = $st->fetch();
        if (!$p) { flash_set('対象が見つかりません。','error'); }
        elseif ($user['role'] !== 'admin' && (int)$p['sender_id'] !== $uid) { flash_set('編集権限がありません。','error'); }
        elseif ($body === '') { flash_set('本文は必須です。','error'); }
        else {
            if ($p['parent_post_id'] === null && $newSub !== null && $newSub !== '') {
                $pdo->prepare('UPDATE posts SET subject=?, body=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$newSub, $body, $id]);
            } else {
                $pdo->prepare('UPDATE posts SET body=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$body, $id]);
            }
            audit_log($pdo, $user, 'post_edit', 'post', $id, $p['subject']);
            flash_set('投稿を更新しました。','success');
        }
    } elseif ($action === 'delete_reply') {
        $rid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM posts WHERE id=? AND parent_post_id=? AND deleted_at IS NULL');
        $st->execute([$rid, $thread['id']]); $r = $st->fetch();
        if (!$r) { flash_set('返信が見つかりません。','error'); }
        elseif ($user['role'] !== 'admin' && (int)$r['sender_id'] !== $uid) { flash_set('削除権限がありません。','error'); }
        else {
            $pdo->prepare('UPDATE posts SET deleted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$rid]);
            audit_log($pdo, $user, 'post_trash', 'post', $rid, '(reply)');
            flash_set('返信をゴミ箱へ移動しました。','success');
        }
    }
    header('Location: thread.php?id=' . $threadId);
    exit;
}

// 既読マーク（自分が表示したスレッドおよび配下投稿全部）
$markIds = [(int)$thread['id']];
$ch = $pdo->prepare('SELECT id FROM posts WHERE parent_post_id=? AND deleted_at IS NULL');
$ch->execute([$thread['id']]);
foreach ($ch->fetchAll() as $r) $markIds[] = (int)$r['id'];
$ins = $pdo->prepare('INSERT OR IGNORE INTO post_reads (post_id, user_id) VALUES (?, ?)');
foreach ($markIds as $mid) $ins->execute([$mid, $uid]);

$rep = $pdo->prepare(
    'SELECT p.*, s.username AS sender_username, s.display_name AS sender_display
     FROM posts p LEFT JOIN users s ON s.id = p.sender_id
     WHERE p.parent_post_id = ? AND p.deleted_at IS NULL
     ORDER BY p.created_at ASC'
);
$rep->execute([$thread['id']]);
$replies = $rep->fetchAll();

// 添付の表示用
function fetch_file(PDO $pdo, ?int $id): ?array {
    if (!$id) return null;
    $s = $pdo->prepare('SELECT id, name, size FROM files WHERE id = ? AND deleted_at IS NULL');
    $s->execute([$id]); return $s->fetch() ?: null;
}

// 全宛先ラベル
$rs = $pdo->prepare('SELECT pr.user_id, u.username, u.display_name FROM post_recipients pr LEFT JOIN users u ON u.id=pr.user_id WHERE pr.post_id=?');
$rs->execute([$threadId]);
$allRcps = $rs->fetchAll();
$rcpLabels = [];
if ($thread['recipient_id'] === null && !$allRcps) $rcpLabels[] = '<em>全員</em>';
elseif ($thread['recipient_id'] !== null) $rcpLabels[] = h($thread['recipient_display'] ?: $thread['recipient_username']);
foreach ($allRcps as $ar) {
    if ($ar['user_id'] === null) { if (!in_array('<em>全員</em>',$rcpLabels,true)) $rcpLabels[]='<em>全員</em>'; }
    else { $lbl = h($ar['display_name'] ?: $ar['username']); if (!in_array($lbl,$rcpLabels,true)) $rcpLabels[] = $lbl; }
}

$threadAttach = fetch_file($pdo, $thread['attachment_file_id'] ? (int)$thread['attachment_file_id'] : null);

render_header('スレッド: ' . $thread['subject'], $user);
?>
<div class="card">
  <p class="breadcrumb"><a href="board.php">← 掲示板</a></p>
  <h2><?= h($thread['subject']) ?>
    <?php if ($thread['updated_at']): ?><span class="badge edited">編集済</span><?php endif; ?>
  </h2>
  <p class="muted">
    差出: <?= h($thread['sender_display'] ?: $thread['sender_username']) ?> /
    宛先: <?= implode(', ', $rcpLabels) ?> /
    日時: <?= h($thread['created_at']) ?>
    <?php if ($thread['updated_at']): ?> / 更新: <?= h($thread['updated_at']) ?><?php endif; ?>
  </p>
  <pre style="white-space:pre-wrap;font-family:inherit;background:#f9fafb;padding:10px;border-radius:4px;"><?= h($thread['body']) ?></pre>
  <?php if ($threadAttach): ?>
    📎 <a href="download.php?id=<?= (int)$threadAttach['id'] ?>"><?= h($threadAttach['name']) ?></a>
       <span class="muted">(<?= number_format((int)$threadAttach['size']) ?> B)</span>
  <?php endif; ?>
  <?php if ($user['role'] === 'admin' || (int)$thread['sender_id'] === $uid): ?>
    <details style="margin-top:10px;"><summary>編集</summary>
      <form method="post" style="margin-top:8px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <label>件名</label><input type="text" name="subject" required style="width:100%" value="<?= h($thread['subject']) ?>">
        <label>本文</label><textarea name="body" required><?= h($thread['body']) ?></textarea>
        <button type="submit" style="margin-top:8px;">更新</button>
      </form>
    </details>
    <form method="post" action="board.php" class="inline" style="margin-top:8px;" onsubmit="return confirm('スレッド全体を削除（ゴミ箱へ）。よろしいですか？');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
      <button class="btn-danger" type="submit">スレッド削除</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>返信（<?= count($replies) ?> 件）</h3>
  <?php if (!$replies): ?><p class="muted">まだ返信はありません。</p><?php endif; ?>
  <?php foreach ($replies as $r): $att = fetch_file($pdo, $r['attachment_file_id']?(int)$r['attachment_file_id']:null); ?>
    <div style="border-top:1px solid #e5e7eb;padding:10px 0;">
      <p class="muted" style="margin:0;">
        #<?= (int)$r['id'] ?> <?= h($r['sender_display'] ?: $r['sender_username']) ?> <?= h($r['created_at']) ?>
        <?php if ($r['updated_at']): ?><span class="badge edited">編集済</span><?php endif; ?>
        <?php if ($user['role'] === 'admin' || (int)$r['sender_id'] === $uid): ?>
          <span style="float:right;">
            <details style="display:inline-block;"><summary class="btn btn-secondary" style="cursor:pointer;">編集</summary>
              <form method="post" style="margin-top:8px;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <textarea name="body" required><?= h($r['body']) ?></textarea>
                <button type="submit" style="margin-top:6px;">更新</button>
              </form>
            </details>
            <form method="post" class="inline" onsubmit="return confirm('返信を削除（ゴミ箱へ）しますか？');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_reply">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn-danger" type="submit">削除</button>
            </form>
          </span>
        <?php endif; ?>
      </p>
      <pre style="white-space:pre-wrap;font-family:inherit;margin:6px 0 0;"><?= h($r['body']) ?></pre>
      <?php if ($att): ?>
        📎 <a href="download.php?id=<?= (int)$att['id'] ?>"><?= h($att['name']) ?></a>
           <span class="muted">(<?= number_format((int)$att['size']) ?> B)</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>返信を投稿</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="reply">
    <textarea name="body" required></textarea>
    <label>添付（任意）</label><input type="file" name="attachment">
    <div style="margin-top:10px;"><button type="submit">返信</button></div>
  </form>
</div>
<?php render_footer(); ?>
