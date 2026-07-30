<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

/**
 * A boolean connective (`&&`, `||`) over possibly-absent operands, with
 * Kleene's three-valued semantics: a dominant operand decides alone —
 * `false` decides a conjunction and `true` decides a disjunction — and
 * only a result no present operand can decide is absent. Generic lifting
 * ({@see ResolvedOperation::liftedOverAbsence()}) would get this wrong:
 * strict propagation answers `true || unanswered` with absence, when the
 * present `true` already decides it.
 *
 * Both operands present make the connective total and its result present;
 * `!`, `not` and `xor` stay ordinary rows because for them strictness and
 * Kleene agree — no operand value decides those alone.
 */
final readonly class Connective implements BinaryOperatorRule, IdentifiedOperatorRule
{
    public function __construct(
        private string $operator,
        private bool $conjunction,
        private string $identifier,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        $causes = [];

        foreach ([$left, $right] as $operand) {
            $admitted = TypeRelations::admits($operand, new OptionType(new BooleanType()));

            if ($admitted->isErr()) {
                $causes[] = $admitted->unwrapErr();
            }
        }

        if ($causes !== []) {
            return new UnsupportedOperation(
                sprintf(
                    '[%s] expects Boolean and Boolean; got %s and %s.',
                    $this->operator,
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                $causes,
            );
        }

        $optional = $left->shape() instanceof OptionShape || $right->shape() instanceof OptionShape;
        $dominant = !$this->conjunction;

        return new ResolvedOperation(
            $optional ? new OptionType(new BooleanType()) : new BooleanType(),
            static function (?bool $left, ?bool $right) use ($dominant): ?bool {
                if ($left === $dominant || $right === $dominant) {
                    return $dominant;
                }

                if ($left === null || $right === null) {
                    return null;
                }

                return !$dominant;
            },
        );
    }
}
