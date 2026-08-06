-- ============================================
-- Migración v2: plataformas gestionables por el admin (CRUD + imagen)
-- Reemplaza la tabla platform_images por una tabla platforms completa
-- que incluye las reglas de extracción.
--
-- Ejecuta esto en phpMyAdmin sobre la BD verifycodes.
-- (Si ya corriste migration_platforms.sql, este script hace el resto.)
-- ============================================
USE verifycodes;

-- 1) Asegurar la columna de caché por plataforma (por si no se corrió antes)
ALTER TABLE code_cache
  ADD COLUMN IF NOT EXISTS service_type VARCHAR(50) DEFAULT NULL AFTER mailbox_id;
DELETE FROM code_cache;

-- 2) Quitar la tabla antigua de solo-imágenes
DROP TABLE IF EXISTS platform_images;

-- 3) Nueva tabla de plataformas (nombre, imagen y reglas)
CREATE TABLE IF NOT EXISTS platforms (
  id INT PRIMARY KEY AUTO_INCREMENT,
  service_key VARCHAR(50) UNIQUE NOT NULL,
  label VARCHAR(80) NOT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  from_contains VARCHAR(150) DEFAULT NULL,
  subject_contains VARCHAR(255) DEFAULT NULL,
  code_min TINYINT DEFAULT 4,
  code_max TINYINT DEFAULT 6,
  travel_keywords VARCHAR(255) DEFAULT NULL,
  blocked_keywords VARCHAR(255) DEFAULT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4) Cargar las 5 plataformas que ya venían soportadas
INSERT IGNORE INTO platforms (service_key, label, from_contains, subject_contains, code_min, code_max, travel_keywords, blocked_keywords) VALUES
('netflix','Netflix','netflix.com','código,code,verificación,verification',4,6,'viaje,travel,estás de viaje,traveling','promo,oferta,novedades'),
('disney','Disney+','disneyplus.com','código,code,single sign-on,inicio de sesión',6,6,'','promo,oferta'),
('amazon','Amazon','amazon.com','código de verificación,verification code,otp',6,6,'','pedido,order,envío,promo'),
('max','Max','max.com','código,verification code',4,6,'household,hogar','promo'),
('spotify','Spotify','spotify.com','código,verification,inicio de sesión',4,6,'','promo,plan familiar');
