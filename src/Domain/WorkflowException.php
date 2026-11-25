<?php

declare(strict_types=1);

namespace App\Domain;

/** A user-facing validation failure — its message is safe to flash verbatim. */
final class WorkflowException extends \RuntimeException
{
}
