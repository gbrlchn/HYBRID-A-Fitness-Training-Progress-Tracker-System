-- (Includes user_profiles from migration_001.)
-- Hybrid Fitness Training & Progress Tracker :: MySQL schema
CREATE DATABASE IF NOT EXISTS hybrid_fitness
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hybrid_fitness;

-- Users ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  email         VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,            -- from password_hash()
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Weekly split: one row per user per weekday (1 = Monday ... 7 = Sunday) --
CREATE TABLE IF NOT EXISTS weekly_schedule (
  user_id     INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  focus       VARCHAR(100) NOT NULL DEFAULT 'Rest',
  PRIMARY KEY (user_id, day_of_week),
  CONSTRAINT fk_sched_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_dow CHECK (day_of_week BETWEEN 1 AND 7)
) ENGINE=InnoDB;

-- Workout logs: ONE table, three metric types ------------------------------
--  calisthenics  -> exercise_name, sets, reps
--  weightlifting -> exercise_name, sets, reps, weight_kg
--  running       -> distance_km, duration_seconds   (pace is derived)
-- Columns that don't apply to a type are NULL.
CREATE TABLE IF NOT EXISTS workout_logs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  workout_type     ENUM('calisthenics','weightlifting','running') NOT NULL,
  exercise_name    VARCHAR(100) NOT NULL,
  sets             SMALLINT UNSIGNED NULL,
  reps             SMALLINT UNSIGNED NULL,
  weight_kg        DECIMAL(6,2) NULL,
  distance_km      DECIMAL(6,2) NULL,
  duration_seconds INT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  logged_on        DATE NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_date (user_id, logged_on),
  INDEX idx_user_type (user_id, workout_type),
  INDEX idx_user_exercise (user_id, exercise_name)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_profiles (
  user_id          INT UNSIGNED PRIMARY KEY,
  height_cm        DECIMAL(5,1) NOT NULL,
  weight_kg        DECIMAL(5,1) NOT NULL,           -- current weight
  start_weight_kg  DECIMAL(5,1) NOT NULL,           -- baseline for progress bar
  target_weight_kg DECIMAL(5,1) NULL,
  goal_type        ENUM('lose_fat','build_muscle','endurance','general_fitness') NOT NULL DEFAULT 'general_fitness',
  days_per_week    TINYINT UNSIGNED NOT NULL DEFAULT 4,
  weekly_km_goal   DECIMAL(5,1) NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
