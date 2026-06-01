<?php
require_once __DIR__ . '/config.php';
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
    $dbFile = $dataDir . '/app.db';
    $isNew = !file_exists($dbFile);
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    if ($isNew) seed_initial_admin($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('admin','user')),
        display_name TEXT,
        email TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        parent_id INTEGER,
        owner_id INTEGER NOT NULL,
        is_shared INTEGER NOT NULL DEFAULT 0,
        share_mode TEXT,
        deleted_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(parent_id) REFERENCES folders(id) ON DELETE CASCADE,
        FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        folder_id INTEGER,
        owner_id INTEGER NOT NULL,
        size INTEGER NOT NULL,
        mime_type TEXT,
        stored_name TEXT NOT NULL,
        deleted_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE,
        FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER NOT NULL,
        recipient_id INTEGER,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        parent_post_id INTEGER,
        attachment_file_id INTEGER,
        deleted_at TEXT,
        updated_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY(attachment_file_id) REFERENCES files(id) ON DELETE SET NULL
    )");

    // Generic ALTER ADD COLUMN migration
    $migrate = function(string $table, string $col, string $def) use ($pdo) {
        $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($cols as $c) if ($c['name'] === $col) return;
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
    };
    $migrate('users',   'display_name', 'TEXT');
    $migrate('users',   'email',        'TEXT');
    $migrate('users',   'is_active',    'INTEGER NOT NULL DEFAULT 1');
    $migrate('users',   'theme',        "TEXT NOT NULL DEFAULT 'light'");
    $migrate('folders', 'is_shared',    'INTEGER NOT NULL DEFAULT 0');
    $migrate('folders', 'share_mode',   'TEXT');
    $migrate('folders', 'deleted_at',   'TEXT');
    $migrate('files',   'deleted_at',   'TEXT');
    $migrate('posts',   'parent_post_id',     'INTEGER REFERENCES posts(id) ON DELETE CASCADE');
    $migrate('posts',   'attachment_file_id', 'INTEGER REFERENCES files(id) ON DELETE SET NULL');
    $migrate('posts',   'deleted_at',         'TEXT');
    $migrate('posts',   'updated_at',         'TEXT');

    // Backfill share_mode from is_shared
    $pdo->exec("UPDATE folders SET share_mode = 'edit' WHERE is_shared = 1 AND share_mode IS NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS post_recipients (
        post_id INTEGER NOT NULL,
        user_id INTEGER,
        PRIMARY KEY(post_id, user_id),
        FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_reads (
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        read_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(post_id, user_id),
        FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        description TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_groups (
        group_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        PRIMARY KEY(group_id, user_id),
        FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS folder_acl (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id INTEGER NOT NULL,
        user_id INTEGER,
        group_id INTEGER,
        mode TEXT NOT NULL CHECK(mode IN ('view','edit')),
        FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folder_acl_folder ON folder_acl(folder_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folder_acl_user   ON folder_acl(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folder_acl_group  ON folder_acl(group_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        target_type TEXT,
        target_id INTEGER,
        target_name TEXT,
        meta TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        ip TEXT,
        success INTEGER NOT NULL,
        attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_user ON login_attempts(username, attempted_at)");
}

function seed_initial_admin(PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)');
    $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT), 'admin', '管理者']);
}

function now_jst(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

function storage_dir(): string {
    $dir = __DIR__ . '/../phpfilefolder/_storage';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

/**
 * 共有が継承される全フォルダ ID。
 * 旧 is_shared 互換 + share_mode IS NOT NULL のフォルダを root とし、配下を BFS で展開。
 * @return array<int, string> id => 'view' | 'edit'  ※継承時は root のモードを伝播
 */
function shared_folder_modes(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, share_mode FROM folders
                         WHERE deleted_at IS NULL
                           AND (is_shared = 1 OR (share_mode IS NOT NULL AND share_mode <> ''))")->fetchAll();
    if (!$rows) return [];
    $modes = [];
    foreach ($rows as $r) {
        $modes[(int)$r['id']] = $r['share_mode'] ?: 'edit';
    }
    $frontier = array_keys($modes);
    while ($frontier) {
        $in = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $pdo->prepare("SELECT id, parent_id, share_mode FROM folders WHERE parent_id IN ($in) AND deleted_at IS NULL");
        $stmt->execute($frontier);
        $next = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int)$r['id'];
            if (!isset($modes[$id])) {
                if ($modes[(int)$r['parent_id']] === 'private' || $r['share_mode'] === 'private') {
                    continue; // 親がprivate、または自身がprivateの場合は継承を遮断
                }
                $modes[$id] = $modes[(int)$r['parent_id']] ?? 'view';
                $next[] = $id;
            }
        }
        $frontier = $next;
    }
    
    // private指定のフォルダを一覧から除外する（アクセス遮断）
    foreach ($modes as $id => $mode) {
        if ($mode === 'private') {
            unset($modes[$id]);
        }
    }
    
    return $modes;
}

function shared_folder_ids(PDO $pdo): array {
    return array_keys(shared_folder_modes($pdo));
}

/**
 * folder_acl による拡張アクセス権を user 視点で展開。
 * @return array<int, string> folder_id => 'view'|'edit' （所有・admin・全体共有を除く追加分）
 */
function acl_folder_modes(PDO $pdo, int $userId): array {
    $sql = "SELECT a.folder_id, a.mode
            FROM folder_acl a
            LEFT JOIN user_groups ug ON ug.group_id = a.group_id AND ug.user_id = ?
            WHERE a.user_id = ? OR ug.user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId]);
    $rootModes = [];
    foreach ($stmt->fetchAll() as $r) {
        $id = (int)$r['folder_id'];
        $m = $r['mode'];
        // edit > view（強い権限を優先）
        if (!isset($rootModes[$id]) || ($rootModes[$id] === 'view' && $m === 'edit')) {
            $rootModes[$id] = $m;
        }
    }
    if (!$rootModes) return [];
    $modes = $rootModes;
    $frontier = array_keys($modes);
    while ($frontier) {
        $in = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $pdo->prepare("SELECT id, parent_id, share_mode FROM folders WHERE parent_id IN ($in) AND deleted_at IS NULL");
        $stmt->execute($frontier);
        $next = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int)$r['id'];
            if (!isset($modes[$id])) {
                if ($r['share_mode'] === 'private') {
                    continue; // 個別アクセス権（ACL）の継承であっても、遮断設定があればストップ
                }
                $modes[$id] = $modes[(int)$r['parent_id']];
                $next[] = $id;
            }
        }
        $frontier = $next;
    }
    return $modes;
}

function audit_log(PDO $pdo, ?array $user, string $action, ?string $targetType = null, ?int $targetId = null, ?string $targetName = null, $meta = null): void {
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, username, action, target_type, target_id, target_name, meta, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $user['id'] ?? null,
        $user['username'] ?? null,
        $action, $targetType, $targetId, $targetName,
        $meta === null ? null : (is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_UNICODE)),
        now_jst()
    ]);
}
