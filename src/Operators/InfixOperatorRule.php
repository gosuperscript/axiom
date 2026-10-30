<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\HasValueIdentity;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * One binary operator rule, declared as data: "the operator [-] takes
 * (Date, Period), returns Date, and evaluates with this closure". Built via
 * the Operator::infix() builder.
 *
 * resolve() answers with a resolution when both operand types fit the
 * declared slots; the answer carries the
 * declared return type and closure. Because the compiler only ever calls
 * the closure with values of the declared operand types, the closure may
 * declare native parameter types (fn (Date $d, Period $p) => ...).
 *
 * A rule for an equality token (=, ==, ===, or the negations != and !==)
 * whose two slots are one type with value identity
 * ({@see HasValueIdentity}) also judges its operands the way core
 * {@see Equality} does: operand types that share no value make the
 * comparison constant, a dead operation. Comparing a union of the bands
 * 1..5 and 5..10 to the band 10..20 can never hold. Every other rule only
 * checks admissibility, because some packages deliberately compare types
 * that share no values.
 *
 * The operator and operand types are public properties because Dialect
 * reads them at construction to detect two rows that could both match
 * the same expression, which it refuses as ambiguous.
 */
final readonly class InfixOperatorRule implements BinaryOperatorRule, IdentifiedOperatorRule
{
    /** Equality tokens, each mapped to whether it is the negation. */
    private const array EqualityTokens = ['=' => false, '==' => false, '===' => false, '!=' => true, '!==' => true];

    public function __construct(
        public string $operator,
        public Type $left,
        public Type $right,
        public Type $returnType,
        private Closure $evaluation,
        private ?string $identifier = null,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function identifier(): string
    {
        return $this->identifier ?? sprintf(
            '%s:%s:%s',
            self::class,
            $this->operator,
            substr(hash('sha256', TypeDescriber::describe($this->left) . "\0" . TypeDescriber::describe($this->right)), 0, 12),
        );
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $causes = [];

        foreach ([[$left, $this->left], [$right, $this->right]] as [$operand, $slot]) {
            $admitted = TypeRelations::admits($operand, $slot);

            if ($admitted->isErr()) {
                $causes[] = $admitted->unwrapErr();
            }
        }

        if ($causes !== []) {
            return new UnsupportedOperation(
                sprintf(
                    '[%s] expects %s and %s; got %s and %s.',
                    $this->operator,
                    TypeDescriber::describe($this->left),
                    TypeDescriber::describe($this->right),
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                $causes,
            );
        }

        $negated = self::EqualityTokens[$this->operator] ?? null;

        if ($negated !== null && $this->comparesIdentity()) {
            $overlap = TypeRelations::overlaps($left, $right);

            if ($overlap->isErr()) {
                return new DeadOperation(sprintf(
                    '[%s] between %s and %s is constant: it %s.',
                    $this->operator,
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                    $negated ? 'always holds' : 'can never hold',
                ), [$overlap->unwrapErr()]);
            }
        }

        return new ResolvedOperation($this->returnType, $this->evaluation);
    }

    private function comparesIdentity(): bool
    {
        return $this->left instanceof HasValueIdentity && $this->left->shape()->equals($this->right->shape());
    }
}
