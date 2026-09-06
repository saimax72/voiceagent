<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\Settings;

/**
 * Sends email via SMTP (no dependencies), PHP mail(), or a log file.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        $driver = (string) (Settings::get('mail_driver') ?: App::config('mail.driver', 'mail'));
        $fromEmail = (string) (Settings::get('mail_from_email') ?: App::config('mail.from_email', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $fromName = (string) (Settings::get('mail_from_name') ?: App::config('mail.from_name', app_name()));
        $text ??= trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h[1-6]|/li)>#i', "\n", $html) ?? $html)));

        try {
            return match ($driver) {
                'smtp' => self::sendSmtp($to, $subject, $html, $text, $fromEmail, $fromName),
                'log' => self::sendLog($to, $subject, $text),
                default => self::sendMail($to, $subject, $html, $text, $fromEmail, $fromName),
            };
        } catch (\Throwable $e) {
            Logger::error('Mail send failed: ' . $e->getMessage(), ['to' => $to, 'subject' => $subject]);
            return false;
        }
    }

    /** Render an email template (app/Views/emails/{name}.php) inside the email layout. */
    public static function render(string $template, array $data = []): string
    {
        $data['app_name'] = app_name();
        $body = View::partial('emails/' . $template, $data);
        return View::partial('emails/layout', array_merge($data, ['body' => $body]));
    }

    private static function buildMime(string $html, string $text, string $fromEmail, string $fromName): array
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'From' => self::encodeName($fromName) . ' <' . $fromEmail . '>',
            'Reply-To' => $fromEmail,
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer' => 'VoiceAgent',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";
        return [$headers, $body];
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/[^\x20-\x7e]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : '"' . addslashes($name) . '"';
    }

    private static function sendMail(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
    {
        [$headers, $body] = self::buildMime($html, $text, $fromEmail, $fromName);
        $headerString = '';
        foreach ($headers as $k => $v) {
            $headerString .= $k . ': ' . $v . "\r\n";
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $encodedSubject, $body, $headerString, '-f' . $fromEmail);
    }

    private static function sendLog(string $to, string $subject, string $text): bool
    {
        Logger::log('mail', "To: {$to} | Subject: {$subject}\n" . $text);
        return true;
    }

    private static function sendSmtp(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
    {
        $cfg = (array) App::config('mail.smtp', []);
        $host = (string) (Settings::get('smtp_host') ?: ($cfg['host'] ?? ''));
        $port = (int) (Settings::get('smtp_port') ?: ($cfg['port'] ?? 587));
        $encryption = strtolower((string) (Settings::get('smtp_encryption') ?: ($cfg['encryption'] ?? 'tls')));
        $username = (string) (Settings::get('smtp_username') ?: ($cfg['username'] ?? ''));
        $password = (string) (Settings::get('smtp_password') ?: ($cfg['password'] ?? ''));
        if ($host === '') {
            throw new \RuntimeException('SMTP host not configured');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 20);
        $read = static function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $command, array $expect) use ($socket, $read): string {
            fwrite($socket, $command . "\r\n");
            $reply = $read();
            $code = (int) substr($reply, 0, 3);
            if (!in_array($code, $expect, true)) {
                $safe = str_starts_with($command, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $command) ? '[credentials]' : $command;
                throw new \RuntimeException('SMTP error after "' . $safe . '": ' . trim($reply));
            }
            return $reply;
        };

        $read();
        $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $cmd('EHLO ' . $ehloHost, [250]);
        if ($encryption === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $cmd('EHLO ' . $ehloHost, [250]);
        }
        if ($username !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($username), [334]);
            $cmd(base64_encode($password), [235]);
        }
        $cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        [$headers, $body] = self::buildMime($html, $text, $fromEmail, $fromName);
        $headers = array_merge([
            'Date' => date('r'),
            'To' => $to,
            'Subject' => '=?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>',
        ], $headers);
        $data = '';
        foreach ($headers as $k => $v) {
            $data .= $k . ': ' . $v . "\r\n";
        }
        $data .= "\r\n" . preg_replace('/^\./m', '..', $body);
        fwrite($socket, $data . "\r\n.\r\n");
        $reply = $read();
        if ((int) substr($reply, 0, 3) !== 250) {
            throw new \RuntimeException('SMTP DATA rejected: ' . trim($reply));
        }
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }
}
