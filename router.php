<?php
// PHP組み込みサーバー用ルータ
// 使い方: php -S localhost:8080 router.php
// data/ と phpfilefolder/ への直接アクセスを拒否（実体ファイルは download.php 経由でのみ取得）

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === false) {
    http_response_code(400);
    return true;
}

// ルーティング遮断対象
if (
    preg_match('#^/data(/|$)#i', $path) ||
    preg_match('#^/phpfilefolder(/|$)#i', $path) ||
    preg_match('#^/includes(/|$)#i', $path) ||
    preg_match('#/\.ht#i', $path)
) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// 静的ファイルがあれば組み込みサーバに任せる
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// それ以外は通常のフロントコントローラ動作
return false;
