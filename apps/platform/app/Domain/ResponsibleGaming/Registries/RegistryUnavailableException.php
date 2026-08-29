<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming\Registries;

use RuntimeException;

/** Thrown by a RegistryClient implementation on a genuine network/provider failure — never on "not excluded". */
final class RegistryUnavailableException extends RuntimeException
{
}
