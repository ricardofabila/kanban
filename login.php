<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

$setup  = needs_setup();
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($setup) {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Choose a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } else {
            setting_set('password_hash', password_hash($password, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        }
    } else {
        // Constant-ish delay to blunt brute-force attempts against the single account.
        usleep(300_000);
        $hash = (string) setting_get('password_hash');

        if (password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                setting_set('password_hash', password_hash((string) $_POST['password'], PASSWORD_DEFAULT));
            }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        }
        $error = 'Incorrect password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $setup ? 'Set a password' : 'Sign in' ?> · Kanban</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-body">
  <main class="login-card">
    <h1 class="login-mark">Kanban</h1>
    <p class="login-sub">
      <?= $setup
          ? 'First run — choose the password you will use to unlock this board.'
          : 'Enter your password to open the board.' ?>
    </p>

    <?php if ($error !== null): ?>
      <p class="login-error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <label class="field">
        <span>Password</span>
        <input type="password" name="password" required autofocus
               autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">
      </label>

      <?php if ($setup): ?>
        <label class="field">
          <span>Confirm password</span>
          <input type="password" name="confirm" required autocomplete="new-password">
        </label>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary btn-block">
        <?= $setup ? 'Create password' : 'Sign in' ?>
      </button>
    </form>
  </main>
</body>
</html>
