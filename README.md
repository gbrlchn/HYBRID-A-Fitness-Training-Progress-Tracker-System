# HYBRID: Hybrid Fitness Training & Progress Tracker System

A web management system for people who combine **calisthenics, weightlifting and running**. It keeps all three kinds of training in one place, tracks personal goals, and tells you whether you are **progressing, in a plateau, or falling behind**, with advice on what to do next.

Built with native **PHP, MySQL, HTML5, CSS and vanilla JavaScript**. No frameworks or external libraries.

> **Author:** Gabriel Israel R. Chan  |  **Course:** BSCS 3C  |  **Subject:** Web Development

---

## The Problem

Hybrid trainees measure each activity differently: bodyweight work in sets and reps, lifting with added weight, running in distance, time and pace. No single tool handles all three, so records end up scattered across notes, spreadsheets and separate apps, and it becomes hard to tell whether training is actually working.

HYBRID puts everything in one private portal and turns the raw logs into clear progress feedback.

## Features

| Module | What it does |
|---|---|
| **Authentication** | Register, log in and log out. Passwords are hashed; each user sees only their own data. |
| **Goal setup (profile)** | Asks for height, weight, target weight, main goal, training days per week and weekly running goal. Editable anytime. |
| **Dashboard** | Greeting, today's focus, active-days ring, goal progress, quick stats and recent activity. |
| **Weekly split** | Plan what to train each day; today is highlighted. |
| **Hybrid workout logger** | Dynamic form for calisthenics, weightlifting or running. Use **+ Add exercise** to log several exercises in one session. Running pace is computed automatically. |
| **History & reports** | Filter by category or exercise, edit or delete records, and view personal bests per exercise. |
| **Goal tracking** | Sessions per week, weekly running km, weight progress toward target, BMI, and a quick weight update. |
| **Progress check** | Form score (0-100), status per area, weekly streak, level/XP, and an advice dialog for each card. |

## Progress Check: how it works

The system compares your **last 4 weeks** with the **4 weeks before**, using fixed, readable rules:

| Area | Measured by | Progressing | Falling behind |
|---|---|---|---|
| Strength | Best estimated 1RM per lift (Epley: `weight x (1 + reps/30)`) | +2% or more | -3% or more |
| Calisthenics | Best reps in a single set | +5% or more | -10% or more |
| Running | Average pace, and weekly distance | pace +1.5%, distance +5% | pace -3%, distance -15% |
| Consistency | Average active days per week vs. your target | 85% or more of target | below 50% of target |

Anything between the two thresholds is a **Plateau**. A lift not trained recently shows *Not trained lately*, and a brand-new exercise shows *Building baseline*. The **form score** is 40% consistency and 60% progression. The meter appears once you have logged on at least 3 different days.

The thresholds are plain numbers in `analyze_progress()` in `includes/progress.php`, so they are easy to tune. Advice is general guidance based on your logs, not medical advice.

## Tech Stack

- **Backend:** PHP 8.1+ (PDO)
- **Database:** MySQL / MariaDB
- **Frontend:** HTML5, CSS3 (light and dark themes), vanilla JavaScript

## Installation (XAMPP)

1. Install [XAMPP](https://www.apachefriends.org/) (PHP 8.1 or newer) and start **Apache** and **MySQL** in the Control Panel.
2. Copy the `fitness-tracker` folder into `C:\xampp\htdocs\` (macOS: `/Applications/XAMPP/htdocs/`, Linux: `/opt/lampp/htdocs/`).
3. Open **http://localhost/phpmyadmin**, choose **Import**, select `database/schema.sql`, and click **Go**. This creates the `hybrid_fitness` database and all tables.
4. Check the database credentials in `includes/db_connect.php`. The defaults match a fresh XAMPP install:
   ```php
   const DB_HOST = 'localhost';
   const DB_NAME = 'hybrid_fitness';
   const DB_USER = 'root';
   const DB_PASS = '';
   ```
5. Open **http://localhost/fitness-tracker/register.php**.

**Upgrading an existing install:** if you created the database before the goal-setup feature, import `database/migration_001_profiles.sql` once to add the `user_profiles` table. Existing users will be asked the goal questions on their next visit.

## Using the System

1. **Register.** You are logged in automatically and taken to the goal questions.
2. **Set your goals** (height, weight, target weight, main goal, training days, weekly km).
3. **Log workouts** from the **Log** page. Pick a type; add as many exercises as you need for calisthenics or weightlifting.
4. **Check the dashboard** for goal progress, your weekly split and the **Progress check**. Click any card or the meter to see what changed and what to do next.
5. **Review History** to filter, edit or delete records and see personal bests.

## Project Structure

```
fitness-tracker/
├── index.php                  Redirects to the dashboard
├── register.php  login.php  logout.php
├── profile.php                Goal questions (onboarding) and profile editing
├── dashboard.php              Hero, progress check, goals, weekly split, recent activity
├── log_workout.php            Create/edit workouts (multi-exercise logger)
├── history.php                Filter, edit, delete, personal bests
├── includes/
│   ├── db_connect.php         PDO connection (edit credentials here)
│   ├── bootstrap.php          Session, CSRF, formatting and goal helpers
│   ├── progress.php           Progress analysis, scoring and advice
│   └── header.php  footer.php Shared layout
├── assets/
│   ├── css/style.css          Minimalist theme (auto dark mode)
│   └── js/app.js              Dynamic logger, dialogs, small UI helpers
└── database/
    ├── schema.sql             Full schema (fresh install)
    └── migration_001_profiles.sql   Adds user_profiles to an existing database
```

## Database

| Table | Purpose |
|---|---|
| `users` | Accounts (username, email, password hash) |
| `user_profiles` | Height, weight, start/target weight, goal type, days per week, weekly km goal |
| `weekly_schedule` | One row per user per weekday with that day's focus |
| `workout_logs` | All workouts in one table; `workout_type` is `calisthenics`, `weightlifting` or `running`. Columns that do not apply to a type are `NULL`. Pace is calculated from distance and duration, not stored. |

All user-owned tables use foreign keys with `ON DELETE CASCADE`.

## Security

- Passwords stored with `password_hash()` and checked with `password_verify()`.
- PDO prepared statements everywhere (SQL injection).
- CSRF token on every POST form.
- Output escaped with `htmlspecialchars` (XSS).
- Session ID regenerated on login.
- Every edit, delete and read of a record checks that it belongs to the logged-in user.
- Server-side validation of all inputs, independent of the JavaScript.

## Troubleshooting

| Problem | Fix |
|---|---|
| `Failed to open stream: includes/bootstrap.php` | The `includes` folder is missing or misplaced. Re-extract the project keeping the folder structure. |
| "Database connection failed" | MySQL is not running, the database was not imported, or `DB_PASS` in `db_connect.php` does not match your MySQL root password. |
| Apache will not start | Port 80 is in use (Skype, IIS). Change Apache's port in `httpd.conf` and use `http://localhost:8080/fitness-tracker/`. |
| Styles look old after an update | Hard refresh with `Ctrl + F5`. |
| Progress meter not showing | Log workouts on at least 3 different days. |

## Limitations and Ideas for Future Work

- Metric units only (kg, km, cm).
- One workout type per save; log a mixed session as separate saves.
- Admin and coach roles are not built into the app. A coach view of a member's progress reports is a natural next step.
- Possible additions: progress charts, weight history over time, CSV export, login rate limiting, and achievement badges.

## License

Developed as an academic project for the Web Development course. All rights reserved by the author.
