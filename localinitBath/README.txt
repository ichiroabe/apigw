apiphp ローカル管理バッチ
================================

このフォルダのバッチで、ローカル環境の DB と保管ファイルを操作できます。

▼ init.bat
  ローカル DB（data\app.db）と保管ファイル（phpfilefolder\_storage\*）を全削除し、
  admin/admin のみの初期状態に戻します。
  - 動作中の PHP サーバ（ポート 8080）は自動停止します。
  - PHP CLI で DB を即座に再生成します。

▼ start.bat
  PHP 内蔵サーバを http://localhost:8080/ で起動します。
  停止は Ctrl+C。

▼ reset-and-start.bat
  init.bat → start.bat を連続実行。新規開発時に便利。

注意事項
--------
- 既に 8080 で別アプリが動作している場合は事前に停止してください。
- 初期管理者アカウントは admin / admin です。本番運用では必ず変更してください。
- PHP のパスは C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe を想定。
  別バージョンを使う場合は各 .bat の "set PHP=" 行を編集してください。
