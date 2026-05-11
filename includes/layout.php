<?php
require_once __DIR__ . '/auth.php';

function unread_count(PDO $pdo, int $userId): int {
    $sql = "SELECT COUNT(*) AS c FROM posts p
            WHERE p.deleted_at IS NULL
              AND p.sender_id <> :uid
              AND (
                p.recipient_id IS NULL OR p.recipient_id = :uid
                OR EXISTS (SELECT 1 FROM post_recipients pr WHERE pr.post_id = p.id AND (pr.user_id = :uid OR pr.user_id IS NULL))
                OR (p.parent_post_id IS NOT NULL AND EXISTS (
                  SELECT 1 FROM posts t WHERE t.id = p.parent_post_id
                    AND (t.recipient_id IS NULL OR t.recipient_id = :uid OR t.sender_id = :uid)
                ))
              )
              AND NOT EXISTS (SELECT 1 FROM post_reads r WHERE r.post_id = p.id AND r.user_id = :uid)";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $userId]);
    return (int)$st->fetch()['c'];
}

function render_header(string $title, ?array $user = null): void {
    $user = $user ?? current_user();
    $flash = flash_get();
    $unread = ($user ? unread_count(db(), (int)$user['id']) : 0);
    $theme = valid_theme($user['theme'] ?? 'light');
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
<style>
/* テーマ別カラー変数 */
.theme-light {
  --bg:#f4f6f8; --fg:#222; --header-bg:#1f2937; --header-fg:#fff;
  --header-link:#d1d5db; --header-link-hover:#fff; --header-accent:#fbbf24;
  --card-bg:#fff; --card-shadow:0 1px 3px rgba(0,0,0,.06);
  --border:#e5e7eb; --th-bg:#f9fafb; --th-fg:#374151; --label-fg:#374151;
  --input-bg:#fff; --input-fg:#222; --input-border:#d1d5db;
  --link:#2563eb; --muted:#6b7280;
  --btn-bg:#2563eb; --btn-fg:#fff; --btn-hover:#1d4ed8;
  --btn-danger-bg:#dc2626; --btn-danger-hover:#b91c1c;
  --btn-secondary-bg:#6b7280; --btn-secondary-hover:#4b5563;
  --btn-warn-bg:#f59e0b; --btn-warn-hover:#d97706;
  --flash-info-bg:#dbeafe; --flash-info-fg:#1e40af;
  --flash-success-bg:#d1fae5; --flash-success-fg:#065f46;
  --flash-error-bg:#fee2e2; --flash-error-fg:#991b1b;
  --badge-bg:#e5e7eb; --badge-fg:#374151;
  --badge-admin-bg:#fde68a; --badge-admin-fg:#92400e;
  --badge-shared-bg:#bae6fd; --badge-shared-fg:#075985;
  --badge-shared-view-bg:#bbf7d0; --badge-shared-view-fg:#166534;
  --badge-inactive-bg:#9ca3af; --badge-inactive-fg:#fff;
  --badge-unread-bg:#ef4444; --badge-unread-fg:#fff;
  --badge-edited-bg:#ddd6fe; --badge-edited-fg:#5b21b6;
  --pre-bg:#f9fafb; --crumb-locked:#9ca3af;
  --pager-bg:#fff; --pager-border:#d1d5db; --pager-fg:#374151;
}
.theme-dark {
  --bg:#0f172a; --fg:#e5e7eb; --header-bg:#000; --header-fg:#fff;
  --header-link:#9ca3af; --header-link-hover:#fff; --header-accent:#fbbf24;
  --card-bg:#1e293b; --card-shadow:0 1px 4px rgba(0,0,0,.4);
  --border:#334155; --th-bg:#0f172a; --th-fg:#cbd5e1; --label-fg:#cbd5e1;
  --input-bg:#0f172a; --input-fg:#e5e7eb; --input-border:#475569;
  --link:#60a5fa; --muted:#94a3b8;
  --btn-bg:#3b82f6; --btn-fg:#fff; --btn-hover:#2563eb;
  --btn-danger-bg:#dc2626; --btn-danger-hover:#b91c1c;
  --btn-secondary-bg:#475569; --btn-secondary-hover:#334155;
  --btn-warn-bg:#d97706; --btn-warn-hover:#b45309;
  --flash-info-bg:#1e3a8a; --flash-info-fg:#bfdbfe;
  --flash-success-bg:#064e3b; --flash-success-fg:#a7f3d0;
  --flash-error-bg:#7f1d1d; --flash-error-fg:#fecaca;
  --badge-bg:#475569; --badge-fg:#e5e7eb;
  --badge-admin-bg:#92400e; --badge-admin-fg:#fde68a;
  --badge-shared-bg:#075985; --badge-shared-fg:#bae6fd;
  --badge-shared-view-bg:#166534; --badge-shared-view-fg:#bbf7d0;
  --badge-inactive-bg:#4b5563; --badge-inactive-fg:#cbd5e1;
  --badge-unread-bg:#dc2626; --badge-unread-fg:#fff;
  --badge-edited-bg:#5b21b6; --badge-edited-fg:#ddd6fe;
  --pre-bg:#0f172a; --crumb-locked:#64748b;
  --pager-bg:#1e293b; --pager-border:#475569; --pager-fg:#cbd5e1;
}
.theme-sepia {
  --bg:#f4ecd8; --fg:#3a2e1f; --header-bg:#5b4636; --header-fg:#f4ecd8;
  --header-link:#dcc7a3; --header-link-hover:#fff; --header-accent:#f0c060;
  --card-bg:#faf3df; --card-shadow:0 1px 3px rgba(91,70,54,.15);
  --border:#d6c7a3; --th-bg:#ebdfc1; --th-fg:#5b4636; --label-fg:#5b4636;
  --input-bg:#fffaec; --input-fg:#3a2e1f; --input-border:#bca97e;
  --link:#7a5c2e; --muted:#8a7a5d;
  --btn-bg:#8b6f3a; --btn-fg:#fff; --btn-hover:#6b5326;
  --btn-danger-bg:#a13f2c; --btn-danger-hover:#7d2e1f;
  --btn-secondary-bg:#8a7a5d; --btn-secondary-hover:#6e6049;
  --btn-warn-bg:#c08a3d; --btn-warn-hover:#9c6e2c;
  --flash-info-bg:#e8d8b0; --flash-info-fg:#5b4636;
  --flash-success-bg:#d4dfb4; --flash-success-fg:#3d5727;
  --flash-error-bg:#e8c5b8; --flash-error-fg:#7d2e1f;
  --badge-bg:#dcc7a3; --badge-fg:#5b4636;
  --badge-admin-bg:#f0c060; --badge-admin-fg:#5b4636;
  --badge-shared-bg:#b8d8c8; --badge-shared-fg:#1f4d3a;
  --badge-shared-view-bg:#c8d8b8; --badge-shared-view-fg:#3d5727;
  --badge-inactive-bg:#a89880; --badge-inactive-fg:#fff;
  --badge-unread-bg:#a13f2c; --badge-unread-fg:#fff;
  --badge-edited-bg:#d4c0a3; --badge-edited-fg:#5b4636;
  --pre-bg:#ebdfc1; --crumb-locked:#a89880;
  --pager-bg:#faf3df; --pager-border:#bca97e; --pager-fg:#5b4636;
}
.theme-contrast {
  --bg:#000; --fg:#fff; --header-bg:#000; --header-fg:#fff;
  --header-link:#fff; --header-link-hover:#ff0; --header-accent:#ff0;
  --card-bg:#000; --card-shadow:none;
  --border:#fff; --th-bg:#000; --th-fg:#ff0; --label-fg:#fff;
  --input-bg:#000; --input-fg:#fff; --input-border:#fff;
  --link:#ff0; --muted:#0ff;
  --btn-bg:#fff; --btn-fg:#000; --btn-hover:#ff0;
  --btn-danger-bg:#f00; --btn-danger-hover:#ff0;
  --btn-secondary-bg:#666; --btn-secondary-hover:#999;
  --btn-warn-bg:#ff8c00; --btn-warn-hover:#ffa500;
  --flash-info-bg:#000; --flash-info-fg:#0ff;
  --flash-success-bg:#000; --flash-success-fg:#0f0;
  --flash-error-bg:#000; --flash-error-fg:#f00;
  --badge-bg:#fff; --badge-fg:#000;
  --badge-admin-bg:#ff0; --badge-admin-fg:#000;
  --badge-shared-bg:#0ff; --badge-shared-fg:#000;
  --badge-shared-view-bg:#0f0; --badge-shared-view-fg:#000;
  --badge-inactive-bg:#666; --badge-inactive-fg:#fff;
  --badge-unread-bg:#f00; --badge-unread-fg:#fff;
  --badge-edited-bg:#f0f; --badge-edited-fg:#fff;
  --pre-bg:#111; --crumb-locked:#888;
  --pager-bg:#000; --pager-border:#fff; --pager-fg:#fff;
}

/* 共通スタイル（変数経由） */
* { box-sizing: border-box; }
body { font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
       margin: 0; background: var(--bg); color: var(--fg); }
header { background: var(--header-bg); color: var(--header-fg); padding: 12px 24px; display: flex; align-items: center; flex-wrap: wrap; }
header h1 { margin: 0; font-size: 18px; flex: 0 0 auto; }
header nav { margin-left: 24px; flex: 1; min-width: 0; }
header nav a { color: var(--header-link); text-decoration: none; margin-right: 16px; font-size: 14px; }
header nav a:hover { color: var(--header-link-hover); }
header nav .unread { background: var(--badge-unread-bg); color: var(--badge-unread-fg); border-radius:10px; padding:1px 6px; font-size:11px; margin-left:4px; }
header .user { font-size: 13px; color: var(--header-link); }
header .user a { color: var(--header-accent); margin-left: 12px; }
main { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
h2 { margin-top: 0; }
.card { background: var(--card-bg); border-radius: 6px; padding: 20px; margin-bottom: 16px; box-shadow: var(--card-shadow); }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
th { background: var(--th-bg); font-size: 13px; color: var(--th-fg); }
form.inline { display: inline; }
input[type=text], input[type=email], input[type=password], input[type=file], select, textarea {
    padding: 6px 8px; border: 1px solid var(--input-border); border-radius: 4px; font-size: 14px;
    background: var(--input-bg); color: var(--input-fg); }
textarea { width: 100%; min-height: 100px; }
label { display: block; margin: 8px 0 4px; font-size: 13px; color: var(--label-fg); }
button, .btn { padding: 6px 12px; border: 0; background: var(--btn-bg); color: var(--btn-fg); border-radius: 4px;
               cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
button:hover, .btn:hover { background: var(--btn-hover); }
.btn-danger { background: var(--btn-danger-bg); } .btn-danger:hover { background: var(--btn-danger-hover); }
.btn-secondary { background: var(--btn-secondary-bg); } .btn-secondary:hover { background: var(--btn-secondary-hover); }
.btn-warn { background: var(--btn-warn-bg); } .btn-warn:hover { background: var(--btn-warn-hover); }
.flash { padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 14px; }
.flash.info { background: var(--flash-info-bg); color: var(--flash-info-fg); }
.flash.success { background: var(--flash-success-bg); color: var(--flash-success-fg); }
.flash.error { background: var(--flash-error-bg); color: var(--flash-error-fg); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: var(--badge-bg); color: var(--badge-fg); }
.badge.admin { background: var(--badge-admin-bg); color: var(--badge-admin-fg); }
.badge.shared { background: var(--badge-shared-bg); color: var(--badge-shared-fg); margin-left:6px; }
.badge.shared-view { background: var(--badge-shared-view-bg); color: var(--badge-shared-view-fg); margin-left:6px; }
.badge.inactive { background: var(--badge-inactive-bg); color: var(--badge-inactive-fg); }
.badge.unread { background: var(--badge-unread-bg); color: var(--badge-unread-fg); }
.badge.edited { background: var(--badge-edited-bg); color: var(--badge-edited-fg); font-size: 10px; }
.muted { color: var(--muted); font-size: 12px; }
.breadcrumb { font-size: 14px; margin-bottom: 12px; }
.breadcrumb a { color: var(--link); text-decoration: none; }
.breadcrumb .crumb-locked { color: var(--crumb-locked); }
a { color: var(--link); }
pre { background: var(--pre-bg); }
.row { display: flex; gap: 16px; }
.row > * { flex: 1; }
.pager { margin: 12px 0; text-align: center; }
.pager a, .pager span { display: inline-block; padding: 4px 10px; margin: 0 2px; border:1px solid var(--pager-border); border-radius:4px; text-decoration:none; color: var(--pager-fg); background: var(--pager-bg); }
.pager .current { background: var(--btn-bg); color: var(--btn-fg); border-color: var(--btn-bg); }
.theme-swatch { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; margin-right:6px; border:1px solid #00000022; }
</style>
</head>
<body class="theme-<?= h($theme) ?>">
<header>
  <h1><?= h(APP_NAME) ?></h1>
  <nav>
    <?php if ($user): ?>
      <a href="dashboard.php">ホーム</a>
      <a href="files.php">ファイル</a>
      <a href="board.php">掲示板<?php if ($unread): ?><span class="unread"><?= (int)$unread ?></span><?php endif; ?></a>
      <a href="trash.php">ゴミ箱</a>
      <a href="help.php">ヘルプ</a>
      <?php if ($user['role'] === 'admin'): ?>
        <a href="users.php">ユーザ管理</a>
        <a href="groups.php">グループ</a>
        <a href="audit.php">監査ログ</a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
  <?php if ($user): ?>
    <div class="user">
      <?= h(display_name($user)) ?>
      <span class="badge <?= $user['role'] === 'admin' ? 'admin' : '' ?>"><?= h($user['role']) ?></span>
      <a href="settings.php">設定</a>
      <a href="logout.php">ログアウト</a>
    </div>
  <?php endif; ?>
</header>
<main>
<?php foreach ($flash as $f): ?>
  <div class="flash <?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function render_footer(): void {
    ?>
</main>
</body>
</html>
<?php
}
