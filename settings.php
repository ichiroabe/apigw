<?php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();
$pdo = db();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'profile') {
        $display_name = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $theme = valid_theme($_POST['theme'] ?? 'light');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('メールアドレスの形式が正しくありません。', 'error');
        } else {
            $pdo->prepare('UPDATE users SET display_name=?, email=?, theme=? WHERE id=?')
                ->execute([$display_name ?: null, $email ?: null, $theme, $uid]);
            audit_log($pdo, $user, 'settings_update', 'user', $uid, $user['username'], ['theme'=>$theme]);
            flash_set('設定を保存しました。', 'success');
        }
    }
    header('Location: settings.php'); exit;
}

// 最新値で再取得
$st = $pdo->prepare('SELECT * FROM users WHERE id=?');
$st->execute([$uid]);
$me = $st->fetch();

render_header('設定', $user);
?>
<div class="card" style="max-width:640px;">
  <h2>アカウント設定</h2>
  <p class="muted">ユーザ名: <code><?= h($me['username']) ?></code> / 権限: <span class="badge <?= $me['role']==='admin'?'admin':'' ?>"><?= h($me['role']) ?></span></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="profile">
    <label>表示名</label>
    <input type="text" name="display_name" value="<?= h($me['display_name'] ?? '') ?>" style="width:100%">
    <label>メールアドレス</label>
    <input type="email" name="email" value="<?= h($me['email'] ?? '') ?>" style="width:100%">

    <label style="margin-top:12px;">テーマ</label>
    <div>
      <?php foreach (AVAILABLE_THEMES as $key => $info):
        $checked = ($me['theme'] ?? 'light') === $key ? 'checked' : '';
        // プレビュー用カラー
        $swatch = ['light'=>'#f4f6f8','dark'=>'#0f172a','sepia'=>'#f4ecd8','contrast'=>'#000'][$key] ?? '#ccc';
        $accent = ['light'=>'#1f2937','dark'=>'#fbbf24','sepia'=>'#5b4636','contrast'=>'#ff0'][$key] ?? '#888';
      ?>
        <label style="display:block;margin:6px 0;font-weight:normal;cursor:pointer;">
          <input type="radio" name="theme" value="<?= h($key) ?>" <?= $checked ?>>
          <span class="theme-swatch" style="background:<?= $swatch ?>;border:1px solid <?= $accent ?>;"></span>
          <strong><?= h($info['label']) ?></strong>
          <span class="muted">— <?= h($info['desc']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:14px;">
      <button type="submit">保存</button>
      <a class="btn btn-secondary" href="password.php">パスワード変更</a>
    </div>
  </form>
</div>

<div class="card" style="max-width:640px;">
  <h3>テーマプレビュー</h3>
  <p class="muted">現在のテーマで以下が表示されています。保存後に他のテーマへ切り替わります。</p>
  <div class="row">
    <button>ボタン</button>
    <button class="btn-secondary">セカンダリ</button>
    <button class="btn-warn">警告</button>
    <button class="btn-danger">削除</button>
  </div>
  <div style="margin-top:12px;">
    <span class="badge">ラベル</span>
    <span class="badge admin">admin</span>
    <span class="badge shared">🌐 共有(edit)</span>
    <span class="badge shared-view">🌐 共有(view)</span>
    <span class="badge unread">未読</span>
    <span class="badge edited">編集済</span>
    <span class="badge inactive">無効</span>
  </div>
  <pre style="white-space:pre-wrap;padding:10px;border-radius:4px;">サンプルテキスト
日本語と English の混在表示。リンク: <a href="#">これはリンク</a></pre>
</div>
<?php render_footer(); ?>
