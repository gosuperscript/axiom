<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;

interface OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result;
}
