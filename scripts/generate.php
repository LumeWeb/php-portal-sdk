<?php

declare(strict_types=1);

/**
 * Regenerates the PHP clients from the vendored OpenAPI specs using
 * openapitools/openapi-generator-cli (run via Docker).
 *
 * Usage:
 *   php scripts/generate.php            # regenerate account + admin
 *   php scripts/generate.php account    # only the account client
 *   php scripts/generate.php admin      # only the admin client
 *   OPENAPI_GENERATOR_IMAGE=tag php scripts/generate.php
 *
 * The generated output is committed under generated/{account,admin} and is
 * marked DO NOT EDIT — all hand-written code lives under src/.
 */

$root = dirname(__DIR__);
$image = getenv('OPENAPI_GENERATOR_IMAGE') ?: 'openapitools/openapi-generator-cli:latest';

/**
 * @return array{0:string,1:string,2:string} [specRel, outRel, configRel]
 */
function targets(): array
{
    return [
        'account' => ['specs/account.yaml', 'generated/account', 'config/openapi-generator-account.yaml'],
        'admin'   => ['specs/admin/quota.yaml', 'generated/admin', 'config/openapi-generator-admin.yaml'],
    ];
}

/**
 * @param list<string> $args
 * @return list<string>
 */
function requested(array $args): array
{
    if ($args === []) {
        return array_keys(targets());
    }
    $known = array_keys(targets());
    foreach ($args as $a) {
        if (!in_array($a, $known, true)) {
            fwrite(STDERR, "Unknown target '$a'. Valid targets: " . implode(', ', $known) . PHP_EOL);
            exit(2);
        }
    }
    return $args;
}

/**
 * Reads every proc_open pipe to EOF concurrently using stream_select().
 *
 * Draining the pipes sequentially (stream_get_contents on stdout, then stderr)
 * is a classic deadlock: a child that fills the stderr pipe buffer (>64KB)
 * blocks on write, so it never closes stdout, and the stdout read never
 * returns — the script hangs in proc_close(). Watching both pipes at once
 * keeps every buffer draining so the child always makes progress.
 *
 * Exit status is unaffected: the caller still closes the pipes and calls
 * proc_close(), which reaps the child and exposes the real exit code.
 *
 * @param array{1:resource,2:resource} $pipes proc_open pipes keyed by fd
 * @return array{0:string,1:string} [stdout, stderr]
 */
function drainPipes(array $pipes): array
{
    $out = [1 => '', 2 => ''];
    $open = [];

    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            stream_set_blocking($pipes[$fd], false);
            $open[$fd] = $pipes[$fd];
        }
    }

    while ($open) {
        $read = array_values($open);
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, null);

        if ($ready === false) {
            // Interrupted or transient stream error: drain whatever is still
            // readable instead of abandoning the child, but stop if nothing
            // makes progress so we never spin forever.
            $read = array_values($open);
        }

        $progress = false;
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);

            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    foreach ($open as $fd => $s) {
                        if ($s === $stream) {
                            unset($open[$fd]);
                            $progress = true;
                            break;
                        }
                    }
                }
                continue;
            }

            $progress = true;
            if ($stream === $pipes[1]) {
                $out[1] .= $chunk;
            } else {
                $out[2] .= $chunk;
            }
        }

        if ($ready === false && !$progress) {
            break;
        }
    }

    return [$out[1], $out[2]];
}

// When the file is included by tests, only expose the helpers above; run the
// generator only when invoked directly (php scripts/generate.php).
if (realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

foreach (requested(array_slice($argv, 1)) as $name) {
    [$specRel, $outRel, $configRel] = targets()[$name];

    $specAbs  = $root . '/' . $specRel;
    $outAbs   = $root . '/' . $outRel;
    $configAbs = $root . '/' . $configRel;

    if (!is_file($specAbs)) {
        fwrite(STDERR, "Spec not found: $specAbs\n");
        exit(1);
    }
    if (!is_file($configAbs)) {
        fwrite(STDERR, "Config not found: $configAbs\n");
        exit(1);
    }
    if (!is_dir($outAbs)) {
        if (!mkdir($outAbs, 0777, true)) {
            fwrite(STDERR, "Could not create output dir: $outAbs\n");
            exit(1);
        }
    }

    $cmd = [
        'docker', 'run', '--rm',
        '-v', $specAbs . ':/tmp/spec.yaml:ro',
        '-v', $configAbs . ':/tmp/config.yaml:ro',
        '-v', $outAbs . ':/out:rw',
        $image,
        'generate',
        '-i', '/tmp/spec.yaml',
        '-o', '/out',
        '-c', '/tmp/config.yaml',
        '--global-property',
        'apiTests=false,modelTests=false,apiDocs=false,modelDocs=false',
    ];

    echo "[generate::$name] " . implode(' ', $cmd) . PHP_EOL;
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to launch docker for target '$name'.\n");
        exit(1);
    }

    [$stdout, $stderr] = drainPipes($pipes);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    if ($status !== 0) {
        fwrite(STDERR, "Generation failed for target '$name' (exit $status).\n");
        fwrite(STDERR, $stdout);
        fwrite(STDERR, $stderr);
        exit(1);
    }

    // The generator always writes its own composer.json/README/phpunit.xml into
    // the output dir; the root package owns those files, so drop the copies.
    foreach (['composer.json', 'README.md', 'phpunit.xml.dist', '.travis.yml', 'git_push.sh', '.php-cs-fixer.dist.php', '.gitignore'] as $junk) {
        $j = $outAbs . '/' . $junk;
        if (is_file($j)) {
            unlink($j);
        }
    }

    echo "[generate::$name] wrote {$outRel} from {$specRel}" . PHP_EOL;
}
