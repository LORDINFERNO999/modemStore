# ModemStore — Guía de instalación

Tienda de servicios de streaming (PHP + MySQL/MariaDB). Pensada para correr con **XAMPP**.

## Requisitos
- XAMPP (Apache + MySQL/MariaDB) con PHP 8.x

## Pasos

1. **Copiar el proyecto**
   - Coloca la carpeta `proyecto1` dentro de `C:\xampp\htdocs\`
     → ruta final: `C:\xampp\htdocs\proyecto1`

2. **Iniciar servicios**
   - Abre el **XAMPP Control Panel** y arranca **Apache** y **MySQL**.

3. **Importar la base de datos**
   - Opción A (phpMyAdmin): entra a http://localhost/phpmyadmin → pestaña **Importar** →
     elige el archivo `modemstores.sql` (está en la raíz del proyecto) → **Continuar**.
     *(El archivo ya crea la base `modemstores` automáticamente.)*
   - Opción B (consola):
     ```
     C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\proyecto1\modemstores.sql
     ```

4. **Abrir el sitio**
   - http://localhost/proyecto1

## Accesos de prueba
- **Administrador:** `admin@modemstores.com` / `password`
- **Cliente:** `perrinizzotopereza@gmail.com` / `cliente123`

> ⚠️ Por seguridad, cambia estas contraseñas tras la entrega (Admin → Usuarios → 🔑 Clave,
> o desde "Cambiar contraseña").

## Configuración
- Conexión a la base de datos: `includes/config.php` (por defecto usuario `root` sin
  contraseña, típico de XAMPP local).
- **Datos de pago (QR):** el administrador los gestiona desde el panel → **Datos de pago**
  (sube el QR y edita titular/llave/banco).

## Notas
- `CHANGELOG.md` contiene el detalle de todos los cambios realizados.
- `SECURIDAD.md` contiene el informe de seguridad y recomendaciones.
- La carpeta `assets/comprobantes/` guarda los comprobantes que suben los clientes.
