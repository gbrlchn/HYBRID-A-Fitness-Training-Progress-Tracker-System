<?php
/**
 * log_workout.php - log (or edit via ?id=) a session.
 * Calisthenics / weightlifting: any number of exercises per session ("+ Add exercise").
 * Running: one run (distance + duration; pace derived).
 */
require_once 'includes/bootstrap.php';
$uid = require_login();

$editId = (int)($_GET['id'] ?? 0);
$errors = [];
$s = fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';   // safe string from input

/** Markup for one exercise row (used for existing rows and the JS <template>). */
function ex_row(array $r = []): string {
    ob_start(); ?>
    <div class="ex-row">
      <label>Exercise
        <input name="exercise_name[]" list="known" maxlength="100" value="<?= e($r['exercise_name'] ?? '') ?>" placeholder="Pull-ups, Bench press...">
      </label>
      <div class="grid3">
        <label>Sets <input type="number" name="sets[]" min="1" max="100" value="<?= e($r['sets'] ?? '') ?>"></label>
        <label>Reps <input type="number" name="reps[]" min="1" max="1000" value="<?= e($r['reps'] ?? '') ?>"></label>
        <label class="weight-field">Weight (kg)
          <input type="number" name="weight_kg[]" min="0" max="1000" step="0.25" value="<?= e($r['weight_kg'] ?? '') ?>">
        </label>
      </div>
      <button type="button" class="link-danger remove-row">Remove exercise</button>
    </div>
    <?php return ob_get_clean();
}

// Session-level fields + exercise rows (defaults, or the record being edited).
$f = ['workout_type' => 'calisthenics', 'logged_on' => date('Y-m-d'), 'distance_km' => '',
      'dur_h' => '', 'dur_m' => '', 'dur_s' => '', 'notes' => ''];
$exRows = [[]];

if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM workout_logs WHERE id = ? AND user_id = ?');   // ownership check
    $stmt->execute([$editId, $uid]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Record not found.'); }
    $d = (int)$row['duration_seconds'];
    $f = array_merge($f, array_intersect_key($row, $f), [
        'dur_h' => $d ? intdiv($d, 3600) : '', 'dur_m' => $d ? intdiv($d % 3600, 60) : '', 'dur_s' => $d ? $d % 60 : '']);
    if ($row['workout_type'] !== 'running') $exRows = [$row];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($f as $k => $_) $f[$k] = $s($_POST[$k] ?? '');

    // Rebuild exercise rows from the parallel arrays (max 30 rows).
    $exRows = [];
    foreach (array_slice((array)($_POST['exercise_name'] ?? []), 0, 30, true) as $i => $n) {
        $exRows[] = ['exercise_name' => $s($n), 'sets' => $s($_POST['sets'][$i] ?? ''),
                     'reps' => $s($_POST['reps'][$i] ?? ''), 'weight_kg' => $s($_POST['weight_kg'][$i] ?? '')];
    }
    if (!$exRows) $exRows = [[]];

    $type = $f['workout_type'];
    $date = DateTime::createFromFormat('Y-m-d', $f['logged_on']);
    if (!in_array($type, WORKOUT_TYPES, true))                $errors[] = 'Choose a workout type.';
    if (!$date || $date->format('Y-m-d') !== $f['logged_on']) $errors[] = 'Invalid date.';
    elseif ($f['logged_on'] > date('Y-m-d'))                  $errors[] = 'Date cannot be in the future.';

    $records = [];   // each: [name, sets, reps, weight, distance, duration]

    if ($type === 'calisthenics' || $type === 'weightlifting') {
        $multi = count($exRows) > 1;
        foreach ($exRows as $i => $r) {
            $blank = !array_filter($r, fn($v) => $v !== '');
            if ($blank && $multi) continue;                   // ignore untouched extra rows
            $p = $multi ? 'Exercise ' . ($i + 1) . ': ' : '';
            $name = mb_substr($r['exercise_name'] ?? '', 0, 100);
            $sets = filter_var($r['sets'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            $reps = filter_var($r['reps'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            $wt   = null;
            if ($name === '')      $errors[] = $p . 'name is required.';
            if ($sets === false)   $errors[] = $p . 'sets must be 1-100.';
            if ($reps === false)   $errors[] = $p . 'reps must be 1-1000.';
            if ($type === 'weightlifting') {
                $wt = filter_var($r['weight_kg'] ?? '', FILTER_VALIDATE_FLOAT);
                if ($wt === false || $wt < 0 || $wt > 1000) $errors[] = $p . 'weight must be 0-1000 kg.';
            }
            $records[] = [$name, $sets, $reps, $wt, null, null];
        }
        if (!$records && !$errors) $errors[] = 'Add at least one exercise.';
    } elseif ($type === 'running') {
        $dist = filter_var($f['distance_km'], FILTER_VALIDATE_FLOAT);
        $dur  = ((int)$f['dur_h'] * 3600) + ((int)$f['dur_m'] * 60) + (int)$f['dur_s'];
        if ($dist === false || $dist <= 0 || $dist > 500) $errors[] = 'Distance must be above 0 and at most 500 km.';
        if ($dur <= 0) $errors[] = 'Enter a duration.';
        $records[] = ['Run', null, null, null, $dist, $dur];
    }

    if (!$errors) {
        $notes = $f['notes'] !== '' ? mb_substr($f['notes'], 0, 255) : null;
        $pdo->beginTransaction();
        if ($editId) {
            [$n, $st, $rp, $wt, $di, $du] = $records[0];
            $pdo->prepare('UPDATE workout_logs SET workout_type=?, exercise_name=?, sets=?, reps=?, weight_kg=?,
                           distance_km=?, duration_seconds=?, notes=?, logged_on=? WHERE id=? AND user_id=?')
                ->execute([$type, $n, $st, $rp, $wt, $di, $du, $notes, $f['logged_on'], $editId, $uid]);
            flash('Workout updated.');
        } else {
            $ins = $pdo->prepare('INSERT INTO workout_logs (user_id, workout_type, exercise_name, sets, reps, weight_kg,
                                  distance_km, duration_seconds, notes, logged_on) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($records as [$n, $st, $rp, $wt, $di, $du]) {
                $ins->execute([$uid, $type, $n, $st, $rp, $wt, $di, $du, $notes, $f['logged_on']]);
            }
            $c = count($records);
            flash($c > 1 ? "$c exercises logged." : 'Workout logged.');
        }
        $pdo->commit();
        header('Location: ' . ($editId ? 'history.php' : 'dashboard.php')); exit;
    }
}

// Exercise names used before -> autocomplete.
$stmt = $pdo->prepare("SELECT DISTINCT exercise_name FROM workout_logs WHERE user_id = ? AND workout_type <> 'running' ORDER BY exercise_name");
$stmt->execute([$uid]);
$known = $stmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = $editId ? 'Edit Workout' : 'Log Workout';
include 'includes/header.php';
?>
<h1><?= $editId ? 'Edit workout' : 'Log workout' ?></h1>
<form method="post" class="card stack" id="log-form">
  <?php foreach ($errors as $err): ?><p class="error"><?= e($err) ?></p><?php endforeach; ?>
  <?= csrf_field() ?>

  <div class="type-picker" role="radiogroup" aria-label="Workout type">
    <?php foreach (WORKOUT_TYPES as $t): ?>
      <label class="pill tag-<?= $t ?>">
        <input type="radio" name="workout_type" value="<?= $t ?>" <?= $f['workout_type'] === $t ? 'checked' : '' ?>>
        <span><?= icon($t) ?><em><?= ucfirst($t) ?></em></span>
      </label>
    <?php endforeach; ?>
  </div>

  <label>Date <input type="date" name="logged_on" value="<?= e($f['logged_on']) ?>" max="<?= date('Y-m-d') ?>" required></label>

  <!-- Calisthenics + weightlifting: repeatable exercise rows -->
  <div class="group" data-types="calisthenics weightlifting">
    <datalist id="known"><?php foreach ($known as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
    <div id="ex-rows"><?php foreach ($exRows as $r) echo ex_row($r); ?></div>
    <?php if (!$editId): ?>
      <button type="button" class="btn btn-ghost" id="add-ex">+ Add exercise</button>
    <?php endif; ?>
  </div>
  <template id="ex-template"><?= ex_row() ?></template>

  <!-- Running -->
  <div class="group" data-types="running">
    <label>Distance (km) <input type="number" name="distance_km" min="0" step="0.01" value="<?= e($f['distance_km']) ?>"></label>
    <div class="grid3">
      <label>Hours   <input type="number" name="dur_h" min="0" value="<?= e($f['dur_h']) ?>"></label>
      <label>Minutes <input type="number" name="dur_m" min="0" max="59" value="<?= e($f['dur_m']) ?>"></label>
      <label>Seconds <input type="number" name="dur_s" min="0" max="59" value="<?= e($f['dur_s']) ?>"></label>
    </div>
    <p class="pace">Pace: <b id="pace-out">—</b></p>
  </div>

  <label>Notes (optional) <input name="notes" maxlength="255" value="<?= e($f['notes']) ?>"></label>
  <div class="row-between">
    <button class="btn btn-accent"><?= $editId ? 'Save changes' : 'Save workout' ?></button>
    <a class="link" href="<?= $editId ? 'history.php' : 'dashboard.php' ?>">Cancel</a>
  </div>
</form>
<?php include 'includes/footer.php'; ?>
