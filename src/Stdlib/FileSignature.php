<?php declare(strict_types=1);

namespace Access\Stdlib;

/**
 * Sign the urls of the files allowed to the current user.
 *
 * A page rendered by Omeka checks the rights of each thumbnail, then signs its
 * url, so the browser gets the thumbnails via a light script, without starting
 * Omeka for each of them. The key derives from the config of the database, that
 * is private and readable by the script.
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
        $config = @file_get_contents($omekaPath . '/config/database.ini');
        return $config
            ? new self(hash('sha256', 'access-file-signature' . $config, true))
            : null;
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
