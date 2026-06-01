<?php
require_once __DIR__ . '/db.php';

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, username, role, display_name, email, is_active, theme FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch() ?: null;
    if (!$u || (int)$u['is_active'] === 0) {
        $_SESSION = []; session_destroy();
        return null;
    }
    return $u;
}

const AVAILABLE_THEMES = [
    'light'    => ['label' => 'ライト',     'desc' => '標準の明るいテーマ'],
    'dark'     => ['label' => 'ダーク',     'desc' => '暗い背景・低輝度'],
    'sepia'    => ['label' => 'セピア',     'desc' => '目に優しいクリーム色'],
    'contrast' => ['label' => 'ハイコントラスト', 'desc' => 'アクセシビリティ向け'],
];

function valid_theme(?string $t): string {
    return ($t && isset(AVAILABLE_THEMES[$t])) ? $t : 'light';
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403); echo '権限がありません。'; exit;
    }
    return $u;
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_rotate(): void {
    start_session();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function check_csrf(): void {
    start_session();
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400); exit('CSRFトークンが無効です。');
    }
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function display_name(array $u): string {
    return ($u['display_name'] ?? '') !== '' ? $u['display_name'] : $u['username'];
}

function flash_set(string $msg, string $type = 'info'): void {
    start_session();
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_get(): array {
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** ログイン試行のレート制限: 直近 15 分以内に 5 回以上失敗で 15 分ロック */
function login_locked(PDO $pdo, string $username): bool {
    $since = date('Y-m-d H:i:s', time() - 15 * 60);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM login_attempts
                           WHERE username = ? AND success = 0 AND attempted_at >= ?');
    $stmt->execute([$username, $since]);
    return (int)$stmt->fetch()['c'] >= 5;
}
function record_login_attempt(PDO $pdo, string $username, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $pdo->prepare('INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?,?,?,?)')
        ->execute([$username, $ip, $success ? 1 : 0, now_jst()]);
}

/** 「ファイル/フォルダの実効権限」: returns 'edit'|'view'|null */
function effective_folder_mode(array $user, ?array $folder, array $sharedModes, array $aclModes): ?string {
    if ($user['role'] === 'admin') return 'edit';
    if (!$folder) return 'edit'; // root level
    if ((int)$folder['owner_id'] === (int)$user['id']) return 'edit';
    $fid = (int)$folder['id'];
    $a = $aclModes[$fid] ?? null;
    $s = $sharedModes[$fid] ?? null;
    if ($a === 'edit' || $s === 'edit') return 'edit';
    if ($a === 'view' || $s === 'view') return 'view';
    return null;
}

function password_is_strong(string $pw): bool {
    return strlen($pw) >= 8;
}
