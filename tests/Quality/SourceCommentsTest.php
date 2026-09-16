<?php

declare(strict_types=1);

/*
 * Guards the SDK documentation style: hand-written source and test files must
 * describe the PHP API in its own terms and must not reference a source SDK
 * (a Go implementation, "parity", porting or mirrored structs). This keeps the
 * generated output and hand-written layer self-contained.
 */

namespace LumeWeb\Portal\Tests\Quality;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SourceCommentsTest extends TestCase
{
    /**
     * Substrings that must not appear anywhere in hand-written PHP files.
     * These are source-SDK / porting references (or Go naming conventions).
     */
    private const BANNED = [
        'Go SDK',
        "Go's",
        'PHP port',
        'port of the',
        'parity with',
        'source SDK',
        'internal/http',
        'httpErrorMessages',
        'sentinelMap',
        'account.go',
        'errors.Is',
        'ErrUnauthorized',
        'ErrBadRequest',
        'ErrConflict',
        'ErrForbidden',
        'ErrInternalServer',
        'ErrNotFound',
        'ErrUnavailable',
        'OpLogin',
    ];

    /**
     * @return string[]
     */
    private function phpFiles(): array
    {
        $files = [];
        $skip = [basename(__FILE__)];
        foreach (['src', 'tests'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && !\in_array($file->getFilename(), $skip, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function testHandwrittenFilesDoNotReferenceASourceSdk(): void
    {
        $violations = [];
        foreach ($this->phpFiles() as $file) {
            $content = (string) file_get_contents($file);
            foreach (self::BANNED as $phrase) {
                if (stripos($content, $phrase) !== false) {
                    $violations[] = $file . ' contains "' . $phrase . '"';
                }
            }
        }

        self::assertSame([], $violations);
    }
}
