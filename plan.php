<?php
declare(strict_types=1);

/** Builds a prompt from the board that you can paste into an AI to plan the week. */

require __DIR__ . '/config.php';
require __DIR__ . '/board.php';
require __DIR__ . '/plan-prompt.php';

if (needs_setup()) {
    header('Location: login.php');
    exit;
}
require_auth();

$options = [
    'hours' => mb_substr(trim((string) ($_GET['hours'] ?? '')), 0, 120),
    'notes' => mb_substr(trim((string) ($_GET['notes'] ?? '')), 0, 500),
];

$projects = load_board(db());
$prompt   = plan_build_prompt($projects, $options);

$missing = array_values(array_filter(
    $projects,
    static fn(array $project) => trim((string) $project['description']) === ''
));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plan my week · Kanban</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/styles.css">
<script src="assets/plan.js" defer></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <span class="mark">Kanban</span>
    <span class="crumb">Plan my week</span>
  </div>
  <div class="topbar-right">
    <a class="btn btn-quiet" href="index.php">&larr; Board</a>
    <a class="btn btn-quiet" href="backups.php">Backups</a>
    <a class="btn btn-quiet" href="logout.php">Sign out</a>
  </div>
</header>

<main class="page">

  <?php if ($missing !== []): ?>
    <p class="flash flash-warn">
      <?= h(plural_en(count($missing), 'project')) ?>
      <?= count($missing) === 1 ? 'has' : 'have' ?> no description yet —
      <?= h(implode(', ', array_column($missing, 'title'))) ?>.
      The plan is only as good as what you tell it these are for.
      <a href="index.php">Add them on the board</a>.
    </p>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h1 class="panel-title">Plan my week</h1>
        <p class="panel-sub">
          Built from every project on your board — what each one is for, and where it
          stands right now. Copy it into whichever assistant you use.
        </p>
      </div>
      <button type="button" class="btn btn-primary" id="copy-prompt" data-label="Copy prompt">
        Copy prompt
      </button>
    </div>

    <form class="plan-options" method="get">
      <label class="field">
        <span>Time you have this week</span>
        <input type="text" name="hours" maxlength="120" value="<?= h($options['hours']) ?>"
               placeholder="about 8 hours, mostly evenings">
      </label>
      <label class="field">
        <span>Anything else going on?</span>
        <input type="text" name="notes" maxlength="500" value="<?= h($options['notes']) ?>"
               placeholder="away Thursday and Friday">
      </label>
      <button type="submit" class="btn">Update prompt</button>
    </form>

    <div class="prompt-wrap">
      <textarea id="prompt-text" class="prompt-text" readonly rows="24"
                spellcheck="false" aria-label="Generated prompt"><?= h($prompt) ?></textarea>
      <p class="prompt-meta">
        <?= h(plural_en(mb_strlen($prompt), 'character')) ?> ·
        regenerated from the board every time you open this page.
      </p>
    </div>
  </section>
</main>

</body>
</html>
