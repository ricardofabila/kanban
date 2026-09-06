<?php
declare(strict_types=1);

/**
 * Reading the board. Shared by the JSON API and the weekly-plan page, which
 * both need the same nested projects → columns → tasks structure.
 */

/** The whole board as nested projects → columns → tasks. */
function load_board(PDO $pdo): array
{
    $projects = $pdo->query(
        'SELECT id, title, description, sort_order FROM projects ORDER BY sort_order, id'
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
            'id'          => $id,
            'title'       => $project['title'],
            'description' => $project['description'],
            'columns'     => $columnsByProject[$id] ?? [],
        ];
    }, $projects);
}
