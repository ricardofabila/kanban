<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/board.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Emit a JSON payload and stop. */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

if (!is_authenticated()) {
    fail('Not authenticated.', 401);
}

/* ------------------------------------------------------------ input model */

function body(): array
{
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        $body = is_array($data) ? $data : [];
    }
    return $body;
}

function str_field(string $key, int $maxLength, bool $required = true): string
{
    $value = body()[$key] ?? '';
    if (!is_string($value)) {
        fail("Field '$key' must be a string.");
    }
    $value = trim(preg_replace('/\R/u', "\n", $value) ?? '');
    if ($required && $value === '') {
        fail("Field '$key' is required.");
    }
    return mb_substr($value, 0, $maxLength);
}

function id_field(string $key): int
{
    $value = body()[$key] ?? null;
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
        fail("Field '$key' must be an id.");
    }
    $id = (int) $value;
    if ($id <= 0) {
        fail("Field '$key' must be a positive id.");
    }
    return $id;
}

/** Read a list of positive integer ids from the request body. */
function id_list(mixed $raw, string $label): array
{
    if (!is_array($raw)) {
        fail("Field '$label' must be an array of ids.");
    }
    $ids = [];
    foreach ($raw as $value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            fail("Field '$label' contains a non-numeric id.");
        }
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/* ------------------------------------------------------------ board state */

/**
 * Row mappers for undo snapshots. A delete returns every row it removed, in
 * full, so `restore` can put them back exactly where they were.
 */
function project_row(array $row): array
{
    return [
        'id'          => (int) $row['id'],
        'title'       => $row['title'],
        'description' => $row['description'],
        'sort_order'  => (int) $row['sort_order'],
    ];
}

function column_row(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'title'      => $row['title'],
        'sort_order' => (int) $row['sort_order'],
    ];
}

/** A complete task row, including sort_order — what an undo needs to put it back. */
function task_row(array $row): array
{
    return [
        'id'          => (int) $row['id'],
        'column_id'   => (int) $row['column_id'],
        'title'       => $row['title'],
        'description' => $row['description'],
        'sort_order'  => (int) $row['sort_order'],
    ];
}

/**
 * Shared id/title/sort_order validation for a row being restored.
 * `$bail` rolls the transaction back and fails the request.
 */
function restore_fields(mixed $row, int $maxTitle, callable $bail): array
{
    if (!is_array($row)) {
        $bail('Each row to restore must be an object.');
    }

    $id    = (int) ($row['id'] ?? 0);
    $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';

    if ($id <= 0 || $title === '') {
        $bail('A row to restore is missing its id or title.');
    }

    return [$id, mb_substr($title, 0, $maxTitle), max(0, (int) ($row['sort_order'] ?? 0))];
}

/** Next sort_order for a new row at the end of a list. */
function next_order(PDO $pdo, string $table, ?string $parentColumn = null, ?int $parentId = null): int
{
    $sql = "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM $table";
    $params = [];
    if ($parentColumn !== null) {
        $sql .= " WHERE $parentColumn = ?";
        $params[] = $parentId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function row_exists(PDO $pdo, string $table, int $id): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

/* ------------------------------------------------------------------ routes */

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['ok' => true, 'projects' => load_board($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    fail('Method not allowed.', 405);
}

if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (body()['csrf'] ?? null))) {
    fail('Invalid CSRF token.', 419);
}

$action = body()['action'] ?? '';
if (!is_string($action) || $action === '') {
    fail('Missing action.');
}

switch ($action) {

    /* ------------------------------------------------------------ projects */

    case 'project.create': {
        $title = str_field('title', 120);
        $stmt  = $pdo->prepare(
            'INSERT INTO projects (title, description, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$title, str_field('description', 2000, false), next_order($pdo, 'projects')]);
        $id = (int) $pdo->lastInsertId();

        // A board with no columns cannot hold anything — start with the usual three.
        $insColumn = $pdo->prepare('INSERT INTO columns (project_id, title, sort_order) VALUES (?, ?, ?)');
        foreach (['To Do', 'In Progress', 'Done'] as $i => $columnTitle) {
            $insColumn->execute([$id, $columnTitle, $i]);
        }
        respond(['ok' => true, 'id' => $id, 'projects' => load_board($pdo)]);
    }

    case 'project.update': {
        $stmt = $pdo->prepare('UPDATE projects SET title = ?, description = ? WHERE id = ?');
        $stmt->execute([
            str_field('title', 120),
            str_field('description', 2000, false),
            id_field('id'),
        ]);
        respond(['ok' => true]);
    }

    case 'project.delete': {
        $id = id_field('id');

        $stmt = $pdo->prepare('SELECT id, title, description, sort_order FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if ($project === false) {
            fail('That project no longer exists.', 404);
        }

        $stmt = $pdo->prepare(
            'SELECT id, project_id, title, sort_order
             FROM columns WHERE project_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$id]);
        $columns = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT t.id, t.column_id, t.title, t.description, t.sort_order
             FROM tasks t JOIN columns c ON c.id = t.column_id
             WHERE c.project_id = ? ORDER BY t.column_id, t.sort_order, t.id'
        );
        $stmt->execute([$id]);
        $tasks = $stmt->fetchAll();

        // The cascade takes the columns and tasks with it.
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);

        respond(['ok' => true, 'snapshot' => [
            'projects' => [project_row($project)],
            'columns'  => array_map('column_row', $columns),
            'tasks'    => array_map('task_row', $tasks),
        ]]);
    }

    case 'project.reorder': {
        $ids  = id_list(body()['ids'] ?? null, 'ids');
        $stmt = $pdo->prepare('UPDATE projects SET sort_order = ? WHERE id = ?');
        $pdo->beginTransaction();
        foreach ($ids as $order => $id) {
            $stmt->execute([$order, $id]);
        }
        $pdo->commit();
        respond(['ok' => true]);
    }

    /* ------------------------------------------------------------- columns */

    case 'column.create': {
        $projectId = id_field('project_id');
        if (!row_exists($pdo, 'projects', $projectId)) {
            fail('That project no longer exists.', 404);
        }
        $stmt = $pdo->prepare('INSERT INTO columns (project_id, title, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([
            $projectId,
            str_field('title', 80),
            next_order($pdo, 'columns', 'project_id', $projectId),
        ]);
        respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    case 'column.rename': {
        $stmt = $pdo->prepare('UPDATE columns SET title = ? WHERE id = ?');
        $stmt->execute([str_field('title', 80), id_field('id')]);
        respond(['ok' => true]);
    }

    case 'column.delete': {
        $id = id_field('id');

        $stmt = $pdo->prepare('SELECT id, project_id, title, sort_order FROM columns WHERE id = ?');
        $stmt->execute([$id]);
        $column = $stmt->fetch();
        if ($column === false) {
            fail('That column no longer exists.', 404);
        }

        $stmt = $pdo->prepare(
            'SELECT id, column_id, title, description, sort_order
             FROM tasks WHERE column_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$id]);
        $tasks = $stmt->fetchAll();

        $pdo->prepare('DELETE FROM columns WHERE id = ?')->execute([$id]);

        respond(['ok' => true, 'snapshot' => [
            'columns' => [column_row($column)],
            'tasks'   => array_map('task_row', $tasks),
        ]]);
    }

    case 'column.reorder': {
        $projectId = id_field('project_id');
        $ids       = id_list(body()['ids'] ?? null, 'ids');
        // Scoping the update by project_id keeps a stray id from reordering another board.
        $stmt = $pdo->prepare('UPDATE columns SET sort_order = ? WHERE id = ? AND project_id = ?');
        $pdo->beginTransaction();
        foreach ($ids as $order => $id) {
            $stmt->execute([$order, $id, $projectId]);
        }
        $pdo->commit();
        respond(['ok' => true]);
    }

    /** Empty a column in one go, returning the removed rows so the UI can offer undo. */
    case 'column.clear': {
        $columnId = id_field('column_id');
        if (!row_exists($pdo, 'columns', $columnId)) {
            fail('That column no longer exists.', 404);
        }
        $stmt = $pdo->prepare(
            'SELECT id, column_id, title, description, sort_order
             FROM tasks WHERE column_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$columnId]);
        $removed = array_map('task_row', $stmt->fetchAll());

        $pdo->prepare('DELETE FROM tasks WHERE column_id = ?')->execute([$columnId]);
        respond(['ok' => true, 'snapshot' => ['tasks' => $removed]]);
    }

    /* --------------------------------------------------------------- tasks */

    case 'task.create': {
        $columnId = id_field('column_id');
        if (!row_exists($pdo, 'columns', $columnId)) {
            fail('That column no longer exists.', 404);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO tasks (column_id, title, description, sort_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $columnId,
            str_field('title', 200),
            str_field('description', 4000, false),
            next_order($pdo, 'tasks', 'column_id', $columnId),
        ]);
        respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    case 'task.update': {
        $stmt = $pdo->prepare('UPDATE tasks SET title = ?, description = ? WHERE id = ?');
        $stmt->execute([
            str_field('title', 200),
            str_field('description', 4000, false),
            id_field('id'),
        ]);
        respond(['ok' => true]);
    }

    case 'task.delete': {
        $id   = id_field('id');
        $stmt = $pdo->prepare(
            'SELECT id, column_id, title, description, sort_order FROM tasks WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            fail('That task no longer exists.', 404);
        }

        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        respond(['ok' => true, 'snapshot' => ['tasks' => [task_row($row)]]]);
    }

    /* ------------------------------------------------------------ undo */

    /**
     * Undo any delete. Takes the snapshot the delete returned and writes those
     * rows back with their original ids and sort_order, so restored items land
     * exactly where they were.
     *
     * Parents go in before children so each child's existence check can see
     * them. Projects and columns use OR IGNORE rather than OR REPLACE: REPLACE
     * deletes the old row first, and on a parent that would cascade away the
     * children this same call is restoring. Tasks are leaves, so REPLACE is
     * safe there and keeps a double-tapped Undo idempotent.
     */
    case 'restore': {
        $snapshot = body()['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            fail("Field 'snapshot' must be an object.");
        }

        $projects = is_array($snapshot['projects'] ?? null) ? $snapshot['projects'] : [];
        $columns  = is_array($snapshot['columns']  ?? null) ? $snapshot['columns']  : [];
        $tasks    = is_array($snapshot['tasks']    ?? null) ? $snapshot['tasks']    : [];

        if ($projects === [] && $columns === [] && $tasks === []) {
            fail('There is nothing to restore.');
        }

        $insProject = $pdo->prepare(
            'INSERT OR IGNORE INTO projects (id, title, description, sort_order) VALUES (?, ?, ?, ?)'
        );
        $insColumn = $pdo->prepare(
            'INSERT OR IGNORE INTO columns (id, project_id, title, sort_order) VALUES (?, ?, ?, ?)'
        );
        $insTask = $pdo->prepare(
            'INSERT OR REPLACE INTO tasks (id, column_id, title, description, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );

        $bail = function (string $message, int $status = 400) use ($pdo): never {
            $pdo->rollBack();
            fail($message, $status);
        };

        $pdo->beginTransaction();

        foreach ($projects as $row) {
            [$id, $title, $order] = restore_fields($row, 120, $bail);
            $description = is_string($row['description'] ?? null)
                ? mb_substr($row['description'], 0, 2000)
                : '';
            $insProject->execute([$id, $title, $description, $order]);
        }

        foreach ($columns as $row) {
            [$id, $title, $order] = restore_fields($row, 80, $bail);
            $projectId = (int) ($row['project_id'] ?? 0);
            if ($projectId <= 0 || !row_exists($pdo, 'projects', $projectId)) {
                $bail('The project these columns belonged to no longer exists.', 409);
            }
            $insColumn->execute([$id, $projectId, $title, $order]);
        }

        foreach ($tasks as $row) {
            [$id, $title, $order] = restore_fields($row, 200, $bail);
            $columnId = (int) ($row['column_id'] ?? 0);
            if ($columnId <= 0 || !row_exists($pdo, 'columns', $columnId)) {
                $bail('The column these cards belonged to no longer exists.', 409);
            }
            $description = is_string($row['description'] ?? null)
                ? mb_substr($row['description'], 0, 4000)
                : '';
            $insTask->execute([$id, $columnId, $title, $description, $order]);
        }

        $pdo->commit();

        respond(['ok' => true, 'projects' => load_board($pdo)]);
    }

    /**
     * Persist a drag: `lists` carries every column the drag touched, each with
     * its full, final task order. One round trip covers both same-column
     * reordering and a move between columns.
     */
    case 'task.reorder': {
        $lists = body()['lists'] ?? null;
        if (!is_array($lists) || $lists === []) {
            fail("Field 'lists' must be a non-empty array.");
        }

        $stmt = $pdo->prepare('UPDATE tasks SET column_id = ?, sort_order = ? WHERE id = ?');
        $pdo->beginTransaction();
        foreach ($lists as $list) {
            if (!is_array($list)) {
                $pdo->rollBack();
                fail("Each entry of 'lists' must be an object.");
            }
            $columnId = (int) ($list['column_id'] ?? 0);
            if ($columnId <= 0 || !row_exists($pdo, 'columns', $columnId)) {
                $pdo->rollBack();
                fail('Unknown column in reorder payload.', 404);
            }
            foreach (id_list($list['task_ids'] ?? null, 'task_ids') as $order => $taskId) {
                $stmt->execute([$columnId, $order, $taskId]);
            }
        }
        $pdo->commit();
        respond(['ok' => true]);
    }

    default:
        fail('Unknown action: ' . $action, 404);
}
