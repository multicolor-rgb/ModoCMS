<?php
declare(strict_types=1);

namespace Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Class Mailer
 *
 * Central outbound e-mail dispatcher for Modo CMS. The transport is chosen in
 * System Settings → Email:
 *   - "mail"  : uses the PHP mail() function.
 *   - "smtp"  : sends through the bundled PHPMailer library over SMTP.
 *
 * Plugins (e.g. Modo Form) should route every outgoing message through this
 * class so the same configuration and sender identity are respected site-wide.
 */
final class Mailer
{
    /** @var string Last error message produced by a failed send() call. */
    private static string $lastError = '';

    /**
     * Sends an e-mail using the configured transport.
     *
     * @param string|array<string,string> $to      Recipient address, or [email => name].
     * @param string                      $subject Subject line.
     * @param string                      $body    Message body (plain-text by default).
     * @param array<string,mixed>         $options Optional overrides:
     *        - from_email (string) : overrides the configured From address.
     *        - from_name  (string) : overrides the configured From name.
     *        - reply_to   (string) : Reply-To address.
     *        - reply_name (string) : Reply-To name.
     *        - html       (bool)   : send the body as HTML.
     *        - headers    (array)  : extra raw headers, name => value.
     *        - bcc        (array)  : list of BCC addresses.
     * @return bool True on success, false on failure (see lastError()).
     */
    public static function send(string|array $to, string $subject, string $body, array $options = []): bool
    {
        self::$lastError = '';

        $recipients = self::normalizeAddresses($to);
        if ($recipients === []) {
            self::$lastError = 'No valid recipient address.';
            return false;
        }

        $fromEmail = self::resolveFromEmail((string)($options['from_email'] ?? ''));
        $fromName  = (string)($options['from_name'] ?? self::setting('mail_from_name'));
        $isHtml    = !empty($options['html']);

        // Prefer SMTP + PHPMailer when selected; gracefully fall back to mail().
        if (self::setting('mail_driver', 'mail') === 'smtp' && class_exists(PHPMailer::class)) {
            return self::sendSmtp($recipients, $subject, $body, $fromEmail, $fromName, $isHtml, $options);
        }

        return self::sendNativeMail($recipients, $subject, $body, $fromEmail, $fromName, $isHtml, $options);
    }

    /** Returns the last error message (empty string when the last send succeeded). */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** Whether SMTP transport is currently selected. */
    public static function isSmtp(): bool
    {
        return self::setting('mail_driver', 'mail') === 'smtp';
    }

    // -------------------------------------------------------------------------
    // Transports
    // -------------------------------------------------------------------------

    /**
     * @param array<string,string> $recipients
     * @param array<string,mixed>  $options
     */
    private static function sendSmtp(
        array $recipients,
        string $subject,
        string $body,
        string $fromEmail,
        string $fromName,
        bool $isHtml,
        array $options
    ): bool {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host    = self::setting('mail_smtp_host');
            $mailer->Port    = (int)self::setting('mail_smtp_port', '587') ?: 587;
            $mailer->Timeout = (int)self::setting('mail_smtp_timeout', '15') ?: 15;

            $secure = self::setting('mail_smtp_secure', 'tls');
            if ($secure === 'ssl' || $secure === 'tls') {
                $mailer->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }

            $user = self::setting('mail_smtp_user');
            if (self::setting('mail_smtp_auth', '1') === '1' && $user !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $user;
                $mailer->Password = self::setting('mail_smtp_pass');
            } else {
                $mailer->SMTPAuth = false;
            }

            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($fromEmail, $fromName);

            foreach ($recipients as $email => $name) {
                $mailer->addAddress($email, $name);
            }

            self::applyReplyToSmtp($mailer, $options);
            foreach (self::bccList($options) as $bcc) {
                $mailer->addBCC($bcc);
            }
            self::applyHeadersSmtp($mailer, $options);

            $mailer->Subject = $subject;
            $mailer->isHTML($isHtml);
            $mailer->Body    = $body;
            if ($isHtml) {
                $mailer->AltBody = trim(strip_tags($body));
            }

            return $mailer->send();
        } catch (PHPMailerException | \Throwable $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * @param array<string,string> $recipients
     * @param array<string,mixed>  $options
     */
    private static function sendNativeMail(
        array $recipients,
        string $subject,
        string $body,
        string $fromEmail,
        string $fromName,
        bool $isHtml,
        array $options
    ): bool {
        $toList = [];
        foreach ($recipients as $email => $name) {
            $toList[] = $name !== '' ? self::formatAddress($email, $name) : $email;
        }

        $headers   = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        $headers[] = 'From: ' . self::formatAddress($fromEmail, $fromName);

        $replyTo = trim((string)($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . self::formatAddress($replyTo, (string)($options['reply_name'] ?? ''));
        }

        foreach (self::bccList($options) as $bcc) {
            $headers[] = 'Bcc: ' . $bcc;
        }

        $extra = $options['headers'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                $name  = trim((string)$name);
                $value = str_replace(["\r", "\n"], '', (string)$value);
                if ($name !== '') {
                    $headers[] = $name . ': ' . $value;
                }
            }
        }

        // Normalise line endings for the mail() function.
        $body    = preg_replace("/\r\n|\r/", "\n", $body) ?? $body;
        $body    = str_replace("\n", "\r\n", $body);
        $subject = str_replace(["\r", "\n"], '', $subject);

        $ok = @mail(implode(', ', $toList), $subject, $body, implode("\r\n", $headers));
        if (!$ok) {
            self::$lastError = 'PHP mail() returned false.';
        }
        return (bool)$ok;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function applyReplyToSmtp(PHPMailer $mailer, array $options): void
    {
        $replyTo = trim((string)($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mailer->addReplyTo($replyTo, (string)($options['reply_name'] ?? ''));
        }
    }

    private static function applyHeadersSmtp(PHPMailer $mailer, array $options): void
    {
        $extra = $options['headers'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $name => $value) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $mailer->addCustomHeader($name, (string)$value);
                }
            }
        }
    }

    /**
     * Accepts a single address, or a map of email => name, and returns a
     * normalised [email => name] map containing only valid addresses.
     *
     * @param string|array<string,string> $to
     * @return array<string,string>
     */
    private static function normalizeAddresses(string|array $to): array
    {
        $out = [];
        if (is_string($to)) {
            $to = [$to => ''];
        }
        foreach ($to as $email => $name) {
            // Support a plain list as well: [0 => 'a@b.com'].
            if (is_int($email)) {
                $email = (string)$name;
                $name  = '';
            }
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = trim((string)$name);
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function bccList(array $options): array
    {
        $out = [];
        foreach ((array)($options['bcc'] ?? []) as $addr) {
            $addr = trim((string)$addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[] = $addr;
            }
        }
        return $out;
    }

    private static function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private static function resolveFromEmail(string $override): string
    {
        $override = trim($override);
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_EMAIL)) {
            return $override;
        }

        $configured = trim(self::setting('mail_from_email'));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }

        // Safe fallback so mail never fails purely on a missing sender.
        $host = self::hostname();
        return 'no-reply@' . ($host !== '' ? $host : 'localhost');
    }

    private static function hostname(): string
    {
        $siteUrl = self::setting('site_url');
        if ($siteUrl !== '') {
            $host = parse_url($siteUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';
    }

    private static function setting(string $key, string $default = ''): string
    {
        if (class_exists(Router::class)) {
            return (string)Router::getOption($key, $default);
        }
        return $default;
    }
}
