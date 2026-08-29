<?php

declare(strict_types=1);

// Binds to 127.0.0.1 only when served: `php -S 127.0.0.1:8081 -t public`.
// No database credentials are ever configured for this workspace — see project-context.md rule 8.

require __DIR__ . '/../vendor/autoload.php';

use Betplus\EngineBlackred\Engine;

header('Content-Type: application/json');
echo json_encode((new Engine())->describe());
