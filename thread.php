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
  <pre style="white-space:pre-wrap;font-family:inherit;background:var(--pre-bg);padding:10px;border-radius:4px;"><?= h($thread['body']) ?></pre>
  <?php if ($threadAttach): ?>
    📎 <a href="download.php?id=<?= (int)$threadAttach['id'] ?>"><?= h($threadAttach['name']) ?></a>
       <span class="muted">(<?= number_format((int)$threadAttach['size']) ?> B)</span>
  <?php endif; ?>
  <?php if ($user['role'] === 'admin' || (int)$thread['sender_id'] === $uid): ?>
    <div style="text-align:right; margin-top:10px;">
      <details style="display:inline-block; text-align:left; position:relative;"><summary class="btn btn-secondary" style="cursor:pointer;">編集</summary>
        <form method="post" style="margin-top:8px; width:100%; min-width:300px; padding:12px; border:1px solid var(--border); background:var(--bg); border-radius:6px; box-shadow:0 4px 6px rgba(0,0,0,0.1); position:absolute; right:0; z-index:10;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
          <label>件名</label><input type="text" name="subject" required style="width:100%" value="<?= h($thread['subject']) ?>">
          <label>本文</label><textarea name="body" required><?= h($thread['body']) ?></textarea>
          <div style="text-align:right; margin-top:8px;"><button type="submit">更新</button></div>
        </form>
      </details>
      <form method="post" action="board.php" class="inline" style="margin-left:4px;" onsubmit="return confirm('スレッド全体を削除（ゴミ箱へ）。よろしいですか？');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
        <button class="btn-danger" type="submit">スレッド削除</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="background:var(--pre-bg);">
  <h3 style="margin-top:0;">返信（<?= count($replies) ?> 件）</h3>
  <?php if (!$replies): ?><p class="muted">まだ返信はありません。</p><?php endif; ?>
  <div id="chat-container" style="display:flex; flex-direction:column; gap:16px;">
    <?php foreach ($replies as $r): 
      $att = fetch_file($pdo, $r['attachment_file_id']?(int)$r['attachment_file_id']:null); 
      $isMe = ((int)$r['sender_id'] === $uid);
      $align = $isMe ? 'flex-end' : 'flex-start';
      $bubbleBg = $isMe ? 'var(--btn-bg)' : 'var(--card-bg)';
      $bubbleColor = $isMe ? 'var(--btn-fg)' : 'var(--fg)';
    ?>
      <div style="display:flex; width:100%; justify-content:<?= $align ?>;">
        <div style="display:flex; flex-direction:column; align-items:<?= $align ?>; max-width:85%;">
          <?php if (!$isMe): ?>
            <div class="muted" style="font-size:12px; margin-bottom:4px; margin-left:4px;">
              <?= h($r['sender_display'] ?: $r['sender_username']) ?>
            </div>
          <?php endif; ?>
          
          <div style="background:<?= $bubbleBg ?>; color:<?= $bubbleColor ?>; padding:10px 14px; border-radius:16px; box-shadow:0 1px 2px rgba(0,0,0,0.1); border:1px solid var(--border);">
            <pre style="white-space:pre-wrap;font-family:inherit;margin:0; background:transparent; padding:0; color:inherit; border:none;"><?= h($r['body']) ?></pre>
            <?php if ($att): ?>
              <div style="margin-top:8px; font-size:12px;">📎 <a href="download.php?id=<?= (int)$att['id'] ?>" style="color:inherit;text-decoration:underline;"><?= h($att['name']) ?></a></div>
            <?php endif; ?>
          </div>
          
          <div class="muted" style="font-size:11px; margin-top:4px; display:flex; gap:8px; align-items:center;">
            <?= h(date('m/d H:i', strtotime($r['created_at']))) ?>
            <?php if ($r['updated_at']): ?><span class="badge edited" style="font-size:9px; padding:1px 4px;">編集済</span><?php endif; ?>
            <?php if ($user['role'] === 'admin' || $isMe): ?>
              <details style="position:relative;"><summary style="cursor:pointer; color:var(--link);">編集</summary>
                <form method="post" style="margin-top:8px; width:100%; min-width:260px; padding:12px; border:1px solid var(--border); background:var(--bg); border-radius:6px; box-shadow:0 4px 6px rgba(0,0,0,0.1); position:absolute; <?= $isMe ? 'right:0;' : 'left:0;' ?> z-index:10; text-align:left; color:var(--fg);">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <textarea name="body" required style="width:100%;"><?= h($r['body']) ?></textarea>
                  <div style="text-align:right; margin-top:6px;"><button type="submit" class="btn">更新</button></div>
                </form>
              </details>
              <form method="post" class="inline" onsubmit="return confirm('返信を削除（ゴミ箱へ）しますか？');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_reply">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" style="background:none; border:none; color:var(--btn-danger-bg); cursor:pointer; padding:0; font-size:11px; text-decoration:underline;">削除</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h3>返信を投稿</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="reply">
    <textarea name="body" required></textarea>
    <div style="margin-top:10px; display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
      <button type="submit">返信</button>
      <div class="muted" style="font-size:12px;">
        📎 添付(任意): <input type="file" name="attachment" style="font-size:12px; padding:0; border:none; background:none; color:inherit;">
      </div>
    </div>
  </form>
</div>
<?php
$maxReplyId = 0;
foreach ($replies as $r) {
    if ((int)$r['id'] > $maxReplyId) $maxReplyId = (int)$r['id'];
}
?>
<script>
let lastChatId = <?= $maxReplyId ?>;
const threadId = <?= (int)$thread['id'] ?>;
const chatContainer = document.getElementById('chat-container');

// Only poll if chat container exists
if (chatContainer) {
    setInterval(() => {
        fetch(`api_chat.php?thread_id=${threadId}&last_id=${lastChatId}`)
            .then(res => res.json())
            .then(data => {
                if (data.html && data.html.trim() !== '') {
                    // Append new HTML
                    chatContainer.insertAdjacentHTML('beforeend', data.html);
                    lastChatId = data.last_id;
                    
                    // Scroll to bottom of page smoothly
                    window.scrollTo({
                        top: document.body.scrollHeight,
                        behavior: 'smooth'
                    });
                    
                    // Optional: play notification sound
                    // new Audio('notification.mp3').play().catch(e => {});
                }
            })
            .catch(err => console.error('Polling error:', err));
    }, 3000);
}
</script>
<?php render_footer(); ?>
