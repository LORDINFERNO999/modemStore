<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../core/Encryption.php';
require_once __DIR__ . '/CodeExtractor.php';

/**
 * Requiere la extensión nativa "imap" de PHP habilitada en el hosting
 * (en Hostinger normalmente se activa desde el panel de PHP / php.ini).
 */
class ImapService
{
    /**
     * Busca el código/aviso más reciente para una cuenta de correo específica.
     * Nunca descarga todo el buzón: limita por fecha y por remitente/asunto.
     */
    public static function fetchLatest(array $mailbox, ?int $windowMinutes = null): ?array
    {
        $host = $mailbox['imap_host'];
        $port = $mailbox['imap_port'];
        $user = $mailbox['imap_user'];
        $pass = Encryption::decrypt($mailbox['password_encrypted']);
        $service = $mailbox['service_type'];

        // Ventana efectiva en minutos (por defecto la del config)
        $windowMinutes = $windowMinutes ?? IMAP_SEARCH_WINDOW_MINUTES;
        $windowMinutes = max(1, (int)$windowMinutes);
        $cutoff = time() - ($windowMinutes * 60);

        $rules = CodeExtractor::rulesFor($service);
        if (!$rules) {
            return null;
        }

        $mailboxStr = '{' . $host . ':' . $port . '/imap/ssl}INBOX';

        $connection = @imap_open($mailboxStr, $user, $pass, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if (!$connection) {
            error_log('IMAP connection failed for ' . $user . ': ' . imap_last_error());
            return null;
        }

        try {
            // IMAP SINCE trabaja con granularidad de DÍA, así que buscamos desde
            // el día del corte y luego afinamos por minutos con la fecha real del
            // correo (udate) en PHP. Así respetamos ventanas de 15 min, 1 h, etc.
            $sinceDate = date('d-M-Y', $cutoff);

            $uids = imap_search($connection, 'SINCE "' . $sinceDate . '"', SE_UID);

            if (!$uids) {
                return null;
            }

            // Revisamos del más reciente al más viejo
            rsort($uids);

            foreach ($uids as $uid) {
                $headerInfo = imap_headerinfo($connection, imap_msgno($connection, $uid));
                if (!$headerInfo) {
                    continue;
                }

                // Fecha real de llegada del mensaje. Si es más viejo que la ventana,
                // como vamos de más reciente a más viejo, ya no hay nada útil: paramos.
                $arrival = isset($headerInfo->udate) ? (int)$headerInfo->udate : 0;
                if ($arrival && $arrival < $cutoff) {
                    break;
                }

                $from = strtolower($headerInfo->fromaddress ?? '');
                $subject = self::decodeSubject($headerInfo->subject ?? '');

                // Filtro por remitente esperado del servicio
                if (stripos($from, $rules['from_contains']) === false) {
                    continue;
                }

                // Filtro rápido por asunto (si aplica alguna palabra clave)
                $subjectMatches = empty($rules['subject_contains']);
                foreach ($rules['subject_contains'] as $kw) {
                    if (mb_stripos($subject, $kw) !== false) {
                        $subjectMatches = true;
                        break;
                    }
                }
                if (!$subjectMatches) {
                    continue;
                }

                // Traemos solo el cuerpo de texto plano (no adjuntos completos)
                $body = self::fetchPlainBody($connection, $uid);

                $result = CodeExtractor::extract($service, $subject, $body);
                if ($result !== null) {
                    return $result; // primer resultado válido y más reciente
                }
            }

            return null;
        } finally {
            imap_close($connection);
        }
    }

    private static function decodeSubject(string $subject): string
    {
        $decoded = imap_mime_header_decode($subject);
        $out = '';
        foreach ($decoded as $part) {
            $out .= $part->text;
        }
        return $out;
    }

    private static function fetchPlainBody($connection, int $uid): string
    {
        $structure = imap_fetchstructure($connection, $uid, FT_UID);

        // Mensaje simple sin partes MIME
        if (!isset($structure->parts)) {
            return imap_body($connection, $uid, FT_UID | FT_PEEK);
        }

        // Buscar la primera parte de texto plano o html
        foreach ($structure->parts as $index => $part) {
            if ($part->subtype === 'PLAIN' || $part->subtype === 'HTML') {
                $body = imap_fetchbody($connection, $uid, (string)($index + 1), FT_UID | FT_PEEK);
                if ($part->encoding === 3) { // BASE64
                    $body = base64_decode($body);
                } elseif ($part->encoding === 4) { // QUOTED-PRINTABLE
                    $body = quoted_printable_decode($body);
                }
                return strip_tags($body);
            }
        }

        return '';
    }
}
