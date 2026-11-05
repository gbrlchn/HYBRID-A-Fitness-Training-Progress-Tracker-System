<?php
/**
 * bootstrap.php - session, DB, and shared helpers. Include first on every page.
 */
declare(strict_types=1);

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/db_connect.php';

/** Fallback for PHP builds without the mbstring extension (XAMPP has it enabled by default). */
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string { return substr($s, $start, $len); }
}

const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
const WORKOUT_TYPES = ['calisthenics', 'weightlifting', 'running'];

/** Escape for HTML output (prevents XSS). */
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Redirect to login unless authenticated; returns the user id. */
function require_login(): int {
    if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
    return (int)$_SESSION['user_id'];
}

/** CSRF protection for every POST form. */
function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Invalid CSRF token.');
    }
}

/** One-shot flash message: flash('text') to set, flash() to read+clear. */
function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}

/** 3725 -> "1:02:05" */
function format_duration(?int $s): string {
    if (!$s) return '—';
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

/** Pace in min:sec per km, derived from distance + duration. */
function format_pace(?float $km, ?int $sec): string {
    if (!$km || !$sec) return '—';
    $p = (int)round($sec / $km);
    return sprintf('%d:%02d /km', intdiv($p, 60), $p % 60);
}

/** Human-readable one-line summary of any log row. */
function summarize_log(array $r): string {
    return match ($r['workout_type']) {
        'calisthenics'  => "{$r['sets']} × {$r['reps']} reps",
        'weightlifting' => "{$r['sets']} × {$r['reps']} @ " . (float)$r['weight_kg'] . ' kg',
        'running'       => "{$r['distance_km']} km in " . format_duration((int)$r['duration_seconds'])
                           . ' (' . format_pace((float)$r['distance_km'], (int)$r['duration_seconds']) . ')',
        default         => '',
    };
}

/** Minimal inline SVG icons (stroke style, inherits currentColor). */
function icon(string $name): string {
    $paths = [
        'calisthenics'  => '<path d="M3 4h18M7 4v3M17 4v3"/><circle cx="12" cy="10.5" r="2.5"/><path d="M12 13v5M9 21l3-3 3 3"/>',
        'weightlifting' => '<path d="M6 7v10M18 7v10M3 9.5v5M21 9.5v5M6 12h12"/>',
        'running'       => '<circle cx="14.5" cy="4.5" r="1.8"/><path d="M8 21l3-6 3.5 2.5V21M11 15l-.8-5 4-2 3 3H21M10.2 10L7 12"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/* ---------- Profile & goals ---------- */
const GOALS = [
    'lose_fat'        => 'Lose fat',
    'build_muscle'    => 'Build muscle',
    'endurance'       => 'Improve endurance',
    'general_fitness' => 'General fitness',
];
/** Suggested weekly running distance (km) per goal; user can override. */
const GOAL_DEFAULT_KM = ['lose_fat' => 10, 'build_muscle' => 5, 'endurance' => 25, 'general_fitness' => 10];

function get_profile(PDO $pdo, int $uid): ?array {
    $s = $pdo->prepare('SELECT * FROM user_profiles WHERE user_id = ?');
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}
function bmi(float $kg, float $cm): float { return $cm > 0 ? $kg / (($cm / 100) ** 2) : 0.0; }
/** WHO adult categories. BMI is a rough indicator only. */
function bmi_label(float $b): string {
    return $b < 18.5 ? 'Underweight' : ($b < 25 ? 'Healthy range' : ($b < 30 ? 'Overweight' : 'Obese range'));
}
/** 0-100 progress from start weight toward target (works for losing or gaining). */
function weight_progress(float $start, float $cur, float $target): int {
    if (abs($target - $start) < 0.05) return 100;
    return (int)max(0, min(100, round(($cur - $start) / ($target - $start) * 100)));
}
