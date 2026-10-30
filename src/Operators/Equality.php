<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\HasValueIdentity;
use Superscript\Axiom\Types\IdentityLiteralType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;

use function Superscript\Monads\Result\Ok;

/**
 * Equality semantics configured for one concrete operator alias.
 *
 * Two kinds of operand compare. Values {@see ValueEquality} defines —
 * scalars, null, and arrays of them — compare by its rules. Values of one
 * type with value identity ({@see HasValueIdentity}) compare by identity
 * key: the bands 1..5 and 1.0..5 are equal because they key alike. Both
 * sides must be that one type, possibly narrowed to its literals or made
 * optional, so a select offering the bands 1..5 and 5..10 compares with
 * any band. Every other operand pair is unsupported; an object type
 * without identity keeps its equality in its owning package.
 *
 * Either way, operand types that share no value make the comparison
 * constant, a dead operation: the select above compared to 10..20 can
 * never hold.
 */
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
        $identity = self::sharedIdentity($left, $right);
        $support = $identity === null ? ValueEquality::supports($left, $right) : Ok(true);

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

        $equals = $identity === null
            ? ValueEquality::equals(...)
            : fn(mixed $left, mixed $right) => $left === null || $right === null
                ? $left === $right
                : $identity->identityKey($left) === $identity->identityKey($right);

        return new ResolvedOperation(
            new BooleanType(),
            fn(mixed $left, mixed $right) => $this->negated !== $equals($left, $right),
        );
    }

    /**
     * The one identity-bearing type both operands are values of, or null
     * when either operand is anything else or the two are different types.
     */
    private static function sharedIdentity(Type $left, Type $right): ?HasValueIdentity
    {
        $identity = self::identityOf($left);
        $other = self::identityOf($right);

        return $identity !== null && $other !== null && $identity->shape()->equals($other->shape()) ? $identity : null;
    }

    /**
     * The identity-bearing type whose values $type holds: the type itself,
     * the base of its literals, or that of an option or a union whose
     * members all share it.
     */
    private static function identityOf(Type $type): (Type&HasValueIdentity)|null
    {
        return match (true) {
            $type instanceof HasValueIdentity => $type,
            $type instanceof IdentityLiteralType => $type->base,
            $type instanceof OptionType => self::identityOf($type->inner),
            $type instanceof UnionType => self::unionIdentity($type),
            default => null,
        };
    }

    private static function unionIdentity(UnionType $union): (Type&HasValueIdentity)|null
    {
        $identity = self::identityOf($union->members[0]);

        foreach ($union->members as $member) {
            $other = self::identityOf($member);

            if ($identity === null || $other === null || !$identity->shape()->equals($other->shape())) {
                return null;
            }
        }

        return $identity;
    }
}
