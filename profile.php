<?php
/**
 * profile.php - onboarding questions for new users AND profile editing.
 * Answers drive the goals shown on the dashboard.
 */
require_once 'includes/bootstrap.php';
$uid = require_login();

$existing = get_profile($pdo, $uid);
$isNew    = $existing === null;
$errors   = [];
$f = $existing ?: ['height_cm' => '', 'weight_kg' => '', 'target_weight_kg' => '',
                   'goal_type' => 'general_fitness', 'days_per_week' => 4, 'weekly_km_goal' => ''];

/** Validate a numeric field within [min,max]; returns float or null. */
$num = function (mixed $v, float $min, float $max): ?float {
    $x = filter_var($v, FILTER_VALIDATE_FLOAT);
    return ($x !== false && $x >= $min && $x <= $max) ? (float)$x : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));

    $height = $num($f['height_cm'], 100, 250);
    $weight = $num($f['weight_kg'], 30, 300);
    $target = $f['target_weight_kg'] === '' ? null : $num($f['target_weight_kg'], 30, 300);
    $days   = filter_var($f['days_per_week'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
    $goal   = $f['goal_type'];
    $km     = $f['weekly_km_goal'] === '' ? (float)(GOAL_DEFAULT_KM[$goal] ?? 0) : $num($f['weekly_km_goal'], 0, 500);

    if ($height === null) $errors[] = 'Height must be between 100 and 250 cm.';
    if ($weight === null) $errors[] = 'Weight must be between 30 and 300 kg.';
    if ($f['target_weight_kg'] !== '' && $target === null) $errors[] = 'Target weight must be between 30 and 300 kg.';
    if ($days === false)  $errors[] = 'Choose 1-7 training days per week.';
    if (!isset(GOALS[$goal])) $errors[] = 'Choose a main goal.';
    if ($km === null)     $errors[] = 'Weekly running goal must be 0-500 km.';

    if (!$errors) {
        // start_weight_kg is the baseline for progress: set on first save, or when "restart" is ticked.
        $restart = (int)($isNew || isset($_POST['restart']));
        $pdo->prepare(
            'INSERT INTO user_profiles (user_id, height_cm, weight_kg, start_weight_kg, target_weight_kg, goal_type, days_per_week, weekly_km_goal)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm), weight_kg = VALUES(weight_kg),
               target_weight_kg = VALUES(target_weight_kg), goal_type = VALUES(goal_type),
               days_per_week = VALUES(days_per_week), weekly_km_goal = VALUES(weekly_km_goal),
               start_weight_kg = IF(? = 1, VALUES(weight_kg), start_weight_kg)'
        )->execute([$uid, $height, $weight, $weight, $target, $goal, $days, $km, $restart]);

        flash($isNew ? 'Goals set. Let’s get to work.' : 'Profile updated.');
        header('Location: dashboard.php'); exit;
    }
}

$pageTitle = $isNew ? 'Set your goals' : 'Profile';
include 'includes/header.php';
?>
<h1><?= $isNew ? 'Let’s set your goals' : 'Your profile' ?></h1>
<p class="hint"><?= $isNew ? 'A few quick questions. You can change any of this later.' : 'Update your stats and targets anytime.' ?></p>

<form method="post" class="card stack" id="profile-form">
  <?= csrf_field() ?>
  <?php foreach ($errors as $err): ?><p class="error"><?= e($err) ?></p><?php endforeach; ?>

  <div class="goal-picker" role="radiogroup" aria-label="Main goal">
    <?php foreach (GOALS as $key => $label): ?>
      <label class="pill">
        <input type="radio" name="goal_type" value="<?= $key ?>" data-km="<?= GOAL_DEFAULT_KM[$key] ?>" <?= $f['goal_type'] === $key ? 'checked' : '' ?>>
        <span><em><?= e($label) ?></em></span>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="grid2">
    <label>Height (cm) <input type="number" name="height_cm" step="0.1" min="100" max="250" value="<?= e($f['height_cm']) ?>" required></label>
    <label>Current weight (kg) <input type="number" name="weight_kg" step="0.1" min="30" max="300" value="<?= e($f['weight_kg']) ?>" required></label>
    <label>Target weight (kg, optional) <input type="number" name="target_weight_kg" step="0.1" min="30" max="300" value="<?= e($f['target_weight_kg']) ?>"></label>
    <label>Training days per week
      <select name="days_per_week">
        <?php for ($i = 1; $i <= 7; $i++): ?>
          <option value="<?= $i ?>" <?= (int)$f['days_per_week'] === $i ? 'selected' : '' ?>><?= $i ?> day<?= $i > 1 ? 's' : '' ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label>Weekly running goal (km)
      <input type="number" name="weekly_km_goal" id="km-goal" step="0.5" min="0" max="500"
             value="<?= e($f['weekly_km_goal']) ?>" placeholder="Auto from your goal">
    </label>
  </div>

  <?php if (!$isNew): ?>
    <label class="check"><input type="checkbox" name="restart"> Restart weight progress from my current weight</label>
  <?php endif; ?>

  <div class="row-between">
    <button class="btn btn-accent"><?= $isNew ? 'Save &amp; continue' : 'Save changes' ?></button>
    <?php if (!$isNew): ?><a class="link" href="dashboard.php">Cancel</a><?php endif; ?>
  </div>
</form>
<?php include 'includes/footer.php'; ?>
