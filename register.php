<?php
require_once 'includes/bootstrap.php';
$errors = []; $username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) $errors[] = 'Username: 3-30 letters, numbers or underscores.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))       $errors[] = 'Enter a valid email.';
    if (strlen($password) < 8)                            $errors[] = 'Password must be at least 8 characters.';

    if (!$errors) {
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $errors[] = 'Username or email already registered.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?,?,?)')
                ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $uid = (int)$pdo->lastInsertId();

            // Seed a default hybrid split the user can edit on the dashboard.
            $default = [1 => 'Upper Body (Gym)', 2 => 'Calisthenics', 3 => 'Run',
                        4 => 'Upper Body (Gym)', 5 => 'Calisthenics', 6 => 'Long Run', 7 => 'Rest'];
            $ins = $pdo->prepare('INSERT INTO weekly_schedule (user_id, day_of_week, focus) VALUES (?,?,?)');
            foreach ($default as $d => $f) $ins->execute([$uid, $d, $f]);
            $pdo->commit();

            // Log the new user in and continue to the goal questions.
            session_regenerate_id(true);
            $_SESSION['user_id']  = $uid;
            $_SESSION['username'] = $username;
            flash('Welcome! Answer a few questions so we can set your goals.');
            header('Location: profile.php'); exit;
        }
    }
}
$pageTitle = 'Register';
include 'includes/header.php';
?>
<section class="card narrow">
  <h1>Register</h1>
  <?php foreach ($errors as $err): ?><p class="error"><?= e($err) ?></p><?php endforeach; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username <input name="username" value="<?= e($username) ?>" required></label>
    <label>Email <input type="email" name="email" value="<?= e($email) ?>" required></label>
    <label>Password (min 8) <input type="password" name="password" required></label>
    <button class="btn">Create account</button>
  </form>
  <p>Have an account? <a href="login.php">Log in</a></p>
</section>
<?php include 'includes/footer.php'; ?>
