<?php
/**
 * dashboard.php - hero, goals progress, weekly split, recent activity.
 * Users without a profile are sent to profile.php (onboarding) first.
 */
require_once 'includes/bootstrap.php';
$uid = require_login();

$profile = get_profile($pdo, $uid);
if (!$profile) { header('Location: profile.php'); exit; }

// --- POST: quick weight update OR weekly split save ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['form'] ?? '') === 'weight') {
        $w = filter_var($_POST['weight_kg'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($w !== false && $w >= 30 && $w <= 300) {
            $pdo->prepare('UPDATE user_profiles SET weight_kg = ? WHERE user_id = ?')->execute([$w, $uid]);
            flash('Weight updated.');
        } else {
            flash('Enter a weight between 30 and 300 kg.');
        }
    } else {
        $up = $pdo->prepare(
            'INSERT INTO weekly_schedule (user_id, day_of_week, focus) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE focus = VALUES(focus)'
        );
        foreach (WEEKDAYS as $d => $_) {
            $focus = trim($_POST['focus'][$d] ?? '') ?: 'Rest';
            $up->execute([$uid, $d, mb_substr($focus, 0, 100)]);
        }
        flash('Weekly split updated.');
    }
    header('Location: dashboard.php'); exit;
}

// --- Weekly schedule -----------------------------------------------------------
$stmt = $pdo->prepare('SELECT day_of_week, focus FROM weekly_schedule WHERE user_id = ?');
$stmt->execute([$uid]);
$schedule = array_column($stmt->fetchAll(), 'focus', 'day_of_week');
$today = (int)date('N');

// --- Recent activity -----------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM workout_logs WHERE user_id = ? ORDER BY logged_on DESC, id DESC LIMIT 10');
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

// --- Stats ---------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT
       COUNT(DISTINCT CASE WHEN logged_on >= CURDATE() - INTERVAL 6 DAY THEN logged_on END) AS active_days_7,
       COALESCE(SUM(CASE WHEN workout_type='running' AND logged_on >= CURDATE() - INTERVAL 6 DAY THEN distance_km END),0) AS km_7,
       COALESCE(SUM(CASE WHEN workout_type='running' AND logged_on >= CURDATE() - INTERVAL 29 DAY THEN distance_km END),0) AS km_30,
       COALESCE(MAX(weight_kg),0) AS heaviest,
       COALESCE(SUM(CASE WHEN workout_type='calisthenics' AND logged_on >= CURDATE() - INTERVAL 29 DAY THEN sets*reps END),0) AS bw_reps_30
     FROM workout_logs WHERE user_id = ?"
);
$stmt->execute([$uid]);
$stats = $stmt->fetch();

// --- Goal progress -----------------------------------------------------------
$daysGoal = max(1, (int)$profile['days_per_week']);
$daysPct  = min(100, (int)round($stats['active_days_7'] / $daysGoal * 100));
$kmGoal   = (float)$profile['weekly_km_goal'];
$kmPct    = $kmGoal > 0 ? min(100, (int)round($stats['km_7'] / $kmGoal * 100)) : 0;
$cur      = (float)$profile['weight_kg'];
$target   = $profile['target_weight_kg'] !== null ? (float)$profile['target_weight_kg'] : null;
$wPct     = $target !== null ? weight_progress((float)$profile['start_weight_kg'], $cur, $target) : null;
$bmi      = bmi($cur, (float)$profile['height_cm']);

// --- Progress check (last 8 weeks vs. before; 12 weeks fetched for the weekly streak) ---
require_once 'includes/progress.php';
$stmt = $pdo->prepare('SELECT workout_type, exercise_name, sets, reps, weight_kg, distance_km, duration_seconds, logged_on
                       FROM workout_logs WHERE user_id = ? AND logged_on >= ?');
$stmt->execute([$uid, date('Y-m-d', strtotime('-83 days'))]);
$progressLogs = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT COUNT(DISTINCT logged_on) AS d, COUNT(*) AS n, COALESCE(SUM(distance_km),0) AS km FROM workout_logs WHERE user_id = ?');
$stmt->execute([$uid]);
$pa = analyze_progress($progressLogs, $profile, $stmt->fetch());
$zone = ZONE_META[$pa['zone']];

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Dashboard';
include 'includes/header.php';
?>
<section class="hero">
  <div class="hero-text">
    <p class="eyebrow"><?= date('l, M j') ?> · <?= e(GOALS[$profile['goal_type']]) ?></p>
    <h1><?= $greeting ?>, <?= e($_SESSION['username']) ?></h1>
    <p class="focus">Today · <b><?= e($schedule[$today] ?? 'Rest') ?></b></p>
    <a class="btn btn-accent" href="log_workout.php">Log workout</a>
  </div>
  <div class="ring" style="--p: <?= $daysPct ?>" role="img" aria-label="<?= (int)$stats['active_days_7'] ?> of <?= $daysGoal ?> training days">
    <div><b><?= (int)$stats['active_days_7'] ?></b><span>of <?= $daysGoal ?> days</span></div>
  </div>
</section>

<section class="card" id="progress">
  <div class="row-between"><h2>Progress check</h2><span class="hint">Last 4 weeks vs. the 4 before</span></div>

  <?php if (!$pa['ready']): ?>
    <p class="empty">Log workouts on at least 3 different days to unlock your progress check, plateau detection and advice.</p>
  <?php else: ?>
    <button type="button" class="meter-card" data-dialog="dlg-overall">
      <div class="meter-top">
        <div><small>Form score</small><b><?= (int)$pa['score'] ?></b><small>/100</small></div>
        <span class="chip chip-<?= e($zone[1]) ?> chip-lg"><?= e($zone[0]) ?></span>
        <span class="more">See advice →</span>
      </div>
      <div class="meter" role="img" aria-label="Form score <?= (int)$pa['score'] ?> out of 100, <?= e($zone[0]) ?>">
        <i style="left: <?= (int)$pa['score'] ?>%"></i>
      </div>
      <div class="meter-labels"><span>Falling behind</span><span>Plateau</span><span>Healthy</span><span>On fire</span></div>
    </button>

    <div class="quests">
      <div class="quest"><small>Weekly streak</small><b><?= (int)$pa['streak'] ?></b><span>week<?= $pa['streak'] === 1 ? '' : 's' ?> hitting your goal</span></div>
      <div class="quest">
        <small>Level <?= (int)$pa['level'] ?> · <?= e($pa['title']) ?></small>
        <div class="bar"><i style="width: <?= (int)$pa['xp_pct'] ?>%"></i></div>
        <span><?= (int)$pa['xp_next'] ?> XP to next level</span>
      </div>
    </div>
  <?php endif; ?>

  <div class="pcards">
    <?php foreach ($pa['cats'] as $key => $c):
      $tone = STATUS_META[$c['status']][1]; ?>
      <button type="button" class="pcard tone-<?= e($tone) ?>" data-dialog="dlg-<?= e($key) ?>">
        <span class="pcard-top"><span class="pcard-name"><?= $c['icon'] ? icon($c['icon']) : '' ?><?= e($c['label']) ?></span>
          <?= chip($c['status'], $c['status_label'] ?? null) ?></span>
        <span class="pcard-line"><?= e($c['headline']) ?></span>
        <span class="more">Tap for advice →</span>
      </button>
    <?php endforeach; ?>
  </div>
</section>

<?php
// Dialogs (opened by the buttons above; plain <dialog>, no extra requests)
if ($pa['ready']) {
    $breakdown = [];
    foreach ($pa['cats'] as $c) $breakdown[] = ['name' => $c['label'], 'status' => $c['status'], 'label' => $c['status_label'] ?? null, 'change' => $c['headline']];
    echo render_progress_dialog('dlg-overall', 'Your form score: ' . $pa['score'], '<span class="chip chip-' . e($zone[1]) . ' chip-lg">' . e($zone[0]) . '</span>',
        $zone[2], $breakdown, [], overall_advice($pa['zone'], $profile), 'list');
}
foreach ($pa['cats'] as $key => $c) {
    echo render_progress_dialog('dlg-' . $key, $c['label'], chip($c['status'], $c['status_label'] ?? null), $c['headline'],
        $c['items'], $c['cols'], progress_advice($key, $c['status'], $profile));
}
?>

<section class="card">
  <div class="row-between"><h2>Your goals</h2><a class="link" href="profile.php">Edit</a></div>
  <div class="goals">
    <div class="goal">
      <div class="goal-head"><span>Sessions this week</span><b><?= (int)$stats['active_days_7'] ?> / <?= $daysGoal ?></b></div>
      <div class="bar"><i style="width: <?= $daysPct ?>%"></i></div>
    </div>

    <?php if ($kmGoal > 0): ?>
    <div class="goal">
      <div class="goal-head"><span>Running this week</span><b><?= number_format((float)$stats['km_7'], 1) ?> / <?= rtrim(rtrim(number_format($kmGoal, 1), '0'), '.') ?> km</b></div>
      <div class="bar"><i style="width: <?= $kmPct ?>%"></i></div>
    </div>
    <?php endif; ?>

    <?php if ($wPct !== null): ?>
    <div class="goal">
      <div class="goal-head"><span>Weight</span><b><?= $cur ?> → <?= $target ?> kg</b></div>
      <div class="bar"><i style="width: <?= $wPct ?>%"></i></div>
      <small class="hint"><?= number_format(abs($target - $cur), 1) ?> kg to go</small>
    </div>
    <?php endif; ?>

    <div class="goal">
      <div class="goal-head"><span>BMI</span><b><?= number_format($bmi, 1) ?></b></div>
      <small class="hint"><?= e(bmi_label($bmi)) ?> · a rough indicator only</small>
    </div>
  </div>

  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="form" value="weight">
    <label>Update today’s weight (kg)
      <input type="number" name="weight_kg" step="0.1" min="30" max="300" value="<?= $cur ?>" required>
    </label>
    <button class="btn">Update</button>
  </form>
</section>

<section class="stats">
  <div class="stat"><span>Run · 30 days</span><b><?= number_format((float)$stats['km_30'], 1) ?><small> km</small></b></div>
  <div class="stat"><span>Bodyweight reps · 30 days</span><b><?= number_format((float)$stats['bw_reps_30']) ?></b></div>
  <div class="stat"><span>Heaviest lift</span><b><?= (float)$stats['heaviest'] ?><small> kg</small></b></div>
</section>

<section class="card">
  <div class="row-between"><h2>This week</h2></div>
  <div class="week">
    <?php foreach (WEEKDAYS as $d => $name): ?>
      <div class="day <?= $d === $today ? 'today' : '' ?>">
        <small><?= $name ?></small>
        <strong><?= e($schedule[$d] ?? 'Rest') ?></strong>
      </div>
    <?php endforeach; ?>
  </div>
  <details>
    <summary>Edit split</summary>
    <form method="post" class="week-edit">
      <?= csrf_field() ?><input type="hidden" name="form" value="schedule">
      <?php foreach (WEEKDAYS as $d => $name): ?>
        <label><?= $name ?>
          <input name="focus[<?= $d ?>]" maxlength="100" value="<?= e($schedule[$d] ?? 'Rest') ?>">
        </label>
      <?php endforeach; ?>
      <button class="btn">Save split</button>
    </form>
  </details>
</section>

<section class="card">
  <div class="row-between">
    <h2>Recent activity</h2>
    <a class="link" href="history.php">View all</a>
  </div>
  <?php if (!$recent): ?>
    <p class="empty">No workouts yet. Log your first session to get started.</p>
  <?php else: ?>
  <ul class="feed">
    <?php foreach ($recent as $r): ?>
      <li class="feed-item">
        <span class="feed-icon tag-<?= e($r['workout_type']) ?>"><?= icon($r['workout_type']) ?></span>
        <div>
          <strong><?= e($r['exercise_name']) ?></strong>
          <small><?= e(summarize_log($r)) ?></small>
        </div>
        <time datetime="<?= e($r['logged_on']) ?>"><?= e(date('M j', strtotime($r['logged_on']))) ?></time>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php include 'includes/footer.php'; ?>
