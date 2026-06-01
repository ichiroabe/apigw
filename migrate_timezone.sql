-- Migrate existing UTC timestamps to JST (UTC+9)
-- SQLite: datetime(col, '+9 hours')

UPDATE users SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;

UPDATE folders SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;
UPDATE folders SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL;

UPDATE files SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;
UPDATE files SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL;

UPDATE posts SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;
UPDATE posts SET updated_at = datetime(updated_at, '+9 hours') WHERE updated_at IS NOT NULL;
UPDATE posts SET deleted_at = datetime(deleted_at, '+9 hours') WHERE deleted_at IS NOT NULL;

UPDATE post_reads SET read_at = datetime(read_at, '+9 hours') WHERE read_at IS NOT NULL;

UPDATE groups SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;

UPDATE audit_log SET created_at = datetime(created_at, '+9 hours') WHERE created_at IS NOT NULL;

UPDATE login_attempts SET attempted_at = datetime(attempted_at, '+9 hours') WHERE attempted_at IS NOT NULL;
