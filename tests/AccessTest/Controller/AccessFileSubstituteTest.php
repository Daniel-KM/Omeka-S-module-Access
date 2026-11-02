<?php declare(strict_types=1);

namespace AccessTest\Controller;

use Access\Controller\AccessFileController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the file sent instead of a reserved file.
 *
 * The sending itself cannot be dispatched in tests, because the controller
 * cleans the output buffers.
 *
 * @group file
 */
class AccessFileSubstituteTest extends TestCase
{
    /**
     * @dataProvider mediaTypeProvider
     */
    public function testSubstituteFileHasTheTypeOfTheMedia(string $mediaType, string $expectedType, string $expectedFile): void
    {
        [$type, $file] = AccessFileController::fakeFile($mediaType);
        $this->assertSame($expectedType, $type);
        $this->assertSame($expectedFile, $file);
        $this->assertFileExists(dirname(__DIR__, 3) . '/asset/' . $file);
    }

    public function mediaTypeProvider(): array
    {
        return [
            'audio' => ['audio/mpeg', 'audio/mpeg', 'img/locked-file.mp3'],
            'audio ogg' => ['audio/ogg', 'audio/mpeg', 'img/locked-file.mp3'],
            'video' => ['video/webm', 'video/mp4', 'img/locked-file.mp4'],
            'image' => ['image/jpeg', 'image/png', 'img/locked-file.png'],
            'pdf' => ['application/pdf', 'application/pdf', 'img/locked-file.pdf'],
            'other' => ['application/zip', 'image/png', 'img/locked-file.png'],
        ];
    }
}
