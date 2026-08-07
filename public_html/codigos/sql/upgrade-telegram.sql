-- ─────────────────────────────────────────────────────────────────
-- upgrade-telegram.sql
-- Configura las notificaciones por Telegram (reemplazan a CallMeBot).
--
-- Guarda el token del bot y los chat_id de los destinatarios en la tabla
-- `configuracion`. Ejecuta este archivo una sola vez en tu base de datos
-- (phpMyAdmin › SQL, o por consola).
--
-- Destinatarios (separados por coma):
--   5241646333 = Cliente (@Julian_S777)
--   7329286106 = Admin  (Jonathan Smith)
-- ─────────────────────────────────────────────────────────────────

INSERT INTO configuracion (clave, valor) VALUES
  ('telegram_token',   '8922050450:AAFf_2FaINjQa2IFbITZm1XrEMyJi7sjPv8'),
  ('telegram_chat_id', '5241646333,7329286106')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
