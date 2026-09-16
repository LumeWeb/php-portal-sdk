<?php

declare(strict_types=1);

/*
 * Regression coverage for the subprocess pipe handling in scripts/generate.php.
 * The fixed drainPipes() reads both proc_open pipes concurrently with
 * stream_select(); a sequential drain deadlocks when one pipe fills up.
 */

namespace LumeWeb\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Throwable;

final class GenerateScriptTest extends TestCase
{
    /** Far larger than the default 64KB pipe buffer, forcing a block. */
    private const LARGE = 2 * 1024 * 1024;

    /**
     * The child writes more than the pipe buffer to BOTH stdout and stderr.
     * Sequential draining (stdout to EOF, then stderr) deadlocks here: the
     * child blocks on the full stderr pipe, never closes stdout, and
     * proc_close() hangs forever. Concurrent draining must finish quickly and
     * capture both streams in full. The scenario runs in a forked child under
     * a wall-clock watchdog so a deadlock regression fails instead of hanging
     * the whole test run.
     */
    public function testDrainCapturesLargeStdoutAndStderrWithoutDeadlocking(): void
    {
        $exit = $this->runWithFailFast(function (): void {
            $code = sprintf(
                'fwrite(STDOUT, str_repeat(%s, %d)); fflush(STDOUT); fwrite(STDERR, str_repeat(%s, %d)); fflush(STDERR);',
                var_export('A', true),
                self::LARGE,
                var_export('B', true),
                self::LARGE,
            );
            [$proc, $pipes] = $this->spawnZeroArgPhp($code);

            [$stdout, $stderr] = \drainPipes($pipes);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);

            self::assertSame(0, $status);
            self::assertSame(self::LARGE, strlen($stdout));
            self::assertSame(self::LARGE, strlen($stderr));
            self::assertSame(str_repeat('A', self::LARGE), $stdout);
            self::assertSame(str_repeat('B', self::LARGE), $stderr);
        });

        self::assertSame(0, $exit, 'drain scenario must complete within the watchdog window');
    }

    /**
     * After the concurrent drain, proc_close() must still expose the child's
     * real exit status so a failed generation keeps a meaningful exit code.
     */
    public function testDrainPreservesNonZeroExitStatus(): void
    {
        $code = 'fwrite(STDOUT, "ok" . PHP_EOL); fflush(STDOUT); fwrite(STDERR, "boom" . PHP_EOL); fflush(STDERR); exit(7);';
        [$proc, $pipes] = $this->spawnZeroArgPhp($code);

        [$stdout, $stderr] = \drainPipes($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        self::assertSame(7, $status);
        self::assertSame('ok' . PHP_EOL, $stdout);
        self::assertSame('boom' . PHP_EOL, $stderr);
    }

    /**
     * A silent child (immediate EOF on both pipes) must drain cleanly and not
     * spin in the readiness loop.
     */
    public function testDrainHandlesImmediateEof(): void
    {
        [$proc, $pipes] = $this->spawnZeroArgPhp('');

        [$stdout, $stderr] = \drainPipes($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        self::assertSame(0, $status);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * @return array{0:resource,1:array{1:resource,2:resource}}
     */
    private function spawnZeroArgPhp(string $code): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /**
     * Runs $work in a forked child and returns the child's exit code (0 when
     * every assertion inside the scenario passed). Waits with a wall-clock
     * deadline: a hang in the child (e.g. a deadlock regression in
     * drainPipes) is killed after $seconds and reported as a failure instead
     * of stalling PHPUnit.
     */
    private function runWithFailFast(callable $work, int $seconds = 15): int
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $work();

            return 0;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed; cannot guard the drain against hangs.');

            return 1;
        }

        if ($pid === 0) {
            // Child: run the scenario. Assertions execute here; a failure exits
            // non-zero with the reason on STDERR so the parent can report it.
            $rc = 0;
            try {
                $work();
            } catch (Throwable $e) {
                fwrite(STDERR, (string) $e . PHP_EOL);
                $rc = 1;
            }
            exit($rc);
        }

        $deadline = microtime(true) + $seconds;
        while (true) {
            $wait = pcntl_waitpid($pid, $status, WNOHANG);
            if ($wait === $pid) {
                return pcntl_wexitstatus($status);
            }
            if (microtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                $this->fail(
                    'Pipe drain did not finish within the watchdog window — sequential stdout/stderr draining is back (deadlock regression).',
                );

                return 1;
            }
            usleep(50_000);
        }
    }
}

require_once __DIR__ . '/../scripts/generate.php';
