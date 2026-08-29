<?php

declare(strict_types=1);

/**
 * Shared file-walking helper for the gate scripts.
 *
 * Prunes skip-directories (vendor, node_modules, .venv, .git, build output) at the traversal
 * level via RecursiveCallbackFilterIterator, rather than descending into them and discarding
 * files afterward. node_modules alone can hold tens of thousands of files; walking into it
 * before filtering makes every gate take minutes instead of a second.
 */

const GATE_SKIP_DIRS = [
    'vendor', 'node_modules', '.venv', '.git', 'storage', 'bootstrap',
    '.next', 'dist', 'out', '__pycache__', '.pytest_cache', 'coverage',
];

/**
 * @return Generator<SplFileInfo>
 */
function gateWalk(string $root, array $extraSkipDirs = []): Generator
{
    $skip = array_merge(GATE_SKIP_DIRS, $extraSkipDirs);

    // Canonicalize so every path the iterator yields is built on the same resolved base a
    // caller would get from realpath($root) — otherwise a $root containing '..' segments
    // (e.g. __DIR__ . '/../..') yields paths that don't share a prefix with realpath($root),
    // and stripping the root to compute a relative path silently produces garbage.
    $resolved = realpath($root);
    if ($resolved === false) {
        throw new RuntimeException("gateWalk: root does not exist: {$root}");
    }

    $dirIter = new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS);

    $filter = new RecursiveCallbackFilterIterator(
        $dirIter,
        function (SplFileInfo $current) use ($skip): bool {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $skip, true);
            }
            return true;
        }
    );

    $iter = new RecursiveIteratorIterator($filter);

    foreach ($iter as $file) {
        /** @var SplFileInfo $file */
        yield $file;
    }
}
