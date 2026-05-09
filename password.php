<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        flash_set('現在のパスワードが正しくありません。', 'error');
    } elseif (!password_is_strong($new)) {
        flash_set('新しいパスワードは 8 文字以上で入力してください。', 'error');
    } elseif ($new !== $confirm) {
        flash_set('新しいパスワードの確認が一致しません。', 'error');
    } elseif ($new === $current) {
        flash_set('現在と同じパスワードです。別のものを設定してください。', 'error');
    } else {
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        // セキュリティ: 重要操作後はセッション再生成 + CSRF ローテーション
        session_regenerate_id(true);
        csrf_rotate();
        audit_log($pdo, $user, 'password_change');
        flash_set('パスワードを変更しました。', 'success');
    }
    header('Location: password.php');
    exit;
}

render_header('パスワード変更', $user);
?>
<div class="card" style="max-width:480px;">
  <h2>パスワード変更</h2>
  <p class="muted">ログイン中ユーザ: <?= h(display_name($user)) ?></p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>現在のパスワード</label>
    <input type="password" name="current" required style="width:100%" autofocus>
    <label>新しいパスワード（8 文字以上）</label>
    <input type="password" name="new" required minlength="8" style="width:100%">
    <label>新しいパスワード（確認）</label>
    <input type="password" name="confirm" required minlength="8" style="width:100%">
    <div style="margin-top:14px;"><button type="submit">変更</button></div>
  </form>
</div>
<?php render_footer(); ?>
