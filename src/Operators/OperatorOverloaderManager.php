<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use RuntimeException;
use Superscript\Monads\Result\Result;

interface OperatorOverloaderManager
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    /** @return Result<mixed, RuntimeException> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result;
}
