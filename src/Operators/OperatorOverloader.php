<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

interface OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result;

    /**
     * Infer the result {@see Type} of applying $operator to operands of
     * the given types, without evaluating. Returns {@see None} if this
     * overloader does not accept the combination.
     *
     * @return Option<Type>
     */
    public function inferType(Type $left, Type $right, string $operator): Option;
}
