<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * REQ-GEC-002 as a static check: nothing under Domain/Games/Engine may import
 * Eloquent, the DB facade, or any other Illuminate framework class. This is the
 * enforcement mechanism substituting for the separate-process/mTLS boundary
 * described in architecture.md (deferred — see BlackRedEngine's doc comment).
 */
final class EnginePurityTest extends TestCase
{
    public function test_engine_directory_imports_no_framework_or_database_classes(): void
    {
        $forbidden = ['Illuminate\\', 'Eloquent', 'DB::', 'PDO'];
        $violations = $this->scanEngineCode($forbidden);

        $this->assertSame([], $violations, "Engine must stay framework/DB-free:\n" . implode("\n", $violations));
    }

    public function test_engine_directory_calls_no_prohibited_randomness_function(): void
    {
        // REQ-RNG-007.
        $prohibited = ['rand(', 'mt_rand(', 'shuffle(', 'array_rand(', 'str_shuffle('];
        $violations = $this->scanEngineCode($prohibited);

        $this->assertSame([], $violations, "Prohibited randomness in the engine:\n" . implode("\n", $violations));
    }

    /**
     * Scans compiled code tokens only — comments and doc-comments are excluded, so this
     * doesn't false-positive on this very file's own doc comments naming the strings
     * they prohibit (an earlier version of this test did exactly that).
     *
     * @param list<string> $needles
     * @return list<string>
     */
    private function scanEngineCode(array $needles): array
    {
        $engineDir = dirname(__DIR__, 2) . '/app/Domain/Games/Engine';
        $violations = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($engineDir));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    $violations[] = "$path references '$needle'";
                }
            }
        }

        return $violations;
    }
}
