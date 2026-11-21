<?php
declare(strict_types=1);

/**
 * Sends email through Hostinger. Drivers:
 *  - smtp: a Hostinger mailbox (smtp.hostinger.com:465, SSL). Most reliable.
 *  - mail: PHP mail() (works on Hostinger shared hosting).
 *  - log:  writes to the upload dir's mail.log (local development).
 */
function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    $driver = config('mail.driver', 'log');
    $from = config('mail.from', 'no-reply@localhost');
    $fromName = config('mail.from_name', 'Cafresco');
    $html = $html ?? nl2br(htmlspecialchars($text));
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . mb_encode_mimeheader($fromName) . " <$from>",
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . mail_wrap($html) . "\r\n--$boundary--";
    $subjectEnc = mb_encode_mimeheader($subject, 'UTF-8');

    try {
        if ($driver === 'log') {
            file_put_contents(upload_dir() . '/mail.log', "To: $to\nSubject: $subject\n\n$text\n-----\n", FILE_APPEND);
            return true;
        }
        if ($driver === 'mail') {
            return mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f' . $from);
        }
        return smtp_send($to, $subjectEnc, $body, $headers);
    } catch (Throwable $e) {
        error_log('mail failed: ' . $e->getMessage());
        return false;
    }
}

function mail_wrap(string $inner): string
{
    return '<div style="font-family:Helvetica,Arial,sans-serif;max-width:520px;margin:0 auto;border:2px solid #111;padding:24px;color:#111">'
        . '<div style="font-weight:800;letter-spacing:.2em;font-size:18px;margin-bottom:16px">CAFRESCO</div>'
        . $inner . '</div>';
}

function smtp_send(string $to, string $subject, string $body, array $headers): bool
{
    $host = config('mail.host', 'smtp.hostinger.com');
    $port = (int)config('mail.port', 465);
    $fp = stream_socket_client("ssl://$host:$port", $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("SMTP connect: $errstr");
    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!str_starts_with($r, $expect)) throw new RuntimeException("SMTP '$c' => $r");
        return $r;
    };
    $read();
    $cmd('EHLO ' . (parse_url((string)config('app_url'), PHP_URL_HOST) ?: 'localhost'), '250');
    $cmd('AUTH LOGIN', '334');
    $cmd(base64_encode((string)config('mail.user')), '334');
    $cmd(base64_encode((string)config('mail.pass')), '235');
    $cmd('MAIL FROM:<' . config('mail.from') . '>', '250');
    $cmd("RCPT TO:<$to>", '250');
    $cmd('DATA', '354');
    $msg = implode("\r\n", array_merge(["To: <$to>", "Subject: $subject", 'Date: ' . date('r')], $headers)) . "\r\n\r\n"
         . preg_replace('/^\./m', '..', $body);
    $cmd($msg . "\r\n.", '250');
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}
