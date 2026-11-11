<?php declare(strict_types=1);

namespace TextMeFlow\Service;

/**
 * Encrypts the connection token before it is stored in the database, with a key derived from
 * the shop's APP_SECRET, so a database dump alone does not reveal it.
 */
class SecretBox
{
    private const PREFIX = 'enc1:';

    public function __construct(private readonly string $appSecret)
    {
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * @return string|null the plain text, or null if the value is not decryptable (e.g. APP_SECRET changed)
     */
    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash('sha256', 'textmeflow|' . $this->appSecret, true);
    }
}
