<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Totp
 *
 * Native Time-based One-Time Password implementation (RFC 6238 / RFC 4226)
 * compatible with Google Authenticator, Microsoft Authenticator, Authy and
 * any other RFC-compliant app — no external libraries required.
 *
 * The secret is exposed as Base32 (RFC 4648) and codes are derived with
 * HMAC-SHA1 over a 8-byte big-endian counter, using dynamic truncation.
 */
final class Totp
{
    /** RFC 4648 Base32 alphabet. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Time step in seconds (standard 30s). */
    public const PERIOD = 30;

    /** Number of digits in a generated code. */
    public const DIGITS = 6;

    /**
     * Generates a fresh cryptographically secure Base32 secret.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    /**
     * Encodes raw binary data into a Base32 string (no padding).
     */
    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $bits = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    /**
     * Decodes a Base32 string back into raw binary (case-insensitive).
     */
    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }

        $bits = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $secret[$i]);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $output .= chr(bindec($chunk));
        }

        return $output;
    }

    /**
     * Computes the TOTP code for a given secret and moment in time.
     */
    public static function code(
        string $secret,
        ?int $timestamp = null,
        int $digits = self::DIGITS,
        int $period = self::PERIOD
    ): string {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, $period);
        $key = self::base32Decode($secret);

        if ($key === '') {
            return str_repeat('0', $digits);
        }

        // 8-byte big-endian counter (high 4 bytes + low 4 bytes).
        $binaryCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $modulo = 10 ** $digits;

        return str_pad((string)($value % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a submitted code against the secret, tolerating a small clock
     * drift window (default ±1 step = ±30 seconds).
     */
    public static function verify(
        string $secret,
        string $code,
        int $window = 1,
        int $digits = self::DIGITS,
        int $period = self::PERIOD
    ): bool {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . $digits . '}$/', $code)) {
            return false;
        }

        $now = time();
        for ($step = -$window; $step <= $window; $step++) {
            $candidate = self::code($secret, $now + ($step * $period), $digits, $period);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the otpauth:// provisioning URI consumed by authenticator apps
     * (and turned into a QR code in the admin panel).
     */
    public static function provisioningUri(string $secret, string $accountLabel, string $issuer = ''): string
    {
        $label = $accountLabel;
        if ($issuer !== '' && !str_contains($accountLabel, ':')) {
            $label = $issuer . ':' . $accountLabel;
        }

        $params = [
            'secret' => $secret,
            'digits' => (string)self::DIGITS,
            'period' => (string)self::PERIOD,
        ];
        if ($issuer !== '') {
            $params['issuer'] = $issuer;
        }

        return 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
