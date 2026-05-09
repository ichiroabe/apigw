<?php
require_once __DIR__ . '/includes/auth.php';
$me = require_admin();

// UTF-8 BOM を付けて Excel でも文字化けしないように
$bom = "\xEF\xBB\xBF";
$rows = [
    ['username','password','role','display_name','email'],
    ['taro',    'pass1234',     'user',  '山田 太郎', 'taro@example.com'],
    ['hanako',  'secretpw1',    'admin', '佐藤 花子', 'hanako@example.com'],
    ['ichiro',  'mypassword',   'user',  '鈴木 一郎', ''],
];
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="users_template.csv"');
header('X-Content-Type-Options: nosniff');
echo $bom;
$fh = fopen('php://output', 'w');
foreach ($rows as $r) fputcsv($fh, $r);
fclose($fh);
exit;
