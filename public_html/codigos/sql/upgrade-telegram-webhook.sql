-- ─────────────────────────────────────────────────────────────────
-- upgrade-telegram-webhook.sql
-- Clave secreta para el webhook de Telegram (botones Aprobar/Rechazar).
-- Ejecuta este SQL una sola vez en tu base de datos.
-- ─────────────────────────────────────────────────────────────────

INSERT INTO configuracion (clave, valor) VALUES
  ('telegram_webhook_secret', 'mstg_9a7aaf1b7463db71c43b8e26f8932f8e')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
