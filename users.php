<?php
require_once __DIR__ . '/includes/layout.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username     = trim($_POST['username'] ?? '');
        $password     = $_POST['password'] ?? '';
        $display_name = trim($_POST['display_name'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($username === '' || $password === '') {
            flash_set('ユーザ名とパスワードは必須です。', 'error');
        } elseif (!preg_match('/^[A-Za-z0-9_.\\-]{1,32}$/', $username)) {
            flash_set('ユーザ名は半角英数記号(_-.)1〜32文字で入力してください。', 'error');
        } elseif (!password_is_strong($password)) {
            flash_set('パスワードは 8 文字以上で入力してください。', 'error');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('メールアドレスの形式が正しくありません。', 'error');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, display_name, email) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $display_name ?: null, $email ?: null]);
                $newId = (int)$pdo->lastInsertId();
                audit_log($pdo, $me, 'user_create', 'user', $newId, $username, ['role'=>$role]);
                flash_set('ユーザを作成しました。', 'success');
            } catch (PDOException $e) {
                flash_set('そのユーザ名は既に存在します。', 'error');
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $display_name = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        if ($id === (int)$me['id'] && $role !== 'admin') {
            flash_set('自分自身の管理者権限は外せません。', 'error');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('メールアドレスの形式が正しくありません。', 'error');
        } elseif ($newPassword !== '' && !password_is_strong($newPassword)) {
            flash_set('新パスワードは 8 文字以上必要です。', 'error');
        } else {
            $pdo->prepare('UPDATE users SET role=?, display_name=?, email=? WHERE id=?')
                ->execute([$role, $display_name ?: null, $email ?: null, $id]);
            if ($newPassword !== '') {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
            }
            audit_log($pdo, $me, 'user_update', 'user', $id, null, ['role'=>$role,'pw_changed'=>$newPassword!=='']);
            flash_set('ユーザ情報を更新しました。', 'success');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash_set('自分自身は無効化できません。', 'error');
        } else {
            $st = $pdo->prepare('SELECT username, is_active FROM users WHERE id=?');
            $st->execute([$id]); $u = $st->fetch();
            if ($u) {
                $new = (int)$u['is_active'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$new, $id]);
                audit_log($pdo, $me, $new ? 'user_activate' : 'user_deactivate', 'user', $id, $u['username']);
                flash_set($new ? '有効化しました。' : '無効化しました。', 'success');
            }
        }
    } elseif ($action === 'import_csv') {
        if (empty($_FILES['csv']) || $_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
            flash_set('CSV ファイルが選択されていません。', 'error');
        } elseif ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            flash_set('アップロードに失敗しました（コード: ' . (int)$_FILES['csv']['error'] . '）。', 'error');
        } else {
            $tmp = $_FILES['csv']['tmp_name'];
            $skipDup     = !empty($_POST['skip_dup']);
            $updateDup   = !empty($_POST['update_dup']);
            $defaultRole = ($_POST['default_role'] ?? 'user') === 'admin' ? 'admin' : 'user';

            // BOM 除去 + UTF-8 確認
            $raw = file_get_contents($tmp);
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            // 文字コード自動判定（CP932 で書き出される Excel CSV 対応）
            $enc = mb_detect_encoding($raw, ['UTF-8','SJIS-win','CP932','EUC-JP'], true);
            if ($enc && $enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
            $fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh);

            $imported = 0; $updated = 0; $skipped = 0; $errors = [];
            $rowNum = 0;
            $insSt = $pdo->prepare('INSERT INTO users (username, password_hash, role, display_name, email) VALUES (?, ?, ?, ?, ?)');
            $updSt = $pdo->prepare('UPDATE users SET password_hash=?, role=?, display_name=?, email=? WHERE username=?');
            $existSt = $pdo->prepare('SELECT id FROM users WHERE username = ?');

            $headerSeen = false;
            while (($row = fgetcsv($fh)) !== false) {
                $rowNum++;
                if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) continue; // 空行
                // ヘッダ行スキップ（最初の行が "username" 等を含むときだけ）
                if (!$headerSeen) {
                    $headerSeen = true;
                    $first = strtolower(trim((string)($row[0] ?? '')));
                    if (in_array($first, ['username','user','ユーザ名','ユーザー名'], true)) continue;
                }
                $username     = trim((string)($row[0] ?? ''));
                $password     = (string)($row[1] ?? '');
                $role         = trim((string)($row[2] ?? '')) ?: $defaultRole;
                $display_name = trim((string)($row[3] ?? ''));
                $email        = trim((string)($row[4] ?? ''));
                $role = $role === 'admin' ? 'admin' : 'user';

                if ($username === '') { $errors[] = "行 $rowNum: ユーザ名が空"; continue; }
                if (!preg_match('/^[A-Za-z0-9_.\\-]{1,32}$/', $username)) { $errors[] = "行 $rowNum: ユーザ名 '$username' が不正"; continue; }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "行 $rowNum: メール形式不正 ($email)"; continue; }

                $existSt->execute([$username]);
                $exists = $existSt->fetch();

                if ($exists && !$updateDup && !$skipDup) {
                    $errors[] = "行 $rowNum: '$username' は既存。更新/スキップを選択してください";
                    continue;
                }
                if ($exists && $skipDup) { $skipped++; continue; }

                if (!$exists && !password_is_strong($password)) {
                    $errors[] = "行 $rowNum: パスワード '$username' は 8 文字以上必要"; continue;
                }
                if ($exists && $password !== '' && !password_is_strong($password)) {
                    $errors[] = "行 $rowNum: パスワード '$username' は 8 文字以上必要"; continue;
                }

                try {
                    if ($exists) {
                        $newHash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
                        if ($newHash) {
                            $updSt->execute([$newHash, $role, $display_name ?: null, $email ?: null, $username]);
                        } else {
                            $pdo->prepare('UPDATE users SET role=?, display_name=?, email=? WHERE username=?')
                                ->execute([$role, $display_name ?: null, $email ?: null, $username]);
                        }
                        audit_log($pdo, $me, 'user_csv_update', 'user', (int)$exists['id'], $username, ['role'=>$role]);
                        $updated++;
                    } else {
                        $insSt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $display_name ?: null, $email ?: null]);
                        $newId = (int)$pdo->lastInsertId();
                        audit_log($pdo, $me, 'user_csv_create', 'user', $newId, $username, ['role'=>$role]);
                        $imported++;
                    }
                } catch (PDOException $e) {
                    $errors[] = "行 $rowNum: DB エラー ('$username')";
                }
            }
            fclose($fh);

            $msg = "CSV 取込完了: 新規 {$imported}、更新 {$updated}、スキップ {$skipped}";
            if ($errors) {
                $msg .= '、エラー ' . count($errors) . ' 件';
                flash_set($msg, count($errors) === ($imported+$updated+$skipped+count($errors)) ? 'error' : 'info');
                foreach (array_slice($errors, 0, 10) as $e) flash_set($e, 'error');
                if (count($errors) > 10) flash_set('（他 ' . (count($errors) - 10) . ' 件のエラー省略）', 'info');
            } else {
                flash_set($msg, 'success');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash_set('自分自身は削除できません。', 'error');
        } else {
            $st = $pdo->prepare('SELECT username FROM users WHERE id=?');
            $st->execute([$id]); $u = $st->fetch();
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            if ($u) audit_log($pdo, $me, 'user_delete', 'user', $id, $u['username']);
            flash_set('ユーザを物理削除しました（関連するファイル・フォルダ・投稿も削除）。', 'success');
        }
    }
    header('Location: users.php');
    exit;
}

$users = $pdo->query('SELECT id, username, role, display_name, email, is_active, created_at FROM users ORDER BY id')->fetchAll();
render_header('ユーザ管理', $me);
?>
<div class="card">
  <h2>ユーザ管理</h2>
  <p class="muted">通常運用ではユーザの「無効化」を推奨します。物理削除は配下のファイル/フォルダ/投稿が全消失します。</p>
  <table>
    <thead><tr><th>ID</th><th>ユーザ名</th><th>表示名</th><th>メール</th><th>権限</th><th>状態</th><th>新パスワード</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <td><?= (int)$u['id'] ?></td>
          <td><?= h($u['username']) ?><?php if ((int)$u['is_active']===0): ?> <span class="badge inactive">無効</span><?php endif; ?></td>
          <td><input type="text" name="display_name" value="<?= h($u['display_name']??'') ?>" style="width:120px;"></td>
          <td><input type="email" name="email" value="<?= h($u['email']??'') ?>" style="width:160px;"></td>
          <td>
            <select name="role">
              <option value="user"  <?= $u['role']==='user'?'selected':'' ?>>user</option>
              <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
            </select>
          </td>
          <td class="muted"><?= (int)$u['is_active']?'有効':'無効' ?></td>
          <td><input type="password" name="password" placeholder="任意 (8文字以上)" style="width:130px;"></td>
          <td>
            <button type="submit">更新</button>
        </form>
        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" class="inline" onsubmit="return confirm('<?= (int)$u['is_active']?'無効化':'有効化' ?>します。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn-warn" type="submit"><?= (int)$u['is_active']?'無効化':'有効化' ?></button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('物理削除します。配下のファイル・投稿・フォルダもすべて消えます。本当に？');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn-danger" type="submit">削除</button>
          </form>
        <?php else: ?>
          <span class="muted">（自分）</span>
        <?php endif; ?>
          </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>新規ユーザ作成</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <div><label>ユーザ名</label><input type="text" name="username" required style="width:100%"></div>
      <div><label>表示名</label><input type="text" name="display_name" style="width:100%"></div>
      <div><label>メール</label><input type="email" name="email" style="width:100%"></div>
    </div>
    <div class="row">
      <div><label>パスワード（8文字以上）</label><input type="password" name="password" required minlength="8" style="width:100%"></div>
      <div><label>権限</label>
        <select name="role" style="width:100%">
          <option value="user">user（一般）</option>
          <option value="admin">admin（管理）</option>
        </select>
      </div>
    </div>
    <div style="margin-top:12px;"><button type="submit">作成</button></div>
  </form>
</div>

<div class="card">
  <h3>CSV 一括登録</h3>
  <p class="muted">
    形式（ヘッダ行は任意）: <code>username,password,role,display_name,email</code><br>
    role は <code>admin</code> または <code>user</code>（空欄時は下の「既定の権限」を採用）。<br>
    パスワードは 8 文字以上。文字コードは UTF-8 / Shift_JIS(CP932) 自動判定。<br>
    <a href="users_csv_template.php">サンプル CSV をダウンロード</a>
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="import_csv">
    <div class="row">
      <div><label>CSV ファイル</label><input type="file" name="csv" accept=".csv,text/csv" required></div>
      <div><label>既定の権限（空欄行用）</label>
        <select name="default_role" style="width:100%">
          <option value="user">user</option>
          <option value="admin">admin</option>
        </select>
      </div>
      <div><label>既存ユーザ名がある場合</label>
        <label style="display:inline;font-weight:normal;margin-top:4px;"><input type="radio" name="dup_action" value="error" checked onclick="document.getElementById('skip_dup').value='';document.getElementById('update_dup').value='';"> エラー扱い</label>
        <label style="display:inline;font-weight:normal;margin-left:8px;"><input type="radio" name="dup_action" value="skip" onclick="document.getElementById('skip_dup').value='1';document.getElementById('update_dup').value='';"> スキップ</label>
        <label style="display:inline;font-weight:normal;margin-left:8px;"><input type="radio" name="dup_action" value="update" onclick="document.getElementById('skip_dup').value='';document.getElementById('update_dup').value='1';"> 上書き更新</label>
        <input type="hidden" name="skip_dup"   id="skip_dup"   value="">
        <input type="hidden" name="update_dup" id="update_dup" value="">
      </div>
    </div>
    <div style="margin-top:12px;"><button type="submit">取込実行</button></div>
  </form>
</div>
<?php render_footer(); ?>
