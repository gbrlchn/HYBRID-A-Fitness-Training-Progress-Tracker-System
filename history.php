<?php
/**
 * history.php - filter, edit, delete past logs + personal-record progression summary.
 */
require_once 'includes/bootstrap.php';
$uid = require_login();

// --- Delete (POST + CSRF + ownership) ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $pdo->prepare('DELETE FROM workout_logs WHERE id = ? AND user_id = ?')->execute([(int)$_POST['delete_id'], $uid]);
    flash('Record deleted.');
    header('Location: history.php?' . http_build_query(array_filter(['type' => $_POST['type'] ?? '', 'q' => $_POST['q'] ?? '']))); exit;
}

// --- Filters -------------------------------------------------------------------
$type = in_array($_GET['type'] ?? '', WORKOUT_TYPES, true) ? $_GET['type'] : '';
$q    = trim($_GET['q'] ?? '');

$where = ['user_id = ?']; $args = [$uid];
if ($type !== '') { $where[] = 'workout_type = ?';       $args[] = $type; }
if ($q !== '')    { $where[] = 'exercise_name LIKE ?';   $args[] = "%$q%"; }
$w = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT * FROM workout_logs WHERE $w ORDER BY logged_on DESC, id DESC LIMIT 200");
$stmt->execute($args);
$logs = $stmt->fetchAll();

// --- Progression: per-exercise first/last date, count and personal bests --------
$stmt = $pdo->prepare(
    "SELECT workout_type, exercise_name, COUNT(*) AS entries,
            MIN(logged_on) AS first_on, MAX(logged_on) AS last_on,
            MAX(weight_kg) AS best_weight, MAX(reps) AS best_reps,
            MAX(distance_km) AS longest_km,
            MIN(CASE WHEN distance_km > 0 THEN duration_seconds / distance_km END) AS best_pace
     FROM workout_logs WHERE $w GROUP BY workout_type, exercise_name ORDER BY workout_type, exercise_name"
);
$stmt->execute($args);
$progress = $stmt->fetchAll();

$pageTitle = 'History';
include 'includes/header.php';
?>
<h1>History &amp; Progress</h1>

<form method="get" class="card filters">
  <label>Category
    <select name="type">
      <option value="">All</option>
      <?php foreach (WORKOUT_TYPES as $t): ?>
        <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Exercise contains <input name="q" value="<?= e($q) ?>"></label>
  <button class="btn">Filter</button>
  <a href="history.php">Reset</a>
</form>

<section class="card">
  <h2>Personal Bests</h2>
  <?php if (!$progress): ?><p>Nothing to show.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Exercise</th><th>Type</th><th>Entries</th><th>First → Last</th><th>Best</th></tr></thead>
    <tbody>
    <?php foreach ($progress as $p): ?>
      <tr>
        <td><?= e($p['exercise_name']) ?></td>
        <td><span class="tag tag-<?= e($p['workout_type']) ?>"><?= e($p['workout_type']) ?></span></td>
        <td><?= (int)$p['entries'] ?></td>
        <td><?= e($p['first_on']) ?> → <?= e($p['last_on']) ?></td>
        <td><?php
          echo match ($p['workout_type']) {
              'calisthenics'  => 'Max ' . (int)$p['best_reps'] . ' reps / set',
              'weightlifting' => (float)$p['best_weight'] . ' kg',
              'running'       => (float)$p['longest_km'] . ' km longest · best pace ' . format_pace(1.0, (int)round((float)$p['best_pace'])),
          };
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Records (<?= count($logs) ?>)</h2>
  <?php if (!$logs): ?><p>No records match.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Type</th><th>Exercise</th><th>Result</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($logs as $r): ?>
      <tr>
        <td><?= e($r['logged_on']) ?></td>
        <td><span class="tag tag-<?= e($r['workout_type']) ?>"><?= e($r['workout_type']) ?></span></td>
        <td><?= e($r['exercise_name']) ?></td>
        <td><?= e(summarize_log($r)) ?></td>
        <td><?= e($r['notes']) ?></td>
        <td class="actions">
          <a href="log_workout.php?id=<?= (int)$r['id'] ?>">Edit</a>
          <form method="post" data-confirm="Delete this record?">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <button class="link-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php include 'includes/footer.php'; ?>
