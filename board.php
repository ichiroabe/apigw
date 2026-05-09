<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];

/**
 * 投稿の追加宛先 user_id を保存（NULL = 全員、整数配列 = 各ユーザ）
 */
function save_post_recipients(PDO $pdo, int $postId, ?int $primary, array $extras): void {
    $pdo->prepare('DELETE FROM post_recipients WHERE post_id = ?')->execute([$postId]);
    $set = [];
    if ($primary === null) $set[null] = true; else $set[$primary] = true;
    foreach ($extras as $eid) {
        if ($eid === null) $set[null] = true; else $set[(int)$eid] = true;
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO post_recipients (post_id, user_id) VALUES (?, ?)');
    foreach ($set as $rid => $_) {
        $ins->execute([$postId, $rid === '' ? null : ($rid === null ? null : $rid)]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $rcps    = $_POST['recipients'] ?? []; // 多選択
        if (!is_array($rcps)) $rcps = [$rcps];
        $primaryAll = in_array('all', $rcps, true);
        $userIds = array_filter(array_map('intval', $rcps), fn($v)=>$v>0);

        if ($subject === '' || $body === '') {
            flash_set('件名と本文は必須です。', 'error');
        } elseif (!$primaryAll && !$userIds) {
            flash_set('宛先を 1 つ以上選択してください（全員 または ユーザ）。', 'error');
        } else {
            // primary recipient (互換のため): all=>NULL, single user=>user_id, multiple=>NULL（複数行 post_recipients に格納）
            $primary = ($primaryAll || count($userIds) !== 1) ? null : $userIds[0];
            // 添付ファイル
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
            $pdo->prepare('INSERT INTO posts (sender_id, recipient_id, subject, body, parent_post_id, attachment_file_id) VALUES (?,?,?,?,NULL,?)')
                ->execute([$uid, $primary, $subject, $body, $attachId]);
            $pid = (int)$pdo->lastInsertId();
            save_post_recipients($pdo, $pid, $primaryAll ? null : null, $primaryAll ? array_merge([null], $userIds) : $userIds);
            audit_log($pdo, $user, 'post_create', 'post', $pid, $subject, ['recipients_all'=>$primaryAll, 'recipient_user_ids'=>$userIds]);
            flash_set('スレッドを作成しました。', 'success');
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $st = $pdo->prepare('SELECT * FROM posts WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]); $p = $st->fetch();
        if (!$p) { flash_set('投稿が見つかりません。','error'); }
        elseif ($user['role'] !== 'admin' && (int)$p['sender_id'] !== $uid) { flash_set('編集権限がありません。','error'); }
        elseif ($subject === '' || $body === '') { flash_set('件名と本文は必須です。','error'); }
        else {
            $pdo->prepare('UPDATE posts SET subject=?, body=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$subject, $body, $id]);
            audit_log($pdo, $user, 'post_edit', 'post', $id, $subject);
            flash_set('投稿を更新しました。','success');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM posts WHERE id=? AND deleted_at IS NULL');
        $st->execute([$id]); $p = $st->fetch();
        if (!$p) { flash_set('投稿が見つかりません。','error'); }
        elseif ($user['role'] !== 'admin' && (int)$p['sender_id'] !== $uid) { flash_set('削除権限がありません。','error'); }
        else {
            // 親なら子も論理削除
            if ($p['parent_post_id'] === null) {
                $pdo->prepare('UPDATE posts SET deleted_at=CURRENT_TIMESTAMP WHERE id=? OR parent_post_id=?')->execute([$id,$id]);
            } else {
                $pdo->prepare('UPDATE posts SET deleted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
            }
            audit_log($pdo, $user, 'post_trash', 'post', $id, $p['subject']);
            flash_set('投稿をゴミ箱へ移動しました。','success');
        }
    }
    header('Location: board.php' . (!empty($_POST['back_qs']) ? '?'.$_POST['back_qs'] : ''));
    exit;
}

// 検索パラメータ
$kw      = trim((string)($_GET['kw'] ?? ''));
$rcpAll  = !empty($_GET['r_all']);
$rcpMe   = !empty($_GET['r_me']);
$rcpSent = !empty($_GET['r_sent']);
$mode    = (($_GET['mode'] ?? 'or') === 'and') ? 'and' : 'or';
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 10;

// 可視性（複数宛先対応）
$where  = ['p.parent_post_id IS NULL', 'p.deleted_at IS NULL'];
$params = [];
$where[] = '(p.recipient_id IS NULL OR p.recipient_id = ? OR p.sender_id = ? OR EXISTS (SELECT 1 FROM post_recipients pr WHERE pr.post_id = p.id AND (pr.user_id IS NULL OR pr.user_id = ?)))';
$params[] = $uid; $params[] = $uid; $params[] = $uid;

if ($kw !== '') {
    $like = '%' . $kw . '%';
    $where[] = '(p.subject LIKE ? OR p.body LIKE ? OR EXISTS (SELECT 1 FROM posts c WHERE c.parent_post_id = p.id AND c.deleted_at IS NULL AND c.body LIKE ?))';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$rcpConds = []; $rcpParams = [];
if ($rcpAll)  { $rcpConds[] = '(p.recipient_id IS NULL AND NOT EXISTS (SELECT 1 FROM post_recipients prx WHERE prx.post_id = p.id AND prx.user_id IS NOT NULL)) OR EXISTS (SELECT 1 FROM post_recipients pra WHERE pra.post_id = p.id AND pra.user_id IS NULL)'; }
if ($rcpMe)   { $rcpConds[] = '(p.recipient_id = ? OR EXISTS (SELECT 1 FROM post_recipients prm WHERE prm.post_id = p.id AND prm.user_id = ?))'; $rcpParams[] = $uid; $rcpParams[] = $uid; }
if ($rcpSent) { $rcpConds[] = 'p.sender_id = ?'; $rcpParams[] = $uid; }
if ($rcpConds) {
    $glue = $mode === 'and' ? ' AND ' : ' OR ';
    $where[] = '(' . implode($glue, array_map(fn($c)=>"($c)", $rcpConds)) . ')';
    foreach ($rcpParams as $p2) $params[] = $p2;
}

$countSql = 'SELECT COUNT(*) c FROM posts p WHERE ' . implode(' AND ', $where);
$cs = $pdo->prepare($countSql); $cs->execute($params); $total = (int)$cs->fetch()['c'];
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$sql = 'SELECT p.*,
               s.username AS sender_username, s.display_name AS sender_display,
               r.username AS recipient_username, r.display_name AS recipient_display,
               (SELECT COUNT(*) FROM posts c WHERE c.parent_post_id = p.id AND c.deleted_at IS NULL) AS reply_count,
               (SELECT MAX(created_at) FROM posts c2 WHERE c2.parent_post_id = p.id AND c2.deleted_at IS NULL) AS last_reply_at,
               -- 既読: 自分が読んだ（または自分が投稿した）
               CASE WHEN p.sender_id = :u OR EXISTS (SELECT 1 FROM post_reads rd WHERE rd.post_id = p.id AND rd.user_id = :u) THEN 1 ELSE 0 END AS is_read
        FROM posts p
        LEFT JOIN users s ON s.id = p.sender_id
        LEFT JOIN users r ON r.id = p.recipient_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY COALESCE((SELECT MAX(created_at) FROM posts c3 WHERE c3.parent_post_id = p.id AND c3.deleted_at IS NULL), p.created_at) DESC
        LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
$stmt->bindValue(':u', $uid, PDO::PARAM_INT);
$stmt->bindValue(':limit', $per, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$threads = $stmt->fetchAll();

$users = $pdo->query('SELECT id, username, display_name FROM users WHERE is_active=1 ORDER BY username')->fetchAll();

// 検索条件付き back URL（編集等のリダイレクト用）
$backQs = http_build_query(array_filter([
    'kw'=>$kw, 'r_all'=>$rcpAll?1:null, 'r_me'=>$rcpMe?1:null, 'r_sent'=>$rcpSent?1:null,
    'mode'=>$mode, 'page'=>$page>1?$page:null
]));

render_header('掲示板', $user);
?>
<div class="card">
  <h2>掲示板（スレッド一覧）</h2>
  <form method="get" style="margin-bottom:8px;">
    <div class="row" style="align-items:end;">
      <div>
        <label>キーワード検索（件名・本文・返信）</label>
        <input type="text" name="kw" value="<?= h($kw) ?>" style="width:100%">
      </div>
      <div style="flex:0 0 380px;">
        <label>宛先絞り込み</label>
        <label style="display:inline;font-weight:normal;"><input type="checkbox" name="r_all"  value="1" <?= $rcpAll ?'checked':''?>> 全員宛</label>
        <label style="display:inline;font-weight:normal;margin-left:8px;"><input type="checkbox" name="r_me"   value="1" <?= $rcpMe  ?'checked':''?>> 自分宛</label>
        <label style="display:inline;font-weight:normal;margin-left:8px;"><input type="checkbox" name="r_sent" value="1" <?= $rcpSent?'checked':''?>> 自分の送信</label>
        <div style="margin-top:6px;">
          <label style="display:inline;font-weight:normal;"><input type="radio" name="mode" value="or"  <?= $mode==='or' ?'checked':''?>> OR</label>
          <label style="display:inline;font-weight:normal;margin-left:8px;"><input type="radio" name="mode" value="and" <?= $mode==='and'?'checked':''?>> AND</label>
          <span class="muted" style="margin-left:8px;">※AND で「自分宛 + 自分送信」は常に 0 件</span>
        </div>
      </div>
      <div style="flex:0 0 auto;">
        <button type="submit">検索</button>
        <a class="btn btn-secondary" href="board.php">クリア</a>
      </div>
    </div>
  </form>
  <p class="muted">該当 <?= (int)$total ?> 件 / <?= (int)$page ?>/<?= (int)$pages ?> ページ</p>
  <table>
    <thead><tr><th>件名</th><th>差出</th><th>宛先</th><th>返信</th><th>最終更新</th><th>操作</th></tr></thead>
    <tbody>
    <?php if (!$threads): ?>
      <tr><td colspan="6" class="muted">該当するスレッドはありません。</td></tr>
    <?php endif; ?>
    <?php foreach ($threads as $t):
      // 全宛先表示（recipient_id + post_recipients）
      $rs = $pdo->prepare('SELECT pr.user_id, u.username, u.display_name FROM post_recipients pr LEFT JOIN users u ON u.id=pr.user_id WHERE pr.post_id=?');
      $rs->execute([$t['id']]);
      $allRcps = $rs->fetchAll();
      $rcpLabels = [];
      // primary
      if ($t['recipient_id'] === null) {
        if (!$allRcps) $rcpLabels[] = '<em>全員</em>';
      } else {
        $rcpLabels[] = h($t['recipient_display'] ?: $t['recipient_username']);
      }
      foreach ($allRcps as $ar) {
        if ($ar['user_id'] === null) { if (!in_array('<em>全員</em>',$rcpLabels,true)) $rcpLabels[] = '<em>全員</em>'; }
        else { $lbl = h($ar['display_name'] ?: $ar['username']); if (!in_array($lbl,$rcpLabels,true)) $rcpLabels[] = $lbl; }
      }
      $last = $t['last_reply_at'] ?: $t['created_at'];
      $isUnread = !(int)$t['is_read'];
    ?>
      <tr>
        <td>
          <a href="thread.php?id=<?= (int)$t['id'] ?>"><strong><?= h($t['subject']) ?></strong></a>
          <?php if ($isUnread): ?><span class="badge unread">未読</span><?php endif; ?>
          <?php if ($t['attachment_file_id']): ?>📎<?php endif; ?>
          <?php if ($t['updated_at']): ?><span class="badge edited">編集済</span><?php endif; ?>
        </td>
        <td><?= h($t['sender_display'] ?: $t['sender_username']) ?></td>
        <td><?= implode(', ', $rcpLabels) ?></td>
        <td><?= (int)$t['reply_count'] ?></td>
        <td class="muted"><?= h($last) ?></td>
        <td>
          <?php if ($user['role'] === 'admin' || (int)$t['sender_id'] === $uid): ?>
            <form method="post" class="inline" onsubmit="return confirm('スレッドと配下の返信をゴミ箱へ移動します。よろしいですか？');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="back_qs" value="<?= h($backQs) ?>">
              <button class="btn-danger" type="submit">削除</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($p = 1; $p <= $pages; $p++):
        $qs = $backQs; parse_str($qs, $arr); $arr['page'] = $p; $u = 'board.php?'.http_build_query($arr);
        if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= h($u) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>新規スレッド作成</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label>宛先（複数選択可、Ctrl/Cmd クリック）</label>
    <select name="recipients[]" multiple size="4" style="width:100%">
      <option value="all">全員</option>
      <?php foreach ($users as $u): if ((int)$u['id'] === $uid) continue; ?>
        <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name'] ?: $u['username']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>件名</label><input type="text" name="subject" required style="width:100%">
    <label>本文</label><textarea name="body" required></textarea>
    <label>添付ファイル（任意）</label><input type="file" name="attachment">
    <div style="margin-top:12px;"><button type="submit">投稿</button></div>
  </form>
</div>
<?php render_footer(); ?>
