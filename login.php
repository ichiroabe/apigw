<?php
require_once __DIR__ . '/includes/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && login_locked($pdo, $username)) {
        record_login_attempt($pdo, $username, false);
        audit_log($pdo, ['id'=>null,'username'=>$username], 'login_locked');
        flash_set('連続して失敗したため、しばらくロックされています。15 分後に再試行してください。', 'error');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        $ok = $u && (int)$u['is_active'] === 1 && password_verify($password, $u['password_hash']);
        record_login_attempt($pdo, $username, $ok);
        if ($ok) {
            start_session();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            csrf_rotate();
            audit_log($pdo, $u, 'login_success');
            header('Location: dashboard.php'); exit;
        }
        if ($u && (int)$u['is_active'] === 0) {
            flash_set('このユーザは無効化されています。管理者にお問い合わせください。', 'error');
        } else {
            flash_set('ユーザ名またはパスワードが正しくありません。', 'error');
        }
        audit_log($pdo, ['id'=>$u['id']??null,'username'=>$username], 'login_failed');
    }
}

render_header('ログイン', null);
?>
<div class="card" style="max-width:400px;margin:60px auto;">
  <h2>ログイン</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>ユーザ名</label>
    <input type="text" name="username" required autofocus style="width:100%">
    <label>パスワード</label>
    <input type="password" name="password" required style="width:100%">
    <div style="margin-top:16px;">
      <button type="submit">ログイン</button>
    </div>
  </form>
  <p class="muted" style="margin-top:16px;">
    初期管理者: <code>admin</code> / <code>admin</code>（必ず変更してください）
  </p>
</div>
<?php render_footer(); ?>
