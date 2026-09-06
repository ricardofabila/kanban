<?php
declare(strict_types=1);

/** Backups page: snapshot the database, restore from a snapshot, prune old ones. */

require __DIR__ . '/config.php';
require __DIR__ . '/backup-store.php';

if (needs_setup()) {
    header('Location: login.php');
    exit;
}
require_auth();

/* ------------------------------------------------------- POST / redirect / GET */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash_set('error', 'Your session expired. Please try again.');
        header('Location: backups.php');
        exit;
    }

    $name = (string) ($_POST['name'] ?? '');

    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                flash_set('ok', 'Backup created — ' . backup_create() . '.');
                break;

            case 'restore':
                $safety = backup_restore($name);
                flash_set('ok', "Restored from $name. The database it replaced was saved as $safety.");
                break;

            case 'delete':
                backup_delete($name);
                flash_set('ok', "Deleted $name.");
                break;

            default:
                flash_set('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: backups.php');
    exit;
}

$flash   = flash_take();
$backups = backup_list();

$liveSize = is_file(DB_PATH) ? (int) filesize(DB_PATH) : 0;
$counts   = [
    'projects' => (int) db()->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
    'columns'  => (int) db()->query('SELECT COUNT(*) FROM columns')->fetchColumn(),
    'tasks'    => (int) db()->query('SELECT COUNT(*) FROM tasks')->fetchColumn(),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backups · Kanban</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/styles.css">
<script src="assets/backups.js" defer></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="mark">Kanban</span>
    <span class="crumb">Backups</span>
  </div>
  <div class="topbar-right">
    <a class="btn btn-quiet" href="index.php">&larr; Board</a>
    <a class="btn btn-quiet" href="logout.php">Sign out</a>
  </div>
</header>

<main class="page">

  <?php if ($flash !== null): ?>
    <p class="flash flash-<?= h($flash['tone']) ?>" role="status"><?= h($flash['message']) ?></p>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h1 class="panel-title">Backups</h1>
        <p class="panel-sub">
          Each backup is a complete copy of the database, written to
          <code>data/backups/</code>. Live database:
          <?= h(format_bytes($liveSize)) ?> —
          <?= h(plural_en($counts['projects'], 'project')) ?>,
          <?= h(plural_en($counts['columns'], 'column')) ?>,
          <?= h(plural_en($counts['tasks'], 'card')) ?>.
        </p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn btn-primary">Back up now</button>
      </form>
    </div>

    <?php if ($backups === []): ?>
      <div class="empty-state">
        <h2>No backups yet</h2>
        <p>Take one now and it will show up here.</p>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Created</th>
              <th scope="col">File</th>
              <th scope="col">Kind</th>
              <th scope="col" class="num">Size</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backups as $index => $backup): ?>
              <tr>
                <td>
                  <span class="cell-strong"><?= h(format_when($backup['time'])) ?></span>
                  <?php if ($index === 0): ?><span class="pill">Latest</span><?php endif; ?>
                  <?php // The server may not share the reader's timezone, so ship the
                        // instant and let the browser render it locally. ?>
                  <time class="cell-muted stamp" datetime="<?= h(date('c', $backup['time'])) ?>"
                    ><?= h(date('j M Y, H:i T', $backup['time'])) ?></time>
                </td>
                <td class="cell-file"><code class="filename"><?= h($backup['name']) ?></code></td>
                <td><?= h($backup['kind']) ?></td>
                <td class="num"><?= h(format_bytes($backup['size'])) ?></td>
                <td class="row-actions">
                  <form method="post"
                        data-confirm-title="Restore from this backup?"
                        data-confirm-body="The live database is replaced with the contents of <?= h($backup['name']) ?>. A copy of the current database is saved first, and your password stays as it is."
                        data-confirm-ok="Restore">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="name" value="<?= h($backup['name']) ?>">
                    <button type="submit" class="btn btn-small">Restore</button>
                  </form>
                  <form method="post"
                        data-confirm-title="Delete this backup?"
                        data-confirm-body="<?= h($backup['name']) ?> is removed from disk. This cannot be undone."
                        data-confirm-ok="Delete"
                        data-confirm-danger="1">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="name" value="<?= h($backup['name']) ?>">
                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<dialog id="confirm-dialog" class="dialog">
  <h2 id="confirm-title">Are you sure?</h2>
  <p class="dialog-body" id="confirm-body"></p>
  <menu class="dialog-actions">
    <span class="spacer"></span>
    <button type="button" class="btn btn-quiet" data-close>Cancel</button>
    <button type="button" class="btn btn-primary" id="confirm-ok">Confirm</button>
  </menu>
</dialog>

</body>
</html>
