<?php
/**
 * progress.php - transparent, rule-based progress analysis + advice.
 *
 * Method: compare the LAST 4 WEEKS with the 4 WEEKS BEFORE THAT.
 *   Strength      -> best estimated 1RM per lift (Epley: w * (1 + reps/30))     up >= +2%  / down <= -3%
 *   Calisthenics  -> best reps in a set per movement                            up >= +5%  / down <= -10%
 *   Running       -> average pace (faster = better) and weekly distance         pace +1.5% / -3% ; km +5% / -15%
 *   Consistency   -> average active days per week vs. the user's weekly target
 * Between the "up" and "down" thresholds a result counts as a PLATEAU.
 * Needs includes/bootstrap.php loaded first (uses format_pace()).
 */

const STATUS_META = [            // status => [label, tone]
    'progressing' => ['Progressing',        'good'],
    'plateau'     => ['Plateau',            'warn'],
    'declining'   => ['Falling behind',     'bad'],
    'inactive'    => ['Not trained lately', 'bad'],
    'building'    => ['Building baseline',  'neutral'],
    'nodata'      => ['No data yet',        'neutral'],
];
const ZONE_META = [              // zone => [label, tone, summary]
    'fire'    => ['On fire',          'good',    'You’re improving across the board and training consistently. Keep the recipe and protect your recovery.'],
    'healthy' => ['Healthy progress', 'good',    'Most of your numbers are moving the right way. A few small tweaks can keep it going.'],
    'plateau' => ['Plateau zone',     'warn',    'You’re showing up, but your numbers have levelled off. That’s normal: it’s a signal to change one variable.'],
    'falling' => ['Falling behind',   'bad',     'Your recent numbers or consistency have slipped. Don’t panic: a short reset usually fixes this.'],
];
const RANKS = [1 => 'Rookie', 3 => 'Regular', 5 => 'Athlete', 8 => 'Veteran', 12 => 'Elite'];

/** Epley estimated one-rep max. */
function e1rm(float $w, int $reps): float { return $w * (1 + min($reps, 30) / 30); }

/** Compare windows. Returns [status, pct] where positive pct always means "improved". */
function compare_windows(?float $prev, ?float $recent, bool $higherIsBetter, float $up, float $down): array {
    if ($recent === null && $prev === null) return ['nodata', 0.0];
    if ($recent === null) return ['inactive', 0.0];
    if ($prev === null)   return ['building', 0.0];
    $pct = $prev > 0 ? (($higherIsBetter ? $recent - $prev : $prev - $recent) / $prev) * 100 : 0.0;
    return [$pct >= $up ? 'progressing' : ($pct <= $down ? 'declining' : 'plateau'), $pct];
}

/** One table row for the detail dialog. */
function p_item(string $name, string $status, float $pct, ?string $prev, ?string $recent, ?string $suffix = null): array {
    $change = match ($status) {
        'inactive' => 'No sessions',
        'building' => 'New',
        'nodata'   => '—',
        default    => $suffix === 'pace'
                        ? sprintf('%.1f%% %s', abs($pct), $pct >= 0 ? 'faster' : 'slower')
                        : sprintf('%+.1f%%', $pct),
    };
    return ['name' => $name, 'status' => $status, 'prev' => $prev ?? '—', 'recent' => $recent ?? '—', 'change' => $change];
}

/** Worst-first ordering so problems show at the top. */
function sort_items(array $items): array {
    $order = ['declining' => 0, 'inactive' => 1, 'plateau' => 2, 'building' => 3, 'progressing' => 4, 'nodata' => 5];
    usort($items, fn($a, $b) => ($order[$a['status']] <=> $order[$b['status']]) ?: strcmp($a['name'], $b['name']));
    return $items;
}

/** Roll a list of items up into one category status. */
function rollup(array $items): string {
    if (!$items) return 'nodata';
    $c  = array_count_values(array_column($items, 'status'));
    $p  = $c['progressing'] ?? 0; $pl = $c['plateau'] ?? 0;
    $bad = ($c['declining'] ?? 0) + ($c['inactive'] ?? 0);
    if (($c['inactive'] ?? 0) === count($items)) return 'inactive';
    if ($bad > $p) return 'declining';
    if ($p > 0)    return 'progressing';
    if ($pl > 0)   return 'plateau';
    return 'building';
}

/**
 * @param array $logs   rows from the last 84 days (workout_type, exercise_name, sets, reps, weight_kg, distance_km, duration_seconds, logged_on)
 * @param array $profile user_profiles row
 * @param array $totals  ['d' => active days all-time, 'n' => entries all-time, 'km' => total km]
 */
function analyze_progress(array $logs, array $profile, array $totals): array {
    $today   = new DateTime(date('Y-m-d'));
    $buckets = ['weightlifting' => [], 'calisthenics' => [], 'running' => []];
    $activeAgo = [];                                   // date => days ago
    foreach ($logs as $l) {
        $ago = (int)(new DateTime($l['logged_on']))->diff($today)->days;
        $activeAgo[$l['logged_on']] = $ago;
        $win = $ago <= 27 ? 'recent' : ($ago <= 55 ? 'prev' : null);
        if ($win !== null && isset($buckets[$l['workout_type']])) {
            $buckets[$l['workout_type']][$l['exercise_name']][$win][] = $l;
        }
    }
    $cats = [];

    // ---- Strength: best e1RM per lift ----
    $bestE = fn(array $rows): ?float => $rows ? max(array_map(fn($r) => e1rm((float)$r['weight_kg'], (int)$r['reps']), $rows)) : null;
    $items = [];
    foreach ($buckets['weightlifting'] as $name => $w) {
        $p = $bestE($w['prev'] ?? []); $r = $bestE($w['recent'] ?? []);
        [$st, $pct] = compare_windows($p, $r, true, 2.0, -3.0);
        $items[] = p_item($name, $st, $pct, $p !== null ? round($p, 1) . ' kg' : null, $r !== null ? round($r, 1) . ' kg' : null);
    }
    $items = sort_items($items);
    $up = count(array_filter($items, fn($i) => $i['status'] === 'progressing'));
    $cats['weightlifting'] = ['label' => 'Strength', 'icon' => 'weightlifting', 'status' => rollup($items), 'items' => $items,
        'cols' => ['Lift', 'Est. 1RM (prev 4w)', 'Est. 1RM (last 4w)'],
        'headline' => $items ? "$up of " . count($items) . ' lifts improving' : 'Log lifts to unlock'];

    // ---- Calisthenics: best reps in a set ----
    $bestR = fn(array $rows): ?float => $rows ? (float)max(array_column($rows, 'reps')) : null;
    $items = [];
    foreach ($buckets['calisthenics'] as $name => $w) {
        $p = $bestR($w['prev'] ?? []); $r = $bestR($w['recent'] ?? []);
        [$st, $pct] = compare_windows($p, $r, true, 5.0, -10.0);
        $items[] = p_item($name, $st, $pct, $p !== null ? (int)$p . ' reps' : null, $r !== null ? (int)$r . ' reps' : null);
    }
    $items = sort_items($items);
    $up = count(array_filter($items, fn($i) => $i['status'] === 'progressing'));
    $cats['calisthenics'] = ['label' => 'Calisthenics', 'icon' => 'calisthenics', 'status' => rollup($items), 'items' => $items,
        'cols' => ['Movement', 'Best set (prev 4w)', 'Best set (last 4w)'],
        'headline' => $items ? "$up of " . count($items) . ' movements improving' : 'Log bodyweight work to unlock'];

    // ---- Running: pace + weekly distance ----
    $runs = ['prev' => [], 'recent' => []];
    foreach ($buckets['running'] as $w) foreach (['prev', 'recent'] as $k) $runs[$k] = array_merge($runs[$k], $w[$k] ?? []);
    $paceOf = function (array $rows): ?float {
        $rows = array_filter($rows, fn($r) => (float)$r['distance_km'] >= 1.0);
        $km = array_sum(array_column($rows, 'distance_km'));
        return $km > 0 ? array_sum(array_column($rows, 'duration_seconds')) / $km : null;   // sec per km
    };
    $kmOf = fn(array $rows): ?float => $rows ? array_sum(array_column($rows, 'distance_km')) / 4 : null;  // km per week
    $items = [];
    if ($runs['prev'] || $runs['recent']) {
        $p = $paceOf($runs['prev']); $r = $paceOf($runs['recent']);
        [$st, $pct] = compare_windows($p, $r, false, 1.5, -3.0);
        $items[] = p_item('Average pace', $st, $pct, $p ? format_pace(1.0, (int)round($p)) : null, $r ? format_pace(1.0, (int)round($r)) : null, 'pace');
        $p = $kmOf($runs['prev']); $r = $kmOf($runs['recent']);
        [$st2, $pct2] = compare_windows($p, $r, true, 5.0, -15.0);
        $items[] = p_item('Weekly distance', $st2, $pct2, $p !== null ? number_format($p, 1) . ' km' : null, $r !== null ? number_format($r, 1) . ' km' : null);
    }
    $items = sort_items($items);
    $paceItem = null;
    foreach ($items as $i) if ($i['name'] === 'Average pace') $paceItem = $i;
    $cats['running'] = ['label' => 'Running', 'icon' => 'running', 'status' => rollup($items), 'items' => $items,
        'cols' => ['Metric', 'Previous 4 weeks', 'Last 4 weeks'],
        'headline' => !$items ? 'Log runs to unlock'
            : (($paceItem && $paceItem['prev'] !== '—' && $paceItem['recent'] !== '—')
                ? 'Pace ' . $paceItem['prev'] . ' → ' . $paceItem['recent'] : STATUS_META[rollup($items)][0])];

    // ---- Consistency: active days per week vs target ----
    $target = max(1, (int)$profile['days_per_week']);
    $weeks  = array_fill(0, 12, []);
    foreach ($activeAgo as $d => $ago) { $i = intdiv($ago, 7); if ($i < 12) $weeks[$i][$d] = 1; }
    $counts = array_map('count', $weeks);
    $weeksActive = $activeAgo ? min(4, intdiv(max($activeAgo), 7) + 1) : 0;   // don't punish brand-new users
    $avg   = $weeksActive ? array_sum(array_slice($counts, 0, $weeksActive)) / $weeksActive : 0.0;
    $ratio = $avg / $target;
    $items = [];
    for ($i = 0; $i < $weeksActive; $i++) {
        $c  = $counts[$i];
        $st = $c >= $target ? 'progressing' : ($c >= (int)ceil($target * 0.6) ? 'plateau' : 'declining');
        $items[] = ['name' => $i === 0 ? 'Last 7 days' : ($i * 7) . '–' . ($i * 7 + 6) . ' days ago', 'status' => $st,
                    'prev' => "Goal: $target", 'recent' => $c . ' day' . ($c === 1 ? '' : 's'),
                    'change' => $c >= $target ? 'Goal met' : ($st === 'plateau' ? 'Close' : 'Missed')];
    }
    if (!$activeAgo)            { $cs = 'nodata';      $cl = null; }
    elseif ($ratio >= 0.85)     { $cs = 'progressing'; $cl = 'On track'; }
    elseif ($ratio >= 0.5)      { $cs = 'plateau';     $cl = 'Slightly behind'; }
    else                        { $cs = 'declining';   $cl = 'Falling behind'; }
    $cats['consistency'] = ['label' => 'Consistency', 'icon' => null, 'status' => $cs, 'status_label' => $cl, 'items' => $items,
        'cols' => ['Period', 'Target', 'Trained'],
        'headline' => $activeAgo ? number_format($avg, 1) . " of $target days / week" : 'Log workouts to unlock'];

    // ---- Weekly streak: consecutive weeks hitting the target (current week counts if already met) ----
    $streak = 0;
    for ($i = 1; $i < 12 && $counts[$i] >= $target; $i++) $streak++;
    if ($counts[0] >= $target) $streak++;

    // ---- Form score (0-100): 40% consistency + 60% progression ----
    $w = ['progressing' => 1.0, 'building' => 0.6, 'plateau' => 0.4, 'inactive' => 0.1, 'declining' => 0.1];
    $scored = [];
    foreach (['weightlifting', 'calisthenics', 'running'] as $k) foreach ($cats[$k]['items'] as $i) if (isset($w[$i['status']])) $scored[] = $i['status'];
    $prog  = $scored ? array_sum(array_map(fn($s) => $w[$s], $scored)) / count($scored) : 0.5;
    $score = (int)round(min(1, $ratio) * 40 + $prog * 60);
    $anyUp = in_array('progressing', $scored, true);
    $anyPl = in_array('plateau', $scored, true);
    $zone  = $score < 40 ? 'falling' : (($anyPl && !$anyUp) || $score < 60 ? 'plateau' : ($score < 80 ? 'healthy' : 'fire'));

    // ---- Level / XP (game layer) ----
    $xp    = (int)$totals['d'] * 20 + (int)$totals['n'] * 5 + (int)floor((float)$totals['km']) * 3;
    $level = (int)floor(sqrt($xp / 40)) + 1;
    $lo    = 40 * ($level - 1) ** 2; $hi = 40 * $level ** 2;
    $title = 'Rookie'; foreach (RANKS as $min => $t) if ($level >= $min) $title = $t;

    return ['ready' => count($activeAgo) >= 3, 'score' => $score, 'zone' => $zone, 'cats' => $cats, 'streak' => $streak,
            'level' => $level, 'title' => $title, 'xp' => $xp, 'xp_pct' => (int)round(($xp - $lo) / ($hi - $lo) * 100), 'xp_next' => $hi - $xp];
}

/** Practical, goal-aware suggestions. Returns [[title, text], ...]. */
function progress_advice(string $cat, string $status, array $profile): array {
    $A = [
      'weightlifting' => [
        'progressing' => [['Keep the recipe', 'What you’re doing is working. Keep your split, rep ranges and rest days the same for now.'],
                          ['Increase in small steps', 'Add 1–2.5 kg (or an extra rep) per session instead of big jumps. Small steps last longer.'],
                          ['Plan a deload', 'Every 6–8 weeks take one lighter week (about 60% of normal volume) so progress keeps coming.']],
        'plateau'     => [['Change the rep range', 'Stuck at 3×8? Try 4×5 heavier or 2×12 lighter for 3–4 weeks, then return to your old range.'],
                          ['Add a set before adding weight', 'One extra set per week on the stalled lift is often easier than adding load.'],
                          ['Use micro-loading', 'Jump by 1 kg or less on the stalled lift. Small plates keep the bar moving up.'],
                          ['Schedule a deload', 'Stalls are often fatigue. Do one week at roughly 60% volume, then retest.'],
                          ['Check recovery basics', 'Aim for 7–9 hours of sleep and enough protein and total food. Under-eating is a common reason lifts stall.']],
        'declining'   => [['Check recovery first', 'Poor sleep, stress, illness or a long diet can all lower your numbers. A dip is not the same as losing muscle.'],
                          ['Drop the load ~10% and rebuild', 'Lighter weights with clean reps rebuild momentum quickly. Add weight again over 2–3 weeks.'],
                          ['Show up consistently', 'Hitting every planned session matters more than any single heavy set.'],
                          ['Review technique', 'Film a set; sloppy reps can look like strength loss. If something hurts, stop and get it checked.']],
        'inactive'    => [['Ease back in', 'Start around 70% of your last working weights for 1–2 weeks, then climb back.'],
                          ['Aim for 2 sessions a week first', 'Rebuilding the habit is the fastest route back to your old numbers.']],
        'building'    => [['Keep logging the same lifts', 'Use the same exercises and rep ranges for about 3 weeks so we can measure real change.']],
        'nodata'      => [['Log your first lifts', 'Add weightlifting sessions with weight, sets and reps. After 4–8 weeks we can tell you if you’re progressing.']],
      ],
      'calisthenics' => [
        'progressing' => [['Progress the movement', 'Once you hit about 12 clean reps, move to a harder variation (e.g. push-up → archer or pseudo-planche push-up).'],
                          ['Keep reps clean', 'Full range, controlled tempo. Quality reps are the ones that count.']],
        'plateau'     => [['Slow the tempo', 'Try 3-second lowering phases. Time under tension adds difficulty without new equipment.'],
                          ['Add load or a harder variation', 'A backpack or weighted vest, or a harder progression, breaks rep ceilings.'],
                          ['Add volume gradually', 'One extra set per week for 3–4 weeks, then deload.'],
                          ['Practise submax sets', 'Several easy sets through the day (stopping 2–3 reps short of failure) build skill without fatigue.']],
        'declining'   => [['Cut volume, keep quality', 'Fatigue hides strength. Drop to about 70% of your sets for a week and aim for crisp reps.'],
                          ['Check sleep and food', 'Bodyweight strength drops fast when you’re under-recovered.'],
                          ['Review the basics', 'Keep full range of motion and stop sets before form breaks.']],
        'inactive'    => [['Restart with an easier variation', 'Use a variation you can do for 8–10 reps for two weeks, then progress again.']],
        'building'    => [['Build a baseline', 'Repeat the same movements for 3 weeks so we can compare your best sets.']],
        'nodata'      => [['Log bodyweight work', 'Add calisthenics sessions (push-ups, pull-ups, dips…) to unlock progress tracking.']],
      ],
      'running' => [
        'progressing' => [['Raise distance gradually', 'Keep weekly mileage increases to about 10% or less.'],
                          ['Take a down week', 'Every 3–4 weeks cut mileage by roughly 20–30% to absorb the training.'],
                          ['Keep most runs easy', 'About 80% of your running should feel conversational.']],
        'plateau'     => [['Add one quality session', 'One weekly interval run (e.g. 6×400 m hard with easy jogs between) or a tempo run drives pace change.'],
                          ['Add an easy long run', 'Extend your longest run by 5–10 minutes each week to build aerobic base.'],
                          ['Do strides', 'After an easy run, 4–6 × 20-second relaxed fast strides improve running economy.'],
                          ['Slow down your easy days', 'Many runners run easy days too fast, which blocks progress on hard days.']],
        'declining'   => [['Check fatigue', 'A slower pace usually means tiredness, heat, poor sleep or too many hard days in a row.'],
                          ['Run more easy, not more hard', 'Add easy volume gradually and keep at most one or two hard sessions a week.'],
                          ['Take a recovery week', 'Reduce mileage by around 30% for a week, then rebuild. If anything hurts, rest and get advice.']],
        'inactive'    => [['Return gently', 'Start with short easy runs (20–30 minutes) 2–3 times a week and increase slowly.'],
                          ['Walk-run if needed', 'Alternating running and walking is a safe way back.']],
        'building'    => [['Build a baseline', 'Keep logging runs for 4 weeks so we can compare pace and weekly distance.']],
        'nodata'      => [['Log your first runs', 'Add distance and time to unlock pace and mileage tracking.']],
      ],
      'consistency' => [
        'progressing' => [['Protect the habit', 'Keep your sessions at fixed times and don’t skip recovery days.'],
                          ['Consider raising your goal', 'If this feels easy, bump your weekly target up by one day in your profile.']],
        'plateau'     => [['Lock in your days', 'Schedule each session in your calendar and match it to your weekly split on the dashboard.'],
                          ['Use a minimum session', 'On busy days do a 20-minute version instead of skipping. Showing up counts.']],
        'declining'   => [['Make the goal realistic', 'Lower your weekly target in your profile for 2–3 weeks, hit it, then raise it again.'],
                          ['Shrink the barrier', 'Prepare your bag the night before and pick sessions you enjoy.'],
                          ['Find accountability', 'Train with a friend or share your weekly plan with someone.']],
        'inactive'    => [['Start tiny', 'Two short sessions this week is a success. Build from there.']],
        'building'    => [['Keep going', 'A few more weeks of data will give a clearer picture.']],
        'nodata'      => [['Log your first workout', 'Consistency tracking begins as soon as you log sessions.']],
      ],
    ];
    return $A[$cat][$status] ?? $A[$cat]['nodata'] ?? [];
}

/** Overall advice for the Form-score dialog: zone tips + a goal-specific tip. */
function overall_advice(string $zone, array $profile): array {
    $Z = [
      'fire'    => [['Don’t change what works', 'Stay consistent and resist adding lots of new variables at once.'], ['Schedule recovery', 'A planned lighter week every 6–8 weeks protects your streak.']],
      'healthy' => [['Fix the weakest area', 'Open the cards below. Any category marked Plateau or Falling behind is your quickest win.'], ['Keep logging', 'Accurate logs make this check more reliable.']],
      'plateau' => [['Change ONE variable', 'Pick one thing to change for 3–4 weeks: rep range, an extra set, tempo, or weekly distance. Not everything at once.'], ['Prioritise sleep', 'Plateaus are frequently recovery problems, not training problems.']],
      'falling' => [['Reset, don’t panic', 'A 1-week lighter block, then rebuild at about 80% of your usual load.'], ['Rebuild consistency first', 'Aim for your minimum number of sessions for 2 weeks before chasing numbers.'], ['Check life stress', 'Illness, stress and poor sleep can all drag numbers down temporarily.']],
    ];
    $G = [
      'lose_fat'        => ['Goal tip · Lose fat', 'Holding your strength while your weight drops is a win. If lifts fall quickly, ease your calorie deficit slightly and keep protein up.'],
      'build_muscle'    => ['Goal tip · Build muscle', 'Muscle comes from progressive overload plus enough food and sleep. If you’re stuck, add weekly sets before adding weight.'],
      'endurance'       => ['Goal tip · Endurance', 'Build weekly distance gradually (no more than ~10% per week) and keep most runs easy.'],
      'general_fitness' => ['Goal tip · General fitness', 'Keep a balance of lifting, bodyweight work and running rather than letting one slip.'],
    ];
    $adv = $Z[$zone] ?? [];
    if (isset($G[$profile['goal_type']])) $adv[] = $G[$profile['goal_type']];
    return $adv;
}

/** Status chip markup. */
function chip(string $status, ?string $label = null): string {
    [$l, $tone] = STATUS_META[$status] ?? ['', 'neutral'];
    return '<span class="chip chip-' . $tone . '">' . e($label ?? $l) . '</span>';
}

/** Modal with details + advice. $mode 'table' (category) or 'list' (overall breakdown). */
function render_progress_dialog(string $id, string $title, string $chipHtml, string $summary, array $items, array $cols, array $advice, string $mode = 'table'): string {
    ob_start(); ?>
    <dialog class="pdialog" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-t">
      <div class="pd-head">
        <div><h3 id="<?= e($id) ?>-t"><?= e($title) ?></h3><p class="hint"><?= e($summary) ?></p></div>
        <?= $chipHtml ?>
      </div>
      <?php if ($items): ?>
        <h4>What we see</h4>
        <?php if ($mode === 'list'): ?>
          <ul class="pd-list">
            <?php foreach ($items as $i): ?>
              <li><span><b><?= e($i['name']) ?></b> <small><?= e($i['change']) ?></small></span><?= chip($i['status'], $i['label'] ?? null) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th><?= e($cols[0]) ?></th><th><?= e($cols[1]) ?></th><th><?= e($cols[2]) ?></th><th>Change</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
              <tr><td><?= e($i['name']) ?></td><td><?= e($i['prev']) ?></td><td><?= e($i['recent']) ?></td>
                  <td><?= chip($i['status'], $i['change']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      <?php endif; ?>
      <h4>What to do next</h4>
      <ol class="advice">
        <?php foreach ($advice as [$t, $x]): ?><li><b><?= e($t) ?></b><span><?= e($x) ?></span></li><?php endforeach; ?>
      </ol>
      <p class="hint">General tips based on your logs, not medical advice. If something hurts, stop and see a professional.</p>
      <form method="dialog"><button class="btn">Got it</button></form>
    </dialog>
    <?php return ob_get_clean();
}
