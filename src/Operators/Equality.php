<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
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
        // Comparing against the null literal asks whether the other operand holds a value at all,
        // and presence is structural: it does not depend on what equality means for the value
        // inside. An opaque type that has deliberately not defined equality is still either
        // present or absent, so the question stays answerable where value equality is not — and it
        // is asked of opaque types most, because they are the ones no sentinel value can stand in
        // for.
        //
        // Unknown is the exception, and not for want of an answer: it is inert by construction, so
        // nothing resolves over it until it is claimed or coerced. Presence would be the one
        // question askable of a value the engine knows nothing else about, and answering it would
        // be the first crack in that.
        $comparesAbsence =
            (self::isAbsenceLiteral($left) && self::isKnown($right))
            || (self::isAbsenceLiteral($right) && self::isKnown($left));

        if (!$comparesAbsence) {
            $support = ValueEquality::supports($left, $right);

            if ($support->isErr()) {
                $mismatch = $support->unwrapErr();

                return new UnsupportedOperation(
                    sprintf('[%s] %s', $this->operator, $mismatch->message),
                    $mismatch->causes,
                );
            }
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

        $equal = $comparesAbsence
            ? static fn(mixed $left, mixed $right): bool => ($left === null) === ($right === null)
            : ValueEquality::equals(...);

        return new ResolvedOperation(
            new BooleanType(),
            fn(mixed $left, mixed $right) => $this->negated ? !$equal($left, $right) : $equal($left, $right),
        );
    }

    /**
     * Whether this type's only value is absence — the type the `null` literal infers to. Its shape
     * is an option over Never: permission to hold nothing, and nothing it could hold instead.
     */
    private static function isAbsenceLiteral(Type $type): bool
    {
        $shape = $type->shape();

        return $shape instanceof OptionShape && $shape->inner instanceof NeverShape;
    }

    /**
     * Whether the engine knows what every value of this type is. {@see ShapeDomain::all} refuses
     * Unknown whatever the leaf predicate says, so asking it nothing is asking exactly this.
     */
    private static function isKnown(Type $type): bool
    {
        return ShapeDomain::all($type->shape(), static fn(): bool => true);
    }
}
