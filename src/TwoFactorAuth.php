<?php

namespace pskuza\Auth;

use pskuza\Auth\Providers\Qr\IQRCodeProvider;
use \ParagonIE\ConstantTime\Base32;

// Based on / inspired by: https://github.com/PHPGangsta/GoogleAuthenticator
// Algorithms, digits, period etc. explained: https://github.com/google/google-authenticator/wiki/Key-Uri-Format
class TwoFactorAuth
{
    private $algorithm;
    private $period;
    private $digits;
    private $issuer;
    private $qrcodeprovider = null;
    private static $_base32dict = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567=';
    private static $_base32;
    private static $_base32lookup = [];
    private static $_supportedalgos = ['sha1', 'sha256', 'sha512', 'md5'];

    public function __construct(string $issuer = null, int $digits = 6, int $period = 30, string $algorithm = 'sha1', IQRCodeProvider $qrcodeprovider = null)
    {
        $this->issuer = $issuer;
        if (!is_int($digits) || $digits <= 0) {
            throw new TwoFactorAuthException('Digits must be int > 0');
        }
        $this->digits = $digits;

        if (!is_int($period) || $period <= 0) {
            throw new TwoFactorAuthException('Period must be int > 0');
        }
        $this->period = $period;

        $algorithm = strtolower(trim($algorithm));
        if (!in_array($algorithm, self::$_supportedalgos)) {
            throw new TwoFactorAuthException('Unsupported algorithm: '.$algorithm);
        }
        $this->algorithm = $algorithm;
        $this->qrcodeprovider = $qrcodeprovider;

        self::$_base32 = str_split(self::$_base32dict);
        self::$_base32lookup = array_flip(self::$_base32);
    }

    /**
     * Create a new secret.
     */
    public function createSecret(int $bits = 80): string
    {
        $secret = '';
        $bytes = ceil($bits / 5);   //We use 5 bits of each byte (since we have a 32-character 'alphabet' / BASE32)
        $rnd = random_bytes($bytes);
        for ($i = 0; $i < $bytes; $i++) {
            $secret .= self::$_base32[ord($rnd[$i]) & 31];
        }  //Mask out left 3 bits for 0-31 values
        return $secret;
    }

    /**
     * Calculate the code with given secret and point in time.
     */
    public function getCode(string $secret, int $time = null): string
    {
        $secretkey = Base32::decode($secret);

        $timestamp = "\0\0\0\0".pack('N*', $this->getTimeSlice($this->getTime($time)));  // Pack time into binary string
        $hashhmac = hash_hmac($this->algorithm, $timestamp, $secretkey, true);             // Hash it with users secret key
        $hashpart = substr($hashhmac, ord(substr($hashhmac, -1)) & 0x0F, 4);               // Use last nibble of result as index/offset and grab 4 bytes of the result
        $value = unpack('N', $hashpart);                                                   // Unpack binary value
        $value = $value[1] & 0x7FFFFFFF;                                                   // Drop MSB, keep only 31 bits

        return str_pad($value % pow(10, $this->digits), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Check if the code is correct. This will accept codes starting from ($discrepancy * $period) sec ago to ($discrepancy * period) sec from now.
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, int $time = null): bool
    {
        $result = false;
        $timetamp = $this->getTime($time);

        // To keep safe from timing-attacks we iterate *all* possible codes even though we already may have verified a code is correct
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $result |= $this->codeEquals($this->getCode($secret, $timetamp + ($i * $this->period)), $code);
        }

        return (bool) $result;
    }

    /**
     * Timing-attack safe comparison of 2 codes (see http://blog.ircmaxell.com/2014/11/its-all-about-time.html).
     */
    private function codeEquals(string $safe, string $user): bool
    {
        return hash_equals($safe, $user);
    }

    /**
     * Get data-uri of QRCode.
     */
    public function getQRCodeImageAsDataUri(string $label, string $secret, int $size = 200): string
    {
        if (!is_int($size) || $size <= 0) {
            throw new TwoFactorAuthException('Size must be int > 0');
        }
        $qrcodeprovider = $this->getQrCodeProvider();

        return 'data:'
            .$qrcodeprovider->getMimeType()
            .';base64,'
            .base64_encode($qrcodeprovider->getQRCodeImage($this->getQRText($label, $secret), $size));
    }

    private function getTime(int $time): int
    {
        return ($time === null) ? time() : $time;
    }

    private function getTimeSlice(int $time = null, int $offset = 0): int
    {
        return (int) floor($time / $this->period) + ($offset * $this->period);
    }

    /**
     * Builds a string to be encoded in a QR code.
     */
    public function getQRText(string $label, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($label)
            .'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($this->issuer)
            .'&period='.intval($this->period)
            .'&algorithm='.rawurlencode(strtoupper($this->algorithm))
            .'&digits='.intval($this->digits);
    }

    private function base32Decode($value)
    {
        if (strlen($value) == 0) {
            return '';
        }

        if (preg_match('/[^'.preg_quote(self::$_base32dict).']/', $value) !== 0) {
            throw new TwoFactorAuthException('Invalid base32 string');
        }
        $buffer = '';
        foreach (str_split($value) as $char) {
            if ($char !== '=') {
                $buffer .= str_pad(decbin(self::$_base32lookup[$char]), 5, 0, STR_PAD_LEFT);
            }
        }
        $length = strlen($buffer);
        $blocks = trim(chunk_split(substr($buffer, 0, $length - ($length % 8)), 8, ' '));

        $output = '';
        foreach (explode(' ', $blocks) as $block) {
            $output .= chr(bindec(str_pad($block, 8, 0, STR_PAD_RIGHT)));
        }

        return $output;
    }

    /**
     * @throws TwoFactorAuthException
     *
     * @return IQRCodeProvider
     */
    public function getQrCodeProvider()
    {
        // Set default QR Code provider if none was specified
        if (null === $this->qrcodeprovider) {
            return $this->qrcodeprovider = new Providers\Qr\GoogleQRCodeProvider();
        }

        return $this->qrcodeprovider;
    }
}
