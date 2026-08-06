<?php
require_once __DIR__ . '/../core/Database.php';

/**
 * Reglas de extracción por plataforma.
 *
 * Las reglas ahora viven en la tabla `platforms` (gestionadas por el admin):
 *   - from_contains / subject_contains → criterio de búsqueda IMAP.
 *   - code_min / code_max → patrón del código (nº de dígitos).
 *   - travel_keywords → avisos de "estoy de viaje" permitidos.
 *   - blocked_keywords → palabras propias de la plataforma que se descartan.
 *
 * Además, en TODAS las plataformas se bloquean frases de cambio de contraseña
 * y de correo, y se exige contexto de verificación antes de aceptar un número.
 */
class CodeExtractor
{
    /** Frases de cambio de correo/email. Se bloquean en todas las plataformas. */
    private const EMAIL_CHANGE_KEYWORDS = [
        'cambio de correo', 'cambiar correo', 'cambiar tu correo', 'cambiaste tu correo',
        'has cambiado tu correo', 'nueva dirección de correo', 'nueva direccion de correo',
        'nuevo correo electrónico', 'nuevo correo electronico', 'actualiza tu correo',
        'change of email', 'change your email', 'update your email',
        'email address has been changed', 'your email was changed', 'new email address',
    ];

    /** Frases de cambio/restablecimiento de contraseña (NO la palabra suelta). */
    private const PASSWORD_CHANGE_KEYWORDS = [
        'cambio de contraseña', 'cambio de contrasena', 'cambiar contraseña', 'cambiar contrasena',
        'cambiar tu contraseña', 'has cambiado tu contraseña', 'has cambiado tu contrasena',
        'cambiaste tu contraseña', 'nueva contraseña', 'nueva contrasena',
        'restablece tu contraseña', 'restablecer contraseña', 'restablece tu contrasena',
        'restablecer contrasena', 'restablece', 'restablecer', 'actualiza tu contraseña',
        'reset your password', 'password reset', 'reset password', 'change your password',
        'change password',
    ];

    /** Señales de que el mensaje es de verificación/inicio de sesión. */
    private const CODE_CONTEXT = [
        'código', 'codigo', 'code', 'verificación', 'verificacion', 'verification',
        'otp', 'inicio de sesión', 'inicio de sesion', 'sign-in', 'sign in',
        'one-time', 'un solo uso', 'temporary access', 'acceso temporal',
    ];

    /** Caché por request de las plataformas cargadas desde la BD. */
    private static ?array $platforms = null;

    /**
     * Carga las plataformas activas desde la BD una sola vez por request.
     * Si la tabla no existe (falta la migración), devuelve un arreglo vacío.
     */
    private static function load(): array
    {
        if (self::$platforms !== null) {
            return self::$platforms;
        }
        self::$platforms = [];
        try {
            $rows = Database::get()
                ->query('SELECT * FROM platforms WHERE active = 1 ORDER BY label')
                ->fetchAll();
            foreach ($rows as $row) {
                self::$platforms[$row['service_key']] = $row;
            }
        } catch (\PDOException $e) {
            // Tabla ausente: se degradan las funciones sin romper la app.
            self::$platforms = [];
        }
        return self::$platforms;
    }

    /** Fuerza recargar en la próxima llamada (tras cambios del admin). */
    public static function clearCache(): void
    {
        self::$platforms = null;
    }

    /** Convierte "a, b ,c" en ['a','b','c'] (sin vacíos). */
    private static function csv(?string $value): array
    {
        if (!$value) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }

    /** Construye el patrón del código a partir del rango de dígitos. */
    private static function buildPattern(int $min, int $max): string
    {
        $min = max(1, $min);
        $max = max($min, $max);
        $quant = $min === $max ? '{' . $min . '}' : '{' . $min . ',' . $max . '}';
        return '/\b(\d' . $quant . ')\b/';
    }

    /** ¿Es una plataforma soportada (existe y está activa)? */
    public static function isSupported(string $serviceType): bool
    {
        return isset(self::load()[$serviceType]);
    }

    /**
     * Reglas listas para usar por ImapService, o null si no existe la plataforma.
     */
    public static function rulesFor(string $serviceType): ?array
    {
        $p = self::load()[$serviceType] ?? null;
        if (!$p) {
            return null;
        }
        return [
            'from_contains'    => $p['from_contains'] ?? '',
            'subject_contains' => self::csv($p['subject_contains'] ?? ''),
            'code_pattern'     => self::buildPattern((int)($p['code_min'] ?? 4), (int)($p['code_max'] ?? 6)),
            'travel_keywords'  => self::csv($p['travel_keywords'] ?? ''),
            'blocked_keywords' => self::csv($p['blocked_keywords'] ?? ''),
        ];
    }

    /** Lista [clave => etiqueta] para poblar selectores. */
    public static function availableServices(): array
    {
        $out = [];
        foreach (self::load() as $key => $p) {
            $out[$key] = $p['label'];
        }
        return $out;
    }

    /** Lista [clave => ['label'=>, 'image'=>]] para mostrar en el dashboard. */
    public static function platformsForDisplay(): array
    {
        $out = [];
        foreach (self::load() as $key => $p) {
            $out[$key] = ['label' => $p['label'], 'image' => $p['image_path'] ?? null];
        }
        return $out;
    }

    /**
     * Analiza un cuerpo de correo y decide si es código válido, aviso de viaje,
     * o contenido a descartar (null). Busca las reglas de la plataforma en BD.
     */
    public static function extract(string $serviceType, string $subject, string $body): ?array
    {
        $rules = self::rulesFor($serviceType);
        if (!$rules) {
            return null;
        }
        return self::extractWithRules($rules, $subject, $body);
    }

    /**
     * Núcleo de extracción, puro y testeable (no toca la BD).
     */
    public static function extractWithRules(array $rules, string $subject, string $body): ?array
    {
        $haystack = mb_strtolower($subject . ' ' . $body);

        // 1) Bloquear lo que nunca debe mostrarse
        $blocked = array_merge(
            $rules['blocked_keywords'] ?? [],
            self::EMAIL_CHANGE_KEYWORDS,
            self::PASSWORD_CHANGE_KEYWORDS
        );
        foreach ($blocked as $kw) {
            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) {
                return null;
            }
        }

        // 2) Aviso de "estoy de viaje" permitido
        foreach ($rules['travel_keywords'] ?? [] as $kw) {
            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) {
                return ['type' => 'travel', 'code' => null];
            }
        }

        // 3) Código solo si hay contexto real de verificación
        if (self::hasCodeContext($haystack) && preg_match($rules['code_pattern'], $body, $m)) {
            return ['type' => 'code', 'code' => $m[1]];
        }

        return null;
    }

    private static function hasCodeContext(string $haystackLower): bool
    {
        foreach (self::CODE_CONTEXT as $kw) {
            if (mb_strpos($haystackLower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}
