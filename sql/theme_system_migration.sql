-- Theme System Database Migration
-- Run this script to add theme support to OpenXE

-- 1. Theme Settings Table (stores user and firma theme choices)
CREATE TABLE IF NOT EXISTS `theme_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `firma_id` INT DEFAULT 1,
  `theme_name` VARCHAR(100) NOT NULL,
  `custom_css` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_theme` (`user_id`, `firma_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores theme preferences for users and firms';

-- 2. Theme Metadata Table (stores available theme information)
CREATE TABLE IF NOT EXISTS `themes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) UNIQUE NOT NULL COMMENT 'Internal theme name (folder name)',
  `display_name` VARCHAR(200) NOT NULL COMMENT 'Display name shown in UI',
  `description` TEXT COMMENT 'Theme description',
  `author` VARCHAR(100) DEFAULT 'OpenXE Team',
  `version` VARCHAR(20) DEFAULT '1.0.0',
  `preview_image` VARCHAR(255) COMMENT 'Path to preview screenshot',
  `is_system` TINYINT(1) DEFAULT 0 COMMENT '1 = system theme, cannot be deleted',
  `is_enabled` TINYINT(1) DEFAULT 1 COMMENT '1 = enabled, 0 = disabled',
  `config_json` TEXT COMMENT 'Theme configuration as JSON',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Metadata for available themes';

-- 3. Modify User Table (add theme permission)
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `can_change_theme` TINYINT(1) DEFAULT 1 
COMMENT 'Allow user to change their own theme' 
AFTER `activ`;

-- 4. Modify Firmendaten Table (add default theme settings)
ALTER TABLE `firmendaten` 
ADD COLUMN IF NOT EXISTS `default_theme` VARCHAR(100) DEFAULT 'openxe_default' 
COMMENT 'Default theme for all users' 
AFTER `name`;

ALTER TABLE `firmendaten` 
ADD COLUMN IF NOT EXISTS `allow_user_themes` TINYINT(1) DEFAULT 1 
COMMENT 'Allow users to override firm theme';

-- 5. Insert default themes
INSERT IGNORE INTO `themes` (`name`, `display_name`, `description`, `is_system`, `is_enabled`) VALUES
('openxe_default', 'OpenXE Default', 'Standard light theme', 1, 1),
('dark_deep', 'Dark Deep', 'Very dark theme (#1e1e1e)', 1, 1),
('dark_medium', 'Dark Medium', 'Medium dark theme (#2d2d30)', 1, 1),
('dark_grey', 'Dark Grey', 'Grey-based dark theme (#353535)', 1, 1),
('high_contrast', 'High Contrast', 'Accessibility-optimized high contrast theme', 1, 1),
('compact', 'Compact', 'Space-saving theme with smaller fonts', 1, 1),
('corporate', 'Corporate', 'Customizable corporate branding theme', 1, 1);

-- 6. Create cache directory for theme CSS (handled by PHP, but add note)
-- NOTE: PHP will create www/cache/themes/ directory automatically

COMMIT;
