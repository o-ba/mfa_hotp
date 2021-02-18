<?php

declare(strict_types=1);

namespace Bo\Hotp\Mfa;

use Base32\Base32;

/**
 * Hmac-based one-time password (HOTP) implementation according to rfc6238
 *
 * @author Oliver Bartsch <bo@cedev.de>
 */
class Hotp
{
    private const ALLOWED_ALGOS = ['sha1', 'sha256', 'sha512'];
    private const MIN_LENGTH = 6;
    private const MAX_LENGTH = 8;

    protected string $secret;
    protected int $counter;
    protected string $algo;
    protected int $length;

    public function __construct(string $secret, int $counter, string $algo = 'sha1', int $length = 6)
    {
        $this->secret = $secret;
        $this->counter = $counter;

        if (!in_array($algo, self::ALLOWED_ALGOS, true)) {
            throw new \InvalidArgumentException(
                $algo . ' is not allowed. Allowed algos are: ' . implode(',', self::ALLOWED_ALGOS),
                1611748793
            );
        }
        $this->algo = $algo;

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                $length . ' is not allowed as HOTP length. Must be between ' . self::MIN_LENGTH . ' and ' . self::MAX_LENGTH,
                1611748794
            );
        }
        $this->length = $length;
    }

    /**
     * Generate a one-time password for the given counter according to rfc4226
     *
     * @param int $counter A counter according to rfc4226
     * @return string The generated HOTP
     */
    public function generateHotp(int $counter): string
    {
        // Generate a 8-byte counter value (C) from the given counter input
        $binary = [];
        while ($counter !== 0) {
            $binary[] = pack('C*', $counter);
            $counter >>= 8;
        }
        // Implode and fill with NULL values
        $binary = str_pad(implode(array_reverse($binary)), 8, "\000", STR_PAD_LEFT);
        // Create a 20-byte hash string (HS) with given algo and decoded shared secret (K)
        $hash = hash_hmac($this->algo, $binary, $this->getDecodedSecret());
        // Convert hash into hex and generate an array with the decimal values of the hash
        $hmac = [];
        foreach (str_split($hash, 2) as $hex) {
            $hmac[] = hexdec($hex);
        }
        // Generate a 4-byte string with dynamic truncation (DT)
        $offset = $hmac[\count($hmac) - 1] & 0xf;
        $bits = ((($hmac[$offset + 0] & 0x7f) << 24) | (($hmac[$offset + 1] & 0xff) << 16) | (($hmac[$offset + 2] & 0xff) << 8) | ($hmac[$offset + 3] & 0xff));
        // Compute the HOTP value by reducing the bits modulo 10^Digits and filling it with zeros '0'
        return str_pad((string)($bits % (10 ** $this->length)), $this->length, '0', STR_PAD_LEFT);
    }

    /**
     * Verify the given hmac-based one-time password
     *
     * @param string $hotp The hmac-based one-time password to be verified
     * @param int|null $counter The counter value (moving factor) for the HOTP
     * @return bool
     */
    public function verifyHotp(string $hotp, int $counter = null): bool
    {
        $counter ??= $this->handleEmptyCounter();
        return $this->compare($hotp, $counter);
    }

    /**
     * The drawback of HOTP is that the counter can get out of sync.
     * Therefore, this method allows to resync the counter based on the
     * given window. This means, all HOTPs in the given window are accepted.
     * If one is valid, this counter should be stored in the database, to be
     * back in sync.
     *
     * @param string $hotp
     * @param int $window
     * @param int|null $counter
     * @return int|null
     */
    public function resyncCounter(string $hotp, int $window = 3, int $counter = null): ?int
    {
        $counter ??= $this->handleEmptyCounter();

        for ($i = 0; $i < $window; ++$i) {
            $next = $counter + $i;
            if ($this->compare($hotp, $next)) {
                return $next;
            }
        }

        // Counter could not be resynchronized
        return null;
    }

    /**
     * Generate and return the otpauth URL for HOTP
     *
     * @param string $issuer
     * @param string $account
     * @param array  $additionalParameters
     * @return string
     */
    public function getHotpAuthUrl(string $issuer, string $account = '', array $additionalParameters = []): string
    {
        $parameters = [
            'secret' => $this->secret,
            'issuer' => htmlspecialchars($issuer),
            // We include the current counter in the URL. Note: Not all OTP applications
            // evaluate this parameter, but just start with a counter value of "0" or "-1".
            'counter' => $this->counter
        ];

        // Common OTP applications expect the following parameters:
        // - algo: sha1
        // - digits 6
        // Only if we differ from these assumption, the exact values must be provided.
        if ($this->algo !== 'sha1') {
            $parameters['algorithm'] = $this->algo;
        }
        if ($this->length !== 6) {
            $parameters['digits'] = $this->length;
        }

        // Generate the otpauth URL by providing information like issuer and account
        return sprintf(
            'otpauth://hotp/%s?%s',
            rawurlencode($issuer . ($account !== '' ? ':' . $account : '')),
            http_build_query(array_merge($parameters, $additionalParameters), '', '&', PHP_QUERY_RFC3986)
        );
    }

    /**
     * Compare given one-time password with a one-time password
     * generated from the known $counter (the moving factor).
     *
     * @param string $hotp The one-time password to verify
     * @param int $counter The counter value, the moving factor
     * @return bool
     */
    protected function compare(string $hotp, int $counter): bool
    {
        return hash_equals($this->generateHotp($counter), $hotp);
    }

    /**
     * Generate the shared secret (K) by using a random and applying
     * additional authentication factors like username or email address.
     *
     * @param array $additionalAuthFactors
     * @return string
     */
    public static function generateEncodedSecret(array $additionalAuthFactors = []): string
    {
        $payload = implode($additionalAuthFactors);
        // RFC 4226 (https://tools.ietf.org/html/rfc4226#section-4) suggests 160 bit HOTP secret keys
        // HMAC-SHA1 based on static factors and a 160 bit HMAC-key lead again to 160 bits (20 bytes)
        // base64-encoding (factor 1.6) 20 bytes lead to 32 uppercase characters
        return Base32::encode(hash_hmac('sha1', $payload, random_bytes(20), true));
    }

    protected function getDecodedSecret(): string
    {
        return Base32::decode($this->secret);
    }

    protected function handleEmptyCounter(): int
    {
        return $this->counter;
    }
}
