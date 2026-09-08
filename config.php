<?php
declare(strict_types=1);

/**
 * Shared bootstrap: session, database connection, schema creation, helpers.
 * Every entry point (index.php, login.php, logout.php, api.php) includes this.
 */

const DB_PATH = __DIR__ . '/data/database.sqlite';

/**
 * How long a signed-in session lasts. This is a rolling window — every request
 * pushes the deadline out again — so in practice you stay signed in unless you
 * leave the app alone for this long, or sign out.
 *
 * Change this one number to make sessions shorter or longer.
 */
const SESSION_LIFETIME = 30 * 24 * 60 * 60; // 30 days

/** Session files live here rather than in the shared system temp directory. */
const SESSION_DIR = __DIR__ . '/data/sessions';

/**
 * The data directory, created on demand with an Apache deny rule dropped in.
 *
 * The database, the backups and the session files all live under here and none
 * of them should ever be fetchable over HTTP — a readable session file is a
 * working login. The guard is written alongside the directory rather than
 * committed, so it cannot go missing if the directory is recreated by hand.
 */
function data_dir(): string
{
    $dir = __DIR__ . '/data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $guard = $dir . '/.htaccess';
    if (!file_exists($guard) && is_dir($dir) && is_writable($dir)) {
        @file_put_contents($guard, <<<'HTACCESS'
            # The database, its backups and the session files live here. None of
            # them may be served over HTTP. Apache only — on nginx, block this
            # location in the server config instead.
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order allow,deny
                Deny from all
            </IfModule>
            HTACCESS);
    }

    return $dir;
}

/* ---------------------------------------------------------------- session */

/**
 * PHP's defaults are short: session data is collectable after 24 minutes idle,
 * and the cookie is dropped when the browser closes. Both are widened here.
 */
function start_session(): void
{
    // Keep session files in our own directory. The default save path is shared
    // with every other PHP application on the machine, and their garbage
    // collectors delete by *their* gc_maxlifetime, not ours — so a long
    // lifetime set here would be quietly overruled by a neighbour's short one.
    data_dir();
    if (!is_dir(SESSION_DIR)) {
        @mkdir(SESSION_DIR, 0700, true);
    }
    if (is_dir(SESSION_DIR) && is_writable(SESSION_DIR)) {
        session_save_path(SESSION_DIR);

        // A custom save path is not covered by the session-cleanup cron some
        // distributions ship, so let PHP collect this directory itself.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    // Refuse a session id the server never issued, so a long-lived session
    // cannot be fixed in advance by someone handing you a prepared link.
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);

    session_start();

    // PHP sends the session cookie once, at its original expiry. Without this
    // the cookie would still lapse SESSION_LIFETIME after sign-in no matter how
    // active you were; re-sending it on each request makes the window roll.
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}

start_session();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* --------------------------------------------------------------- database */

/**
 * Holder for the shared connection. A plain function-static would do, except
 * that restoring a backup has to drop the handle before replacing the file
 * underneath it — and a static local cannot be reset from outside.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** Drop the handle. Required before replacing the database file on disk. */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    private static function connect(): PDO
    {
    $dir = data_dir();
    if (!is_dir($dir)) {
        throw new RuntimeException('Unable to create data directory: ' . $dir);
    }

    $fresh = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    init_schema($pdo);
    if ($fresh) {
        seed_demo_board($pdo);
    }

    return $pdo;
    }
}

function db(): PDO
{
    return Database::connection();
}

function db_close(): void
{
    Database::disconnect();
}

/** Create tables if they do not exist yet. Safe to run on every request. */
function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS projects (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            sort_order  INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS columns (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            title      TEXT    NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS tasks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            column_id   INTEGER NOT NULL REFERENCES columns(id) ON DELETE CASCADE,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            sort_order  INTEGER NOT NULL DEFAULT 0
        );

        CREATE INDEX IF NOT EXISTS idx_columns_project ON columns(project_id, sort_order);
        CREATE INDEX IF NOT EXISTS idx_tasks_column    ON tasks(column_id, sort_order);
    SQL);

    migrate_schema($pdo);
}

/**
 * Bring an older database up to date. CREATE TABLE IF NOT EXISTS leaves an
 * existing table alone, so columns added after the first release have to be
 * patched in — including into a database that arrives by restoring an old
 * backup, since every connection runs this.
 */
function migrate_schema(PDO $pdo): void
{
    $existing = $pdo->query('PRAGMA table_info(projects)')->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('description', $existing, true)) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN description TEXT NOT NULL DEFAULT ''");
    }
}

/** Populate the four starter boards described in the brief. */
function seed_demo_board(PDO $pdo): void
{
    $projects = [
        'SaaS Project' => [
            'columns' => ['Backlog', 'In Progress', 'Review', 'Done'],
            'description' => 'A side product I want earning enough to cover my own tools and, '
                . 'eventually, buy back a day a week from contract work.',
        ],
        'Woodworking / Plushie Jig' => [
            'columns' => ['To Do', 'In Progress', 'Done'],
            'description' => 'Hands-off-the-keyboard time. The jig makes the plushie runs '
                . 'repeatable so I can make things for people instead of one-off prototypes.',
        ],
        'Decluttering' => [
            'columns' => ['To Do', 'In Progress', 'Done'],
            'description' => 'Clearing the flat room by room so the space stops draining me '
                . 'and I can actually use the spare room as a workshop.',
        ],
        'Japanese Studies' => [
            'columns' => ['To Do', 'In Progress', 'Done'],
            'description' => 'Working towards holding a real conversation on the trip next year. '
                . 'Consistency matters far more here than intensity.',
        ],
    ];

    $tasks = [
        'SaaS Project' => [
            'Backlog'     => [['Sketch onboarding flow', 'Three screens: signup, workspace, invite.'],
                              ['Pick billing provider', '']],
            'In Progress' => [['Build auth endpoints', 'Sessions + password reset.']],
            'Done'        => [['Register domain', '']],
        ],
        'Woodworking / Plushie Jig' => [
            'To Do'       => [['Cut jig base to size', '18mm ply, 400x300mm.'],
                              ['Order T-track', '']],
            'In Progress' => [['Design clamp arms', 'Test fit with the cardboard mock-up first.']],
        ],
        'Decluttering' => [
            'To Do'       => [['Garage shelves', ''], ['Sort cable drawer', 'Keep one of each, bin the rest.']],
            'Done'        => [['Donate old monitors', '']],
        ],
        'Japanese Studies' => [
            'To Do'       => [['Genki chapter 7 vocab', '']],
            'In Progress' => [['Daily Anki reviews', 'Aim for 20 new cards a day.']],
            'Done'        => [['Finish hiragana drills', '']],
        ],
    ];

    $insProject = $pdo->prepare(
        'INSERT INTO projects (title, description, sort_order) VALUES (?, ?, ?)'
    );
    $insColumn  = $pdo->prepare('INSERT INTO columns (project_id, title, sort_order) VALUES (?, ?, ?)');
    $insTask    = $pdo->prepare('INSERT INTO tasks (column_id, title, description, sort_order) VALUES (?, ?, ?, ?)');

    $pdo->beginTransaction();
    $p = 0;
    foreach ($projects as $projectTitle => $spec) {
        $insProject->execute([$projectTitle, $spec['description'], $p++]);
        $projectId = (int) $pdo->lastInsertId();

        foreach (array_values($spec['columns']) as $c => $columnTitle) {
            $insColumn->execute([$projectId, $columnTitle, $c]);
            $columnId = (int) $pdo->lastInsertId();

            foreach ($tasks[$projectTitle][$columnTitle] ?? [] as $t => [$title, $description]) {
                $insTask->execute([$columnId, $title, $description, $t]);
            }
        }
    }
    $pdo->commit();
}

/* ---------------------------------------------------------------- settings */

function setting_get(string $key): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

/** True until the single user has chosen a password (first-run state). */
function needs_setup(): bool
{
    return setting_get('password_hash') === null;
}

/* ------------------------------------------------------------------- auth */

function is_authenticated(): bool
{
    return !empty($_SESSION['authenticated']);
}

function require_auth(): void
{
    if (!is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/* ------------------------------------------------------------------ flash */

function flash_set(string $tone, string $message): void
{
    $_SESSION['flash'] = ['tone' => $tone, 'message' => $message];
}

/** Read and clear the pending flash message, if any. */
function flash_take(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/** "1 project" / "3 projects" — naive, but every noun we pluralise is regular. */
function plural_en(int $count, string $noun): string
{
    return $count . ' ' . $noun . ($count === 1 ? '' : 's');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
