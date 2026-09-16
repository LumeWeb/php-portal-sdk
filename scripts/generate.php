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

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
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
