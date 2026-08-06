# ModemStore — Resumen de entrega (lista de cambios)

Lista de trabajos realizados sobre el proyecto, en lenguaje claro para cotización.

## A. Puesta en marcha y corrección de base
1. **Reparación de la base de datos y arranque del sitio** — Faltaban 6 tablas; se crearon,
   se montó la base y se dejó el sitio funcionando.
2. **Corrección de errores y archivos incompletos** — Se arregló el inicio de sesión y se
   completaron páginas/funciones que estaban vacías.

## B. Seguridad
3. **Blindaje de seguridad** — Protección CSRF en todos los formularios, bloqueo por intentos
   fallidos de login, sesiones endurecidas con cierre por inactividad, protección de archivos
   sensibles e **informe de seguridad** con recomendaciones.

## C. Cuentas y administración
4. **Cambio de contraseña** — Para cliente y administrador, de forma segura.
5. **Gestión de usuarios (admin)** — Ver, editar y **resetear contraseñas** (con generador).
6. **Panel de administración integrado** — Todo el admin se ve dentro del mismo panel (antes
   abría pestañas nuevas). Se crearon las páginas de **Gestionar pedidos** y **Stock**.

## D. Sistema de ventas (núcleo)
7. **Compra por transferencia con QR** — Ventana con QR de pago, datos e instrucciones, y
   **adjuntar comprobante**.
8. **Datos de pago configurables** — El admin sube su QR y edita la cuenta sin tocar código.
9. **Flujo de pedidos con entrega manual** — Compra → pendiente → el admin valida y **entrega
   usuario y contraseña** → el cliente los recibe.
10. **Campanita de notificaciones** — Aviso en tiempo real al admin de cada nueva solicitud.
11. **Vigencia de servicios** — Días restantes que bajan solos y **bloqueo al vencer**.
12. **Rechazo con motivo** — El admin rechaza con una nota que le llega al cliente.
13. **Historial y trazabilidad de ventas** — Resumen con valores (vendido, en espera, etc.).

## E. Diseño e imagen profesional
14. **Identidad visual unificada** — Color de marca, tipografía y **logo** unificados + favicon.
15. **Rediseño de la tienda** — Tarjetas con banner, precios y botones con estilo de marca.
16. **Diseño responsive para celulares** + ajuste para verse bien al 100% de zoom.

## F. Mantenimiento y entrega
17. **Migración del modelo de pago** — Se retiró el modelo anterior de "billetera/saldo" y se
    limpió el código sobrante.
18. **Documentación** — Bitácora de cambios (`CHANGELOG.md`) e instrucciones (`INSTALACION.md`).
