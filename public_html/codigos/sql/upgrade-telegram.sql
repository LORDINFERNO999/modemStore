-- ─────────────────────────────────────────────────────────────────
-- upgrade-telegram.sql
-- Configura las notificaciones por Telegram (reemplazan a CallMeBot).
--
-- Guarda el token del bot y el chat_id del destinatario en la tabla
-- `configuracion`. Ejecuta este archivo una sola vez en tu base de datos
-- (phpMyAdmin › Importar, o por consola).
--
-- ⚠️ Reemplaza los valores de ejemplo por los tuyos antes de ejecutar.
-- ─────────────────────────────────────────────────────────────────

INSERT INTO configuracion (clave, valor) VALUES
  ('telegram_token',   '8922050450:AAFf_2FaINjQa2IFbITZm1XrEMyJi7sjPv8'),
  ('telegram_chat_id', '5241646333')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
