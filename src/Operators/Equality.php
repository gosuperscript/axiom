<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/** Equality semantics configured for one concrete operator alias. */
final readonly class Equality implements BinaryOperatorRule
{
    public function __construct(
        private string $operator,
        private bool $negated,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $support = ValueEquality::supports($left, $right);

        if ($support->isErr()) {
            $mismatch = $support->unwrapErr();

            return new UnsupportedOperation(
                sprintf('[%s] %s', $this->operator, $mismatch->message),
                $mismatch->causes,
            );
        }

        $overlap = TypeRelations::overlaps($left, $right);

        if ($overlap->isErr()) {
            return new DeadOperation(sprintf(
                '[%s] between %s and %s is constant: it %s.',
                $this->operator,
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
                $this->negated ? 'always holds' : 'can never hold',
            ), [$overlap->unwrapErr()]);
        }

        return new ResolvedOperation(
            new BooleanType(),
            fn(mixed $left, mixed $right) => $this->negated
                ? !ValueEquality::equals($left, $right)
                : ValueEquality::equals($left, $right),
        );
    }
}
