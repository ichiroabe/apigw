#!/data/data/com.termux/files/usr/bin/bash
# Termux (Android) setup script for apiphp

set -e

echo "=== apiphp Termux セットアップ ==="

# パッケージ更新
pkg update -y

# PHP と SQLite のインストール
pkg install -y php php-sqlite

# 必要なフォルダを作成
mkdir -p data phpfilefolder/_storage

# パーミッション設定
chmod 700 data phpfilefolder

echo ""
echo "=== セットアップ完了 ==="
echo "以下のコマンドでサーバを起動してください:"
echo ""
echo "  php -S 0.0.0.0:8080 router.php"
echo ""
echo "ブラウザで http://localhost:8080/ を開く"
echo "初期ログイン: admin / admin"
