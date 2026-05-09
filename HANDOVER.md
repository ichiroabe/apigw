# apiphp 引き継ぎメモ

最終更新: 2026-05-09 / プロジェクト: ファイル管理 + 掲示板の小規模グループウェア

## 1. プロジェクト概要

| 項目 | 内容 |
|---|---|
| 言語 | PHP 8.0+（本番は PHP 7.4 でも動作確認済） |
| DB | SQLite3（PDO 経由） |
| Web サーバ | Apache（本番）/ PHP 内蔵サーバ（ローカル） |
| ローカルパス | `C:\Project\AI\apiphp\` |
| ローカル URL | `http://localhost:8080/` |
| 本番 URL | `http://www.api-jpn.co.jp/gw/` |
| FTP | `ftp.api-jpn.co.jp` （アカウント `hm-apijp.03@deskwing.net`、パスは別途管理） |
| 初期管理者 | `admin` / `admin`（運用前に変更必須） |

## 2. ファイル構成

```
apiphp/
├── index.php              ログイン/ダッシュボード振り分け
├── login.php              ログイン処理（レート制限・監査）
├── logout.php             ログアウト
├── dashboard.php          ホーム画面
├── settings.php           ユーザ自身の設定（表示名・メール・テーマ）
├── password.php           パスワード変更
├── users.php              ユーザ管理（admin）+ CSV 一括登録
├── users_csv_template.php CSV ひな形 DL（admin）
├── files.php              ファイル/フォルダ管理（メイン画面）
├── download.php           ファイルダウンロード
├── backup.php             ZIP バックアップ生成（manifest 同梱）
├── restore.php            ZIP リストア（完全上書き、admin）
├── board.php              掲示板スレッド一覧・新規スレッド作成
├── thread.php             スレッド詳細・返信
├── trash.php              ゴミ箱（論理削除の復元/物理削除）
├── groups.php             グループ管理（admin）
├── audit.php              監査ログ閲覧（admin）
├── router.php             PHP 内蔵サーバ用ルータ（/data/, /includes/ 等を遮断）
├── .htaccess              Apache 用ルート保護
├── README.md              起動手順
├── HANDOVER.md            このファイル
├── data/
│   ├── app.db             SQLite DB（自動生成）
│   └── .htaccess          外部アクセス拒否
├── phpfilefolder/
│   ├── _storage/          実体ファイル保管（ランダム 16byte hex 名）
│   └── .htaccess          外部アクセス拒否
├── includes/
│   ├── db.php             DB 接続・スキーマ初期化・共有モード解決ヘルパ・audit_log()
│   ├── auth.php           セッション・CSRF・require_login/require_admin・display_name 等
│   ├── layout.php         render_header / render_footer・テーマ CSS・ヘッダナビ
│   └── .htaccess          外部アクセス拒否
└── localinitBath/         ローカル開発用バッチ（init・start・reset-and-start）
```

## 3. DB スキーマ（主要テーブル）

スキーマ定義は `includes/db.php` の `init_schema()` 内に集約。新規カラム追加は同ファイル内の `$migrate(...)` 呼び出しで idempotent に対応。

| テーブル | 主な列 |
|---|---|
| `users` | id, username, password_hash, role(admin/user), display_name, email, is_active, theme, created_at |
| `folders` | id, name, parent_id, owner_id, is_shared, share_mode(view/edit), deleted_at, created_at |
| `files` | id, name, folder_id, owner_id, size, mime_type, stored_name, deleted_at, created_at |
| `posts` | id, sender_id, recipient_id, subject, body, parent_post_id, attachment_file_id, deleted_at, updated_at, created_at |
| `post_recipients` | post_id, user_id (NULL = 全員) — 複数宛先 |
| `post_reads` | post_id, user_id, read_at — 既読記録 |
| `groups` | id, name, description |
| `user_groups` | group_id, user_id |
| `folder_acl` | id, folder_id, user_id, group_id, mode(view/edit) |
| `audit_log` | id, user_id, username, action, target_type, target_id, target_name, meta, created_at |
| `login_attempts` | id, username, ip, success, attempted_at |

外部キーは全て `ON DELETE CASCADE`（`posts.attachment_file_id` のみ `ON DELETE SET NULL`）。

## 4. アーキテクチャ・コーディング規約

### 共通ヘッダ・フッタ
```php
require_once __DIR__ . '/includes/layout.php';
$user = require_login();           // 又は require_admin()
$pdo = db();
// ... POST 処理 / GET レンダリング ...
render_header('画面タイトル', $user);
?>
<div class="card">...</div>
<?php render_footer(); ?>
```

### POST ハンドラ規約
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();                            // CSRF 必須
    $action = $_POST['action'] ?? '';
    if ($action === 'xxx') {
        // 入力バリデーション
        // 権限チェック
        // DB 更新
        audit_log($pdo, $user, 'xxx_action', 'target_type', $id, $name, ['meta'=>...]);
        flash_set('成功メッセージ', 'success');  // info/success/error
    }
    header('Location: same.php'); exit;     // PRG パターン徹底
}
```

### 出力エスケープ
- HTML 出力は必ず `h($value)` 経由（`htmlspecialchars(ENT_QUOTES, UTF-8)` 包装）
- onclick / data-* の中も `h()` を使う（json_encode 直接埋め込みは禁止 ※下記 6.1 参照）

### SQL
- すべて prepared statement
- 動的 IN 句は `array_map('intval', $ids)` で int 化してから `implode(',')`（過去にこのパターン多用）

### 権限判定
- フォルダ/ファイルの実効権限は `effective_folder_mode($user, $folder, $sharedModes, $aclModes)` を使う（`includes/auth.php`）
- 戻り値: `'edit'` / `'view'` / `null`
- `shared_folder_modes(PDO)` と `acl_folder_modes(PDO, $userId)` で共有・ACL を取得

## 5. 機能追加の標準手順

### A. 新画面を追加する場合

1. `xxx.php` を作成（既存の `groups.php` などを参考に）
2. 先頭で `require_once __DIR__ . '/includes/layout.php';`
3. `require_login()` / `require_admin()`
4. POST → action ごとに分岐 → audit_log → flash_set → redirect
5. GET → DB 取得 → `render_header()` → HTML → `render_footer()`
6. ナビへのリンクを `includes/layout.php` の `<nav>` に追加（admin 限定なら `if ($user['role']==='admin')` 内）

### B. DB スキーマ拡張

1. `includes/db.php` の `init_schema()` で：
   - 新規テーブルなら `CREATE TABLE IF NOT EXISTS ...`
   - 既存テーブルへのカラム追加なら `$migrate('table','col','TYPE');`（同じ関数が idempotent）
2. 既存データの backfill が必要なら `init_schema()` 末尾で `UPDATE ... WHERE col IS NULL` 等を流す（過去事例: `share_mode` ← `is_shared` のバックフィル）
3. SQLite の特殊事情:
   - ALTER COLUMN 不可（型変更時は新テーブル作って INSERT-SELECT）
   - 外部キー制約は接続ごとに `PRAGMA foreign_keys = ON`（既に `db()` 内で発行済）

### C. 監査ログを残す

すべての書き込み系操作に：
```php
audit_log($pdo, $user, 'action_name', 'target_type', $target_id, $target_name, ['key'=>'value']);
```

監査ログは `audit.php` で検索可能（admin 限定）。

### D. 新しい権限カテゴリの追加

現状は `users.role IN ('admin','user')` の 2 値のみ。第 3 ロールが必要な場合：
1. CHECK 制約を更新（テーブル再作成必要）
2. `auth.php` の `require_admin()` 等にロール判定追加
3. layout.php のナビ表示条件追加

## 6. 既知の落とし穴・解決済み事項

### 6.1 HTML 属性内に json_encode を埋めない
過去バグ: `onclick="(function(){...prompt('x',<?= json_encode($name) ?>)...})()"` は **`"` で属性が切れる**。
解決: data 属性 + 単独関数（`files.php` の `apiphpRename` 参照）。
```html
<button data-target="..." data-name="<?= h($name) ?>" onclick="apiphpRename(this)">
```

### 6.2 form.name は IDL 属性に取られる
`<form><input name="name"></form>` で `form.name` はフォームの `name` 属性（空文字列）になり得る（ブラウザにより挙動が違う）。
解決: `form.elements['name']` または `form.querySelector('input[name="name"]')` を使う。

### 6.3 Windows + Git Bash で curl 引数の日本語が CP932 化
curl のテストで `--data-urlencode "subject=日本語"` すると CP932 で送信され DB に化けバイトが残る。
解決: テストはブラウザ操作で行うか、PHP CLI 経由で直接 DB 投入する。本番ユーザはブラウザ経由なので影響なし。

### 6.4 PHP 内蔵サーバは .htaccess を読まない
本番（Apache）と挙動が違う。`router.php` で `/data/`, `/phpfilefolder/`, `/includes/` を 403 にしている。
新しい保護ディレクトリを追加する場合は `router.php` と各ディレクトリの `.htaccess` の両方を更新。

### 6.5 サーバに ZipArchive 拡張がない
本番は `pdo_sqlite=on, sqlite3=off, ZipArchive=off, gzdeflate=on, Phar=on(readonly)`。
解決: `backup.php` `restore.php` は ZipArchive と gzdeflate の両対応（class_exists 分岐）。
**新機能で zip を扱うときは要確認**。

### 6.6 PHP 7.4 に PHP 8 構文を持ち込まない
本番が PHP 7.4 のため、以下は使えない:
- `match` 式 → `switch` で代替
- nullsafe `?->` → `isset() ?` で
- `str_contains` / `str_starts_with` / `str_ends_with` → `strpos() !== false` 等
- 名前付き引数

CI / テストでは `php -l ファイル名` で文法だけ確認可能だが、PHP 8 の構文も通る点に注意。

### 6.7 セッション・CSRF
- ログインで必ず `session_regenerate_id(true)` + `csrf_rotate()`
- パスワード変更でも同様（password.php）
- CSRF 検証は **全 POST ハンドラの先頭で `check_csrf()`**

### 6.8 SQLite 並列書き込み
SQLite はファイルロックで 1 接続のみ書き込み可。`backup.php` のような長時間 DB スキャンが他の更新と衝突する場合は WAL モードを検討（現在は `DELETE` モード）。

### 6.9 文字コード
- DB / HTML / curl 引数は UTF-8 統一
- CSV 取込は UTF-8/SJIS-win/CP932 自動判定（mb_detect_encoding）
- ZIP ファイル名は **UTF-8（GP bit 11 セット）** で出力（Windows/Mac/Linux すべてで化けない）

## 7. 開発環境

### ローカル PHP
- `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe`
- `php.ini` で `extension=zip` 等を有効化済み

### バッチ（`localinitBath/`）
- `init.bat` → DB と `_storage/` をクリアし admin/admin 状態に戻す
- `start.bat` → サーバ起動
- `reset-and-start.bat` → 連続実行
- 実体は同名 `.ps1`（PowerShell スクリプト、UTF-8 BOM 付き）

### 動作確認の流れ
1. `localinitBath/init.bat` で初期化
2. `localinitBath/start.bat` でサーバ起動
3. `http://localhost:8080/` で `admin/admin` ログイン
4. 機能を触る
5. 必要なら `audit.php` で監査ログを確認

### Lint
```bash
cd C:\Project\AI\apiphp
php -l <file.php>
```
全ファイル一括は次のような PowerShell:
```powershell
Get-ChildItem *.php,includes\*.php | ForEach-Object { php -l $_.FullName }
```

## 8. デプロイ手順

### FTP 経由（curl）

```bash
FTP_HOST="ftp.api-jpn.co.jp"
FTP_USER='hm-apijp.03@deskwing.net'
FTP_PASS='<別途管理>'

# ファイル単体アップ
curl -T xxx.php -u "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/xxx.php"

# サブディレクトリへ
curl -T includes/xxx.php -u "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/includes/xxx.php"

# 削除
curl -X "DELE foo.txt" -u "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/"
# サブディレクトリのファイル削除は -Q 必須:
curl -Q "DELE /phpfilefolder/_storage/abcdef" -u "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/"
```

### 本番初期化
1. `data/app.db` を FTP で削除
2. `phpfilefolder/_storage/*` の中身を全削除（DELE で個別、ディレクトリは残す）
3. 次回 HTTP アクセスで自動的に admin/admin DB 再生成

### デプロイ後チェックリスト
- [ ] `/login.php` が 200
- [ ] `/data/app.db`, `/includes/db.php`, `/phpfilefolder/_storage/` がすべて 403
- [ ] admin/admin でログイン → ダッシュボード表示
- [ ] `audit.php` に `login_success` が記録されている
- [ ] エラーログを確認（X-Powered-By 等の不要ヘッダ漏れがないか）

## 9. セキュリティチェックリスト（運用開始前）

- [ ] admin パスワードを変更（`admin` のままにしない）
- [ ] `php.ini` で `expose_php = Off`（X-Powered-By を消す）
- [ ] `display_errors = Off`、`log_errors = On`
- [ ] HTTPS 化（http の場合はセッションクッキーが流れる）
- [ ] アップロードサイズ上限確認（`upload_max_filesize`, `post_max_size`）
- [ ] バックアップ手順を運用 SOP に明記（`backup.php?mode=all` の admin 取得）
- [ ] 監査ログの定期確認担当者を決定

## 10. 主要機能のキーポイント

### 共有モード
- フォルダに `share_mode='view'|'edit'` を設定すると配下に継承
- view: 閲覧/DL のみ、edit: 全員が書き込み可
- 共有 root の削除は所有者/admin のみ
- 細かい個別権限は `folder_acl`（user / group + view/edit）

### 掲示板
- スレッド = `parent_post_id IS NULL` の post
- 返信 = `parent_post_id` あり、件名は親から継承（DB 上は `subject=''`）
- 複数宛先は `post_recipients`（NULL は全員）
- 既読は `post_reads` に挿入（thread.php 表示時）
- ページネーション: `?page=N`（10 件/頁）

### バックアップ ZIP
- 構造: `<folder>/.../<file>` または `_root_files/<owner>/<file>`
- `__manifest__.json` 同梱でメタデータ完全保存
- リストア時は manifest 検出 → 所有者/share_mode/ACL を完全復元
- manifest 無しでも path-only リストアが可能（後方互換）

### CSV 一括登録
- 列: `username,password,role,display_name,email`
- 文字コード自動判定（UTF-8 / SJIS-win / CP932 / EUC-JP）
- 既存ユーザ: エラー / スキップ / 上書き更新を選択
- バリデーション: ユーザ名形式・パス 8 文字・メール形式

### ユーザテーマ
- `users.theme` カラム（light/dark/sepia/contrast）
- CSS カスタムプロパティで全要素が変動
- 各ユーザが `settings.php` から個別に変更可能

## 11. 参考: 過去に追加した機能の差分量目安

| 機能 | 追加/変更ファイル | 追加行数 | 工数感 |
|---|---|---|---|
| テーマ機能 | db.php, auth.php, layout.php, settings.php | 約 200 | 1〜2h |
| 掲示板スレッド化 | board.php, thread.php, db.php | 約 150 | 2h |
| ゴミ箱 | trash.php, files.php, board.php, db.php | 約 100 | 1h |
| バックアップ ZIP | backup.php, files.php | 約 250 | 2〜3h |
| リストア（manifest） | restore.php, backup.php | 約 200 | 2h |
| CSV 一括登録 | users.php, users_csv_template.php | 約 130 | 1h |

新機能を追加する際の工数見積もりの参考に。

---

不明点があれば監査ログ（`audit.php`）と各ファイル冒頭のコメントを参照。SQLite DB を直接見るときは `sqlite3 data/app.db` か PHP CLI:
```bash
php -r '$db=new PDO("sqlite:data/app.db");foreach($db->query("SELECT ...") as $r) print_r($r);'
```
