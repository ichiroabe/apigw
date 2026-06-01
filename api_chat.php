<?php
// api_chat.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = current_user();
if (!$user) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
session_write_close(); // セッションのロックを解除して並行処理の詰まりを防ぐ

$pdo = db();
$uid = (int)$user['id'];
$threadId = (int)($_GET['thread_id'] ?? 0);
$lastId = (int)($_GET['last_id'] ?? 0);

if ($threadId <= 0) {
    echo json_encode(['error' => 'invalid_thread']);
    exit;
}

// 権限チェック (thread.phpと同じ)
$st = $pdo->prepare(
    'SELECT p.* FROM posts p
     LEFT JOIN post_recipients pr ON p.id = pr.post_id
     WHERE p.id = ? AND p.parent_post_id IS NULL AND p.deleted_at IS NULL
       AND (
           p.sender_id = :uid
           OR p.recipient_id = :uid
           OR p.recipient_id IS NULL
           OR pr.user_id = :uid
           OR pr.user_id IS NULL
           OR :role = \'admin\'
       )'
);
$st->bindValue(':uid', $uid, PDO::PARAM_INT);
$st->bindValue(':role', $user['role'], PDO::PARAM_STR);
$st->bindValue(1, $threadId, PDO::PARAM_INT);
$st->execute();
$thread = $st->fetch();

if (!$thread) {
    echo json_encode(['error' => 'not_found_or_forbidden']);
    exit;
}

// 新着返信を取得
$rep = $pdo->prepare(
    'SELECT p.*, s.username AS sender_username, s.display_name AS sender_display
     FROM posts p LEFT JOIN users s ON s.id = p.sender_id
     WHERE p.parent_post_id = ? AND p.id > ? AND p.deleted_at IS NULL
     ORDER BY p.created_at ASC'
);
$rep->execute([$threadId, $lastId]);
$replies = $rep->fetchAll();

if (!$replies) {
    echo json_encode(['html' => '', 'last_id' => $lastId]);
    exit;
}

function fetch_file(PDO $pdo, ?int $id): ?array {
    if (!$id) return null;
    $s = $pdo->prepare('SELECT id, name, size FROM files WHERE id = ? AND deleted_at IS NULL');
    $s->execute([$id]); return $s->fetch() ?: null;
}

$html = '';
$maxId = $lastId;
$markIds = [];

ob_start();
foreach ($replies as $r) {
    $markIds[] = (int)$r['id'];
    $maxId = max($maxId, (int)$r['id']);
    
    $att = fetch_file($pdo, $r['attachment_file_id'] ? (int)$r['attachment_file_id'] : null);
    $isMe = ((int)$r['sender_id'] === $uid);
    $align = $isMe ? 'flex-end' : 'flex-start';
    $bubbleBg = $isMe ? 'var(--btn-bg)' : 'var(--card-bg)';
    $bubbleColor = $isMe ? 'var(--btn-fg)' : 'var(--fg)';
    ?>
      <div style="display:flex; width:100%; justify-content:<?= $align ?>; animation: fadeIn 0.3s ease-out;">
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
    <?php
}
$html = ob_get_clean();

if ($markIds) {
    $ins = $pdo->prepare('INSERT OR IGNORE INTO post_reads (post_id, user_id, read_at) VALUES (?, ?, ?)');
    foreach ($markIds as $mid) {
        $ins->execute([$mid, $uid, now_jst()]);
    }
}

header('Content-Type: application/json');
echo json_encode([
    'html' => $html,
    'last_id' => $maxId
]);
