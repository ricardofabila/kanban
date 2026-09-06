<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (needs_setup()) {
    header('Location: login.php');
    exit;
}
require_auth();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kanban</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/styles.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js" defer></script>
<script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/app.js" defer></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="mark">Kanban</span>
    <span class="status" id="status" role="status" aria-live="polite"></span>
  </div>
  <div class="topbar-right">
    <button type="button" class="btn" id="add-project">New project</button>
    <a class="btn btn-quiet" href="plan.php">Plan my week</a>
    <a class="btn btn-quiet" href="backups.php">Backups</a>
    <a class="btn btn-quiet" href="logout.php">Sign out</a>
  </div>
</header>

<main id="board" class="board" aria-busy="true">
  <p class="board-loading">Loading board…</p>
</main>

<!-- Undo toasts. The container ignores pointer events so it can span the
     viewport without swallowing clicks on the board underneath. -->
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>

<!-- Task editor -->
<dialog id="task-dialog" class="dialog">
  <form method="dialog" id="task-form">
    <h2 id="task-dialog-title">Edit task</h2>

    <label class="field">
      <span>Title</span>
      <input type="text" name="title" maxlength="200" required>
    </label>

    <label class="field">
      <span>Description</span>
      <textarea name="description" rows="5" maxlength="4000"
                placeholder="Optional notes…"></textarea>
    </label>

    <menu class="dialog-actions">
      <button type="button" class="btn btn-danger" id="task-delete">Delete</button>
      <span class="spacer"></span>
      <button type="button" class="btn btn-quiet" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary">Save</button>
    </menu>
  </form>
</dialog>

<!-- Project editor: title plus the "why", which feeds the weekly plan prompt -->
<dialog id="project-dialog" class="dialog">
  <form method="dialog" id="project-form">
    <h2 id="project-dialog-title">Edit project</h2>

    <label class="field">
      <span>Project name</span>
      <input type="text" name="title" maxlength="120" required>
    </label>

    <label class="field">
      <span>What is this project for?</span>
      <textarea name="description" rows="4" maxlength="2000"
                placeholder="What it is for, and what it will do for you once it is done. This is what the weekly plan is built on."></textarea>
      <small class="field-hint">Used on the <a href="plan.php">Plan my week</a> page.</small>
    </label>

    <menu class="dialog-actions">
      <span class="spacer"></span>
      <button type="button" class="btn btn-quiet" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" id="project-save">Save</button>
    </menu>
  </form>
</dialog>

<!-- Single-line prompt (rename, add) -->
<dialog id="prompt-dialog" class="dialog">
  <form method="dialog" id="prompt-form">
    <h2 id="prompt-title">Rename</h2>
    <label class="field">
      <span id="prompt-label">Title</span>
      <input type="text" name="value" maxlength="200" required>
    </label>
    <menu class="dialog-actions">
      <span class="spacer"></span>
      <button type="button" class="btn btn-quiet" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" id="prompt-ok">Save</button>
    </menu>
  </form>
</dialog>

<!-- Destructive confirmation -->
<dialog id="confirm-dialog" class="dialog">
  <form method="dialog">
    <h2 id="confirm-title">Are you sure?</h2>
    <p class="dialog-body" id="confirm-body"></p>
    <menu class="dialog-actions">
      <span class="spacer"></span>
      <button type="button" class="btn btn-quiet" data-close>Cancel</button>
      <button type="button" class="btn btn-danger" id="confirm-ok">Delete</button>
    </menu>
  </form>
</dialog>

</body>
</html>
