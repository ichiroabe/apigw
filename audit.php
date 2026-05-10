<?php
require_once __DIR__ . '/includes/layout.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $actionType = $_POST['action_type'] ?? '';
    if ($actionType === 'clear_all') {
        $pdo->exec('DELETE FROM audit_log');
        audit_log($pdo, $me, 'audit_clear', null, null, null, 'Cleared all logs');
        flash_set('すべての監査ログを削除しました。', 'success');
        header('Location: audit.php');
        exit;
    }
}

$kw  = trim((string)($_GET['kw'] ?? ''));
$act = trim((string)($_GET['action'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = []; $params = [];
if ($kw !== '') { $where[] = '(username LIKE ? OR target_name LIKE ? OR meta LIKE ?)'; $like = '%'.$kw.'%'; $params[]=$like;$params[]=$like;$params[]=$like; }
if ($act !== '') { $where[] = 'action = ?'; $params[] = $act; }
$wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $st = $pdo->prepare('SELECT * FROM audit_log' . $wsql . ' ORDER BY id DESC');
    $st->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['日時', 'ユーザ', 'action', '対象', 'meta']);
    while ($r = $st->fetch()) {
        $targetStr = ($r['target_type']?:'-').' #'.($r['target_id']??'-').' '.($r['target_name']??'');
        fputcsv($out, [$r['created_at'], $r['username']??'-', $r['action'], $targetStr, $r['meta']??'']);
    }
    fclose($out);
    exit;
}

$cs = $pdo->prepare('SELECT COUNT(*) c FROM audit_log' . $wsql); $cs->execute($params);
$total = (int)$cs->fetch()['c'];
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$sql = 'SELECT * FROM audit_log' . $wsql . ' ORDER BY id DESC LIMIT :lim OFFSET :off';
$st = $pdo->prepare($sql);
foreach ($params as $i=>$v) $st->bindValue($i+1, $v);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$logs = $st->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

render_header('監査ログ', $me);
?>
<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h2 style="margin:0;">監査ログ</h2>
    <form method="post" onsubmit="return confirm('本当にすべての監査ログを削除しますか？この操作は取り消せません。');" style="margin:0;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action_type" value="clear_all">
      <button type="submit" class="btn-danger">全ログ削除</button>
    </form>
  </div>
  <hr style="margin: 16px 0; border: none; border-top: 1px solid var(--border);">
  <form method="get" style="margin-bottom:8px;">
    <div class="row" style="align-items:end;">
      <div><label>キーワード（ユーザ名/対象名/meta）</label><input type="text" name="kw" value="<?= h($kw) ?>" style="width:100%"></div>
      <div><label>アクション</label>
        <select name="action" style="width:100%"><option value="">すべて</option>
          <?php foreach ($actions as $a): ?><option value="<?= h($a) ?>" <?= $a===$act?'selected':'' ?>><?= h($a) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="flex:0 0 auto;">
        <button type="submit" class="btn">検索</button> 
        <a class="btn btn-secondary" href="audit.php">クリア</a>
        <button type="submit" name="export" value="csv" class="btn btn-secondary" style="margin-left: 8px;">CSVダウンロード</button>
      </div>
    </div>
  </form>
  <p class="muted">該当 <?= (int)$total ?> 件 / <?= (int)$page ?>/<?= (int)$pages ?> ページ</p>
  <table>
    <thead><tr><th>日時</th><th>ユーザ</th><th>action</th><th>対象</th><th>meta</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="5" class="muted">該当なし</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td class="muted"><?= h($l['created_at']) ?></td>
        <td><?= h($l['username']??'-') ?></td>
        <td><?= h($l['action']) ?></td>
        <td><?= h(($l['target_type']?:'-').' #'.($l['target_id']??'-').' '.($l['target_name']??'')) ?></td>
        <td class="muted" style="max-width:340px;overflow-wrap:anywhere;"><?= h($l['meta']??'') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($p=1;$p<=$pages;$p++):
        $qs = http_build_query(array_filter(['kw'=>$kw,'action'=>$act,'page'=>$p]));
        if ($p===$page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="audit.php?<?= h($qs) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
