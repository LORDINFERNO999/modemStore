<?php
/**
 * Redirección de conveniencia para la raíz del proyecto.
 *
 * La aplicación real vive en /public. Así, abrir http://localhost/verifycodes/
 * lleva directamente al login sin tener que escribir /public/login.php.
 */
header('Location: public/index.php');
exit;
