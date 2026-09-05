<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

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

/** The whole board as nested projects → columns → tasks. */
function load_board(PDO $pdo): array
{
    $projects = $pdo->query(
        'SELECT id, title, sort_order FROM projects ORDER BY sort_order, id'
    )->fetchAll();

    $columns = $pdo->query(
        'SELECT id, project_id, title, sort_order FROM columns ORDER BY sort_order, id'
    )->fetchAll();

    $tasks = $pdo->query(
        'SELECT id, column_id, title, description, sort_order FROM tasks ORDER BY sort_order, id'
    )->fetchAll();

    $tasksByColumn = [];
    foreach ($tasks as $task) {
        $tasksByColumn[(int) $task['column_id']][] = [
            'id'          => (int) $task['id'],
            'column_id'   => (int) $task['column_id'],
            'title'       => $task['title'],
            'description' => $task['description'],
        ];
    }

    $columnsByProject = [];
    foreach ($columns as $column) {
        $id = (int) $column['id'];
        $columnsByProject[(int) $column['project_id']][] = [
            'id'         => $id,
            'project_id' => (int) $column['project_id'],
            'title'      => $column['title'],
            'tasks'      => $tasksByColumn[$id] ?? [],
        ];
    }

    return array_map(static function (array $project) use ($columnsByProject): array {
        $id = (int) $project['id'];
        return [
            'id'      => $id,
            'title'   => $project['title'],
            'columns' => $columnsByProject[$id] ?? [],
        ];
    }, $projects);
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
        $stmt  = $pdo->prepare('INSERT INTO projects (title, sort_order) VALUES (?, ?)');
        $stmt->execute([$title, next_order($pdo, 'projects')]);
        $id = (int) $pdo->lastInsertId();

        // A board with no columns cannot hold anything — start with the usual three.
        $insColumn = $pdo->prepare('INSERT INTO columns (project_id, title, sort_order) VALUES (?, ?, ?)');
        foreach (['To Do', 'In Progress', 'Done'] as $i => $columnTitle) {
            $insColumn->execute([$id, $columnTitle, $i]);
        }
        respond(['ok' => true, 'id' => $id, 'projects' => load_board($pdo)]);
    }

    case 'project.rename': {
        $stmt = $pdo->prepare('UPDATE projects SET title = ? WHERE id = ?');
        $stmt->execute([str_field('title', 120), id_field('id')]);
        respond(['ok' => true]);
    }

    case 'project.delete': {
        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([id_field('id')]);
        respond(['ok' => true]);
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
        $stmt = $pdo->prepare('DELETE FROM columns WHERE id = ?');
        $stmt->execute([id_field('id')]);
        respond(['ok' => true]);
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
        $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ?');
        $stmt->execute([id_field('id')]);
        respond(['ok' => true]);
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
