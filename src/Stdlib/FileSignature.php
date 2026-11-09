<?php declare(strict_types=1);

namespace Access\Stdlib;

/**
 * Sign the urls of the files allowed to the current user.
 *
 * A page rendered by Omeka checks the rights of each thumbnail, then signs its
 * url, so the browser gets the thumbnails via a light script, without starting
 * Omeka for each of them. The key derives from the secret key of Omeka when it
 * is set in the local config, else from the config of the database, that is
 * private too.
 *
 * This class has no dependency, because it is used by the script too.
 *
 * @see data/scripts/file.php
 */
class FileSignature
{
    /**
     * Name of the argument of the url that contains the signature.
     */
    public const QUERY_ARG = 'access_signature';

    /**
     * Validity of a signature, in days. The expiry is rounded to the day, so
     * the urls remain stable and cacheable during one day.
     */
    public const DAYS = 2;

    /**
     * @var string
     */
    protected $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function fromOmekaPath(string $omekaPath): ?self
    {
        $secretKey = static::secretKey($omekaPath);
        if ($secretKey !== null) {
            return new self(hash_hkdf('sha256', $secretKey, 32, 'access-file-signature'));
        }
        $config = @file_get_contents($omekaPath . '/config/database.ini');
        return $config
            ? new self(hash('sha256', 'access-file-signature' . $config, true))
            : null;
    }

    /**
     * Get the secret key of Omeka set in the local config, if any.
     *
     * The file is not included, because it may use classes that are not loaded
     * in the script, so only a key written as a literal string is read. The
     * comments are skipped.
     *
     * @see \Common\Stdlib\Cipher
     */
    protected static function secretKey(string $omekaPath): ?string
    {
        $config = @file_get_contents($omekaPath . '/config/local.config.php');
        if (!$config) {
            return null;
        }
        if (function_exists('token_get_all')) {
            $code = '';
            foreach (token_get_all($config) as $token) {
                if (!is_array($token)) {
                    $code .= $token;
                } elseif (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            }
            $config = $code;
        }
        if (!preg_match('~[\'"]security[\'"]\s*=>\s*\[[^\]]*?[\'"]secret_key[\'"]\s*=>\s*[\'"]([A-Za-z0-9+/=]+)[\'"]~s', $config, $matches)) {
            return null;
        }
        $key = base64_decode($matches[1], true);
        return $key !== false && strlen($key) >= 32 ? $key : null;
    }

    /**
     * Sign a path relative to the directory of the files, like "square/x.jpg".
     */
    public function sign(string $path, ?int $now = null): string
    {
        $expires = (intdiv($now ?? time(), 86400) + self::DAYS) * 86400;
        return $expires . '.' . $this->hash($path, $expires);
    }

    public function isValid(string $path, string $signature, ?int $now = null): bool
    {
        [$expires, $hash] = array_pad(explode('.', $signature, 2), 2, '');
        return ctype_digit($expires)
            && (int) $expires >= ($now ?? time())
            && hash_equals($this->hash($path, (int) $expires), $hash);
    }

    protected function hash(string $path, int $expires): string
    {
        return substr(hash_hmac('sha256', $path . '|' . $expires, $this->key), 0, 32);
    }
}
