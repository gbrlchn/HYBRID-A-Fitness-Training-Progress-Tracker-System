-- Run this ONCE on an existing hybrid_fitness database (fresh installs: use schema.sql instead).
USE hybrid_fitness;
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
