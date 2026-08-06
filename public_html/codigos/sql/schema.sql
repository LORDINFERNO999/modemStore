-- ============================================
-- VerifyCodes - Esquema de base de datos
-- ============================================

CREATE DATABASE IF NOT EXISTS verifycodes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE verifycodes;

CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') DEFAULT 'user',
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE mailboxes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(150) NOT NULL,
  imap_host VARCHAR(150) NOT NULL DEFAULT 'imap.gmail.com',
  imap_port INT NOT NULL DEFAULT 993,
  imap_user VARCHAR(150) NOT NULL,
  password_encrypted TEXT NOT NULL,
  service_type VARCHAR(50) NOT NULL,   -- netflix, disney, spotify, amazon, max...
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_mailbox_access (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  mailbox_id INT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_mailbox (user_id, mailbox_id)
);

CREATE TABLE code_cache (
  id INT PRIMARY KEY AUTO_INCREMENT,
  mailbox_id INT NOT NULL,
  service_type VARCHAR(50) DEFAULT NULL,   -- plataforma consultada (permite varias por buzón)
  code VARCHAR(20) DEFAULT NULL,
  message_type ENUM('code','travel') NOT NULL,
  valid_until TIMESTAMP NULL,
  fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE CASCADE
);

-- Plataformas gestionadas por el administrador (nombre, imagen y reglas de
-- extracción). Antes estas reglas estaban fijas en el código; ahora se pueden
-- agregar, editar y eliminar desde el panel admin.
CREATE TABLE platforms (
  id INT PRIMARY KEY AUTO_INCREMENT,
  service_key VARCHAR(50) UNIQUE NOT NULL,   -- clave interna (ej: netflix)
  label VARCHAR(80) NOT NULL,                -- nombre visible (ej: Netflix)
  image_path VARCHAR(255) DEFAULT NULL,      -- ruta de la imagen subida (relativa a /public)
  from_contains VARCHAR(150) DEFAULT NULL,   -- remitente esperado (ej: netflix.com)
  subject_contains VARCHAR(255) DEFAULT NULL,-- palabras clave de asunto (separadas por coma)
  code_min TINYINT DEFAULT 4,                -- mínimo de dígitos del código
  code_max TINYINT DEFAULT 6,                -- máximo de dígitos del código
  travel_keywords VARCHAR(255) DEFAULT NULL, -- avisos de viaje permitidos (coma)
  blocked_keywords VARCHAR(255) DEFAULT NULL,-- palabras a bloquear propias de la plataforma (coma)
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO platforms (service_key, label, from_contains, subject_contains, code_min, code_max, travel_keywords, blocked_keywords) VALUES
('netflix','Netflix','netflix.com','código,code,verificación,verification',4,6,'viaje,travel,estás de viaje,traveling','promo,oferta,novedades'),
('disney','Disney+','disneyplus.com','código,code,single sign-on,inicio de sesión',6,6,'','promo,oferta'),
('amazon','Amazon','amazon.com','código de verificación,verification code,otp',6,6,'','pedido,order,envío,promo'),
('max','Max','max.com','código,verification code',4,6,'household,hogar','promo'),
('spotify','Spotify','spotify.com','código,verification,inicio de sesión',4,6,'','promo,plan familiar');

CREATE TABLE query_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  mailbox_id INT,
  result ENUM('success','no_code','denied','error') NOT NULL,
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Usuario admin por defecto (usuario: admin / password: admin123 -> CAMBIAR)
-- Hash generado con password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (username, password_hash, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
