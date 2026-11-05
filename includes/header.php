<?php
/* Shared layout top. Set $pageTitle before including. */
$current = basename($_SERVER['SCRIPT_NAME']);
$nav = ['dashboard.php' => 'Dashboard', 'log_workout.php' => 'Log', 'history.php' => 'History', 'profile.php' => 'Profile'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f1115">
  <title><?= e($pageTitle ?? 'Tracker') ?> · Hybrid Tracker</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="dashboard.php"><span class="dot"></span>Hybrid</a>
  <?php if (!empty($_SESSION['user_id'])): ?>
  <nav aria-label="Main">
    <?php foreach ($nav as $file => $label): ?>
      <a href="<?= $file ?>" <?= $current === $file ? 'class="active" aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a class="muted-link" href="logout.php">Log out</a>
  </nav>
  <?php endif; ?>
</header>
<main class="container">
<?php if ($m = flash()): ?><div class="flash"><?= e($m) ?></div><?php endif; ?>
