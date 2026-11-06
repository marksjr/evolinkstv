-- ========================================================
-- Evo Links TV · Schema do Banco de Dados MySQL
-- Compatível com cPanel, VPS e Hospedagem Compartilhada
-- ========================================================

-- Se estiver em VPS/Dedicado e desejar criar o banco manualmente:
-- CREATE DATABASE IF NOT EXISTS `pro` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `pro`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `url` VARCHAR(2048) NOT NULL,
  `url_backup` VARCHAR(2048) DEFAULT NULL,
  `category` VARCHAR(60) DEFAULT NULL,
  `player_type` VARCHAR(20) NOT NULL DEFAULT 'auto',
  `drm_key` VARCHAR(255) DEFAULT NULL,
  `popup_ads` MEDIUMTEXT DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_channels_slug` (`slug`),
  KEY `idx_channels_active_sort` (`active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` LONGTEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash` CHAR(64) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip_time` (`ip_hash`, `created_at`),
  KEY `idx_attempts_user_time` (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `protected_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `filename` VARCHAR(150) NOT NULL DEFAULT 'canal.php',
  `allowed_domains` TEXT DEFAULT NULL,
  `block_direct` TINYINT(1) NOT NULL DEFAULT 1,
  `block_iframe` TINYINT(1) NOT NULL DEFAULT 1,
  `anti_adblock` TINYINT(1) NOT NULL DEFAULT 1,
  `anti_sandbox` TINYINT(1) NOT NULL DEFAULT 1,
  `anti_inspect` TINYINT(1) NOT NULL DEFAULT 1,
  `obfuscate` TINYINT(1) NOT NULL DEFAULT 1,
  `redirect_url` VARCHAR(2048) DEFAULT NULL,
  `popup_ads` LONGTEXT DEFAULT NULL,
  `source_code` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_protected_files_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('protect_iframe', '1'),
('block_direct_access', '1'),
('anti_adblock', '1'),
('anti_sandbox', '1'),
('inspect_protection', '1'),
('allowed_domains', 'localhost,127.0.0.1'),
('redirect_url', ''),
('watermark_text', ''),
('player_brand_name', 'Ao Vivo'),
('msg_adblock_title', 'Desative o bloqueador de anúncios'),
('msg_adblock_desc', 'Este player não funciona com AdBlock ativo. Desative o bloqueador para continuar.'),
('msg_adblock_button', 'Já desativei — recarregar'),
('msg_sandbox_title', 'Sandbox detectado'),
('msg_sandbox_desc', 'Este player não funciona dentro de um iframe com atributos de restrição (sandbox). Remova o sandbox para continuar.'),
('msg_sandbox_button', 'Recarregar'),
('msg_inspect_title', 'Inspeção não permitida.'),
('msg_direct_title', 'Acesso Não Permitido'),
('msg_direct_desc', 'Este player não pode ser aberto diretamente na barra do navegador. Assista através do site oficial incorporado.'),
('msg_unauthorized_title', 'Acesso não autorizado'),
('msg_unauthorized_desc', 'A reprodução deste canal é restrita a domínios previamente autorizados.'),
('player_ads_code', '');
