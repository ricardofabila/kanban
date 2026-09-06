<?php
declare(strict_types=1);

/**
 * Backup store: the backups folder and the operations the Backups page runs
 * against it. Every backup is an ordinary, self-contained SQLite file — you can
 * copy one out of `data/backups/` and open it with any SQLite tool, and
 * restoring is a plain file copy back over the live database.
 */

const BACKUP_DIR = __DIR__ . '/data/backups';

/** Tables a file must have before we will restore from it. */
const BACKUP_REQUIRED_TABLES = ['projects', 'columns', 'tasks'];

function backup_dir(): string
{
    if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0775, true) && !is_dir(BACKUP_DIR)) {
        throw new RuntimeException('Could not create the backups folder.');
    }
    return BACKUP_DIR;
}

/**
 * A name is only ever a bare filename inside the backups folder — never a path.
 * Everything that touches the filesystem goes through here.
 */
function backup_name_valid(string $name): bool
{
    return $name !== ''
        && basename($name) === $name
        && !str_starts_with($name, '.')
        && str_ends_with($name, '.sqlite')
        && !str_contains($name, '/')
        && !str_contains($name, '\\');
}

function backup_path(string $name): string
{
    if (!backup_name_valid($name)) {
        throw new RuntimeException('That is not a valid backup name.');
    }

    $path = backup_dir() . '/' . $name;

    // Belt and braces: refuse anything that resolves outside the folder.
    $real = realpath($path);
    if ($real !== false && !str_starts_with($real, realpath(backup_dir()) . '/')) {
        throw new RuntimeException('That is not a valid backup name.');
    }

    return $path;
}

/** How a backup came to exist, inferred from its filename. */
function backup_kind(string $name): string
{
    if (str_starts_with($name, 'pre-restore-')) return 'Before restore';
    if (str_starts_with($name, 'backup-'))      return 'Manual';
    return 'Added by hand';
}

/** Every backup on disk, newest first. */
function backup_list(): array
{
    $rows = [];

    foreach (scandir(backup_dir()) ?: [] as $name) {
        if (!backup_name_valid($name)) {
            continue;
        }
        $path = backup_dir() . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'kind' => backup_kind($name),
            'size' => (int) filesize($path),
            'time' => (int) filemtime($path),
        ];
    }

    usort($rows, static fn(array $a, array $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);

    return $rows;
}

/**
 * Snapshot the live database into the backups folder.
 *
 * `VACUUM INTO` rather than copy(): the database runs in WAL mode, so the most
 * recent commits sit in `database.sqlite-wal` and a copy of the main file alone
 * would silently miss them. This writes one consistent, compacted file in a
 * single statement, with no lock dance and nothing to race against.
 */
function backup_create(string $prefix = 'backup'): string
{
    $dir  = backup_dir();
    $stamp = date('Ymd-His');

    $name = "$prefix-$stamp.sqlite";
    for ($n = 2; file_exists("$dir/$name"); $n++) {
        $name = "$prefix-$stamp-$n.sqlite";
    }

    $statement = db()->prepare('VACUUM INTO ?');
    $statement->execute(["$dir/$name"]);

    return $name;
}

/** Reject anything that is not a readable database with our tables in it. */
function backup_verify(string $path): void
{
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $check  = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        throw new RuntimeException('That file is not a readable SQLite database.');
    }

    if (strtolower($check) !== 'ok') {
        throw new RuntimeException('That backup failed SQLite\'s integrity check.');
    }

    foreach (BACKUP_REQUIRED_TABLES as $table) {
        if (!in_array($table, $tables, true)) {
            throw new RuntimeException("That backup has no '$table' table, so it is not a board backup.");
        }
    }
}

/**
 * Replace the live database with a backup.
 *
 * Takes a `pre-restore` snapshot first, so the state being replaced is itself
 * recoverable, and carries the current password across — restoring a board from
 * before a password change should not lock anyone out.
 *
 * Returns the name of the safety snapshot it made.
 */
function backup_restore(string $name): string
{
    $path = backup_path($name);
    if (!is_file($path)) {
        throw new RuntimeException('That backup no longer exists.');
    }

    backup_verify($path);

    $currentHash = setting_get('password_hash');
    $safety = backup_create('pre-restore');

    db_close();

    // The WAL and shared-memory files belong to the database being replaced.
    // Left behind, SQLite would try to replay them against the restored file.
    foreach (['-wal', '-shm'] as $suffix) {
        if (file_exists(DB_PATH . $suffix)) {
            unlink(DB_PATH . $suffix);
        }
    }

    if (!copy($path, DB_PATH)) {
        throw new RuntimeException('Could not write over the live database file.');
    }

    if ($currentHash !== null) {
        setting_set('password_hash', $currentHash);
    }

    return $safety;
}

function backup_delete(string $name): void
{
    $path = backup_path($name);

    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Could not delete that backup.');
    }
}

/* ------------------------------------------------------------- formatting */

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'GB') {
            return round($value, $value < 10 ? 1 : 0) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

function format_when(int $timestamp): string
{
    $seconds = time() - $timestamp;

    if ($seconds < 60)    return 'just now';
    if ($seconds < 3600)  return plural_en(intdiv($seconds, 60), 'minute') . ' ago';
    if ($seconds < 86400) return plural_en(intdiv($seconds, 3600), 'hour') . ' ago';
    if ($seconds < 604800) return plural_en(intdiv($seconds, 86400), 'day') . ' ago';

    return date('j M Y', $timestamp);
}
