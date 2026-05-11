# apiphp — PHP + SQLite アカウント/ファイル/掲示板アプリ

## 機能
- ユーザアカウント管理（管理者のみ）
- 権限管理: `admin`（全操作可）/ `user`（自分のフォルダ・ファイルのみ操作可）
- ファイル/フォルダ管理（作成・削除・アップロード・ダウンロード）
- 掲示板（宛先=特定ユーザ or 全員）

## 必要環境
- PHP 8.0 以上（`pdo_sqlite` 有効）

## 起動方法（PHP内蔵サーバ）
```
cd C:\Project\AI\apiphp
php -S localhost:8080 router.php
```
ブラウザで http://localhost:8080/ を開く。

> 内蔵サーバは `.htaccess` を読まないため、`router.php` で `data/`, `phpfilefolder/`, `includes/` への直接アクセスを遮断しています。Apache/IIS 等で運用する場合は `.htaccess` が効くので `router.php` 無しでも安全です。

初期ログイン: `admin` / `admin`
（初回ログイン後、`ユーザ管理` から必ずパスワードを変更してください）

## ディレクトリ構成
```
apiphp/
├── index.php              ログイン振り分け
├── login.php / logout.php
├── dashboard.php          ホーム
├── users.php              ユーザ管理（管理者のみ）
├── files.php              ファイル/フォルダ管理
├── download.php           ダウンロード
├── board.php              掲示板
├── includes/
│   ├── db.php             DB接続/初期化
│   ├── auth.php           認証/CSRF/ヘルパ
│   └── layout.php         共通レイアウト
├── data/
│   └── app.db             SQLite DB（自動生成）
└── phpfilefolder/         ファイル保管ルート
    └── _storage/          実体ファイル（ユニーク名で保存）
```

## 注意
- `data/` および `phpfilefolder/` 配下は Apache の `.htaccess` で外部アクセスを拒否しています。PHP内蔵サーバや他のWebサーバを使う場合、これらのフォルダにWebからアクセスできないようドキュメントルートを `apiphp/` に限定してください。
- 内蔵サーバ利用時は基本的に `phpfilefolder/_storage/` のファイル名がランダムなので推測アクセスは困難ですが、本番運用ではドキュメントルートを分離してください。

## ライセンス
このプロジェクトは [Apache License 2.0](LICENSE) のもとで公開されています。
