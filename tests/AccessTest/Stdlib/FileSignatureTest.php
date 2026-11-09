<?php declare(strict_types=1);

namespace AccessTest\Stdlib;

use Access\Stdlib\FileSignature;
use PHPUnit\Framework\TestCase;

class FileSignatureTest extends TestCase
{
    public function testSignatureIsValidForItsPathUntilItsExpiry(): void
    {
        $signer = new FileSignature('key');
        $now = 1700000000;
        $signature = $signer->sign('square/a/b.jpg', $now);

        $this->assertTrue($signer->isValid('square/a/b.jpg', $signature, $now));
        $this->assertTrue($signer->isValid('square/a/b.jpg', $signature, $now + 86400));
        $this->assertFalse($signer->isValid('square/a/b.jpg', $signature, $now + 3 * 86400));
        $this->assertFalse($signer->isValid('large/a/b.jpg', $signature, $now));
        $this->assertFalse((new FileSignature('other'))->isValid('square/a/b.jpg', $signature, $now));
    }

    public function testSignatureIsStableDuringTheDay(): void
    {
        $signer = new FileSignature('key');
        $start = intdiv(1700000000, 86400) * 86400;
        $this->assertSame($signer->sign('square/b.jpg', $start), $signer->sign('square/b.jpg', $start + 86399));
    }

    public function testTamperedSignatureIsInvalid(): void
    {
        $signer = new FileSignature('key');
        $now = 1700000000;
        [$expires, $hash] = explode('.', $signer->sign('square/b.jpg', $now));
        $this->assertFalse($signer->isValid('square/b.jpg', ($expires + 86400) . '.' . $hash, $now));
        $this->assertFalse($signer->isValid('square/b.jpg', $expires . '.' . strrev($hash), $now));
        $this->assertFalse($signer->isValid('square/b.jpg', '', $now));
        $this->assertFalse($signer->isValid('square/b.jpg', 'abc.def', $now));
    }

    protected function omekaPath(?string $localConfig): string
    {
        $path = sys_get_temp_dir() . '/access-signature-' . bin2hex(random_bytes(4));
        mkdir($path . '/config', 0777, true);
        file_put_contents($path . '/config/database.ini', "user = \"omeka\"\npassword = \"secret\"\n");
        if ($localConfig !== null) {
            file_put_contents($path . '/config/local.config.php', $localConfig);
        }
        return $path;
    }

    protected function removeOmekaPath(string $path): void
    {
        @unlink($path . '/config/database.ini');
        @unlink($path . '/config/local.config.php');
        @rmdir($path . '/config');
        @rmdir($path);
    }

    public function testKeyDerivesFromTheSecretKeyWhenSet(): void
    {
        $secret = base64_encode(str_repeat("\x01", 32));
        $withSecret = $this->omekaPath("<?php\nreturn [\n    'logger' => ['priority' => \\Laminas\\Log\\Logger::NOTICE],\n    'security' => [\n        'secret_key' => '$secret',\n    ],\n];\n");
        $withoutSecret = $this->omekaPath(null);
        try {
            $signature = FileSignature::fromOmekaPath($withSecret)->sign('square/a.jpg', 1700000000);
            $expected = (new FileSignature(hash_hkdf('sha256', str_repeat("\x01", 32), 32, 'access-file-signature')))->sign('square/a.jpg', 1700000000);
            $this->assertSame($expected, $signature);
            $this->assertNotSame($signature, FileSignature::fromOmekaPath($withoutSecret)->sign('square/a.jpg', 1700000000));
        } finally {
            $this->removeOmekaPath($withSecret);
            $this->removeOmekaPath($withoutSecret);
        }
    }

    public function testCommentedOrInvalidSecretKeyIsIgnored(): void
    {
        $secret = base64_encode(str_repeat("\x01", 32));
        $commented = $this->omekaPath("<?php\nreturn [\n    'security' => [\n        // 'secret_key' => '$secret',\n    ],\n];\n");
        $invalid = $this->omekaPath("<?php\nreturn ['security' => ['secret_key' => 'tooshort']];\n");
        $none = $this->omekaPath(null);
        try {
            $fallback = FileSignature::fromOmekaPath($none)->sign('square/a.jpg', 1700000000);
            $this->assertSame($fallback, FileSignature::fromOmekaPath($commented)->sign('square/a.jpg', 1700000000));
            $this->assertSame($fallback, FileSignature::fromOmekaPath($invalid)->sign('square/a.jpg', 1700000000));
        } finally {
            $this->removeOmekaPath($commented);
            $this->removeOmekaPath($invalid);
            $this->removeOmekaPath($none);
        }
    }
}
