<?php
/**
 * Database layer — works with SQLite (default, zero-config) or MySQL/MariaDB.
 */
declare(strict_types=1);

function db(?array $override = null): PDO
{
    static $pdo = null;
    if ($override !== null) {
        $pdo = db_connect($override);
        return $pdo;
    }
    if ($pdo === null) {
        $pdo = db_connect(config('db', []));
    }
    return $pdo;
}

function db_connect(array $c): PDO
{
    $driver = $c['driver'] ?? 'sqlite';
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if ($driver === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'] ?? 'localhost',
            (int)($c['port'] ?? 3306),
            $c['name'] ?? ''
        );
        return new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $opts);
    }
    $file = $c['path'] ?? (DATA_DIR . '/app.sqlite');
    $pdo = new PDO('sqlite:' . $file, null, null, $opts);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function db_driver(): string
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    db_exec($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    foreach (array_keys($data) as $c) $sets[] = $c . ' = ?';
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return db_exec($sql, array_merge(array_values($data), $whereParams));
}

/** Creates all tables. Safe to run repeatedly. */
function db_migrate(): void
{
    $mysql = db_driver() === 'mysql';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $str = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $int = $mysql ? 'BIGINT' : 'INTEGER';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tables = [
        "settings (k $str PRIMARY KEY, v $txt)",
        "channels (
            id $pk,
            yt_channel_id $str,
            title $str,
            handle $str,
            thumbnail $txt,
            client_id $txt,
            client_secret_enc $txt,
            refresh_token_enc $txt,
            access_token_enc $txt,
            token_expires $int DEFAULT 0,
            ai_access $str DEFAULT 'full',
            color $str,
            notes $txt,
            status $str DEFAULT 'pending',
            last_error $txt,
            stats_json $txt,
            created_at $int,
            updated_at $int
        )",
        "oauth_clients (
            id $pk,
            client_id $str UNIQUE,
            client_secret_hash $txt,
            name $str,
            redirect_uris $txt,
            dynamic $int DEFAULT 1,
            created_at $int,
            last_used $int
        )",
        "oauth_codes (
            code_hash $str PRIMARY KEY,
            client_id $str,
            redirect_uri $txt,
            code_challenge $txt,
            scope $txt,
            resource $txt,
            expires $int
        )",
        "oauth_tokens (
            id $pk,
            token_hash $str UNIQUE,
            kind $str,
            client_id $str,
            family $str,
            expires $int,
            created_at $int,
            last_used $int,
            revoked $int DEFAULT 0
        )",
        "uploads (
            id $pk,
            token $str UNIQUE,
            channel_id $int,
            source $str,
            source_url $txt,
            filename $txt,
            mime $str,
            size $int DEFAULT 0,
            received $int DEFAULT 0,
            path $txt,
            status $str,
            yt_session $txt,
            yt_sent $int DEFAULT 0,
            video_id $str,
            meta $txt,
            error $txt,
            expires $int,
            created_at $int,
            updated_at $int
        )",
        "notebook (
            id $pk,
            channel_id $int,
            kind $str,
            title $txt,
            body $txt,
            data $txt,
            tags $txt,
            pinned $int DEFAULT 0,
            author $str DEFAULT 'ai',
            period_start $str,
            period_end $str,
            created_at $int,
            updated_at $int
        )",
        "snapshots (
            id $pk,
            channel_id $int,
            day $str,
            subscribers $int,
            views $int,
            videos $int,
            created_at $int
        )",
        "activity (
            id $pk,
            source $str,
            action $str,
            channel_id $int,
            detail $txt,
            ok $int DEFAULT 1,
            created_at $int
        )",
        "login_attempts (id $pk, ip $str, created_at $int)",
        "cache (k $str PRIMARY KEY, v $txt, expires $int)",
    ];
    foreach ($tables as $t) {
        db()->exec('CREATE TABLE IF NOT EXISTS ' . $t . $tail);
    }
    $indexes = [
        'idx_snap ON snapshots (channel_id, day)',
        'idx_act ON activity (created_at)',
        'idx_note ON notebook (channel_id, created_at)',
        'idx_tok ON oauth_tokens (family)',
    ];
    foreach ($indexes as $ix) {
        try {
            db()->exec(($mysql ? 'CREATE INDEX ' : 'CREATE INDEX IF NOT EXISTS ') . $ix);
        } catch (Throwable $e) { /* already exists on MySQL */ }
    }
}
