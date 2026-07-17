<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

/** Concise constructors for the verdicts returned by computed rules. */
final readonly class Operation
{
    public static function returns(Type $type): ResolvedOperationBuilder
    {
        return new ResolvedOperationBuilder($type);
    }

    /** @param list<TypeMismatch> $causes */
    public static function unsupported(string $message, array $causes = []): UnsupportedOperation
    {
        return new UnsupportedOperation($message, $causes);
    }

    /** @param list<TypeMismatch> $causes */
    public static function dead(string $message, array $causes = []): DeadOperation
    {
        return new DeadOperation($message, $causes);
    }
}
