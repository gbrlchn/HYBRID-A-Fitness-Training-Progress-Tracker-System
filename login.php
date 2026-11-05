<?php
require_once 'includes/bootstrap.php';
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?');
    $login = trim($_POST['login'] ?? '');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);               // prevent session fixation
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: dashboard.php'); exit;
    }
    $error = 'Invalid credentials.';              // deliberately vague
}
$pageTitle = 'Login';
include 'includes/header.php';
?>
<section class="card narrow">
  <h1>Login</h1>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username or email <input name="login" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn">Enter</button>
  </form>
  <p>New here? <a href="register.php">Register</a></p>
</section>
<?php include 'includes/footer.php'; ?>
