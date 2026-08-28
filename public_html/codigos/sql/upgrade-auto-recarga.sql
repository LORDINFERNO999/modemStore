-- ─────────────────────────────────────────────────────────────────
-- upgrade-auto-recarga.sql
-- Interruptor de aprobación automática de recargas (control manual del admin).
--   auto_recarga_activa: '1' = activada, '0' = desactivada (por defecto 0)
--   auto_recarga_tope:   monto máximo que se auto-aprueba (por defecto 40000)
-- El admin lo activa/desactiva desde el panel de Recargas.
-- ─────────────────────────────────────────────────────────────────

INSERT INTO configuracion (clave, valor) VALUES
  ('auto_recarga_activa', '0'),
  ('auto_recarga_tope',   '40000')
ON DUPLICATE KEY UPDATE valor = valor;  -- no pisa el valor si ya existe
