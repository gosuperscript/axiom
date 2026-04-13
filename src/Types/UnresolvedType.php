<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use RuntimeException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

/**
 * Sentinel returned by {@see \Superscript\Axiom\Source::type()} when a type
 * cannot be determined statically — e.g. an undeclared symbol, an operator
 * applied to incompatible operands, or a match expression whose arms do not
 * share a common type.
 *
 * {@see \Superscript\Axiom\TypeChecker} turns an {@see UnresolvedType} into
 * a {@see \Superscript\Axiom\Exceptions\TypeCheckException} so pre-execution
 * validation fails with an actionable message.
 *
 * Calling assert/coerce/compare/format on {@see UnresolvedType} is a bug —
 * it means code is trying to use a type that was never resolved.
 *
 * @extends AbstractType<never>
 */
final class UnresolvedType extends AbstractType
{
    public function __construct(
        public readonly string $reason,
    ) {}

    public function assert(mixed $value): Result
    {
        return new Err(new RuntimeException('UnresolvedType cannot assert values: ' . $this->reason));
    }

    public function coerce(mixed $value): Result
    {
        return new Err(new RuntimeException('UnresolvedType cannot coerce values: ' . $this->reason));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return false;
    }

    public function format(mixed $value): string
    {
        throw new RuntimeException('UnresolvedType cannot format values: ' . $this->reason);
    }

    public function accepts(Type $other): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'Unresolved(' . $this->reason . ')';
    }
}
