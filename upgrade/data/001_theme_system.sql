-- Theme System Tables
-- Add these tables to support the theme system

--
-- Table structure for table `theme_settings`
--

DROP TABLE IF EXISTS `theme_settings`;
CREATE TABLE `theme_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `firma_id` INT DEFAULT 1,
  `theme_name` VARCHAR(100) NOT NULL,
  `custom_css` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_theme` (`user_id`, `firma_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores theme preferences for users and firms';

--
-- Table structure for table `themes`
--

DROP TABLE IF EXISTS `themes`;
CREATE TABLE `themes` (
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

--
-- Dumping data for table `themes`
--

INSERT INTO `themes` (`name`, `display_name`, `description`, `is_system`, `is_enabled`) VALUES
('openxe_default', 'OpenXE Default', 'Standard light theme', 1, 1),
('dark_deep', 'Dark Deep', 'Very dark theme (#1e1e1e)', 1, 1),
('dark_medium', 'Dark Medium', 'Medium dark theme (#2d2d30)', 1, 1),
('dark_grey', 'Dark Grey', 'Grey-based dark theme (#353535)', 1, 1),
('high_contrast', 'High Contrast', 'Accessibility-optimized high contrast theme', 1, 1),
('compact', 'Compact', 'Space-saving theme with smaller fonts', 1, 1),
('corporate', 'Corporate', 'Customizable corporate branding theme', 1, 1);
