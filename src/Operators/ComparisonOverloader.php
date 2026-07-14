<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeOrder;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class ComparisonOverloader implements OperatorOverloader
{
    private const equalityOperators = ['=', '==', '===', '!=', '!=='];
    private const orderingOperators = ['<', '<=', '>', '>='];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        if (in_array($operator, self::equalityOperators)) {
            return $this->isComparable($left) && $this->isComparable($right);
        }

        if (in_array($operator, self::orderingOperators)) {
            return (is_int($left) || is_float($left)) && (is_int($right) || is_float($right));
        }

        return false;
    }

    /**
     * Equality is defined for scalars, null, and arrays of them — never for
     * objects, whose equality belongs to the overloader that owns the type.
     */
    private function isComparable(mixed $value): bool
    {
        if (is_array($value)) {
            return array_all($value, $this->isComparable(...));
        }

        return $value === null || is_scalar($value);
    }

    /**
     * Equality is value equality ({@see ValueEquality}), never PHP juggling.
     * === and !== are aliases of ==/!= — strictness was only distinct while
     * == juggled.
     *
     * @param value-of<self::equalityOperators>|value-of<self::orderingOperators> $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok(match ($operator) {
            '=', '==', '===' => ValueEquality::equals($left, $right),
            '!=', '!==' => !ValueEquality::equals($left, $right),
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
        });
    }

    public function handles(string $operator): bool
    {
        return in_array($operator, self::equalityOperators) || in_array($operator, self::orderingOperators);
    }

    /**
     * Equality requires both operand types to lie within this rule's claimed
     * domain (scalar/null/array values — no objects, whose equality belongs
     * to the rule that owns the type; certifying them here is a certified
     * crash) and the types to overlap — a comparison that can never hold is
     * dead code, not a boolean. Absence is tolerated: options always
     * overlap, and equality against the null literal is the emptiness test.
     * Ordering requires a defined order on both present operand types
     * (Unknown tolerated), and needs no overlap: ranking distinct numbers is
     * the point.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (in_array($operator, self::equalityOperators)) {
            // Totality: Ok certifies EVERY value of the operand types, so a
            // type with object inhabitants — the values isComparable()
            // refuses — is unsupported here, universally over union members.
            foreach (['left' => $left, 'right' => $right] as $side => $operand) {
                if (!ShapeDomain::all($operand->shape(), fn(Shape $leaf) => !$leaf instanceof OpaqueShape)) {
                    return Err(new TypeMismatch(sprintf(
                        '[%s] does not claim the %s operand: %s has object values, and object equality belongs to the rule that owns the type.',
                        $operator,
                        $side,
                        TypeDescriber::describe($operand),
                    )));
                }
            }

            $negated = in_array($operator, ['!=', '!==']);

            return TypeRelations::overlaps($left, $right)
                ->map(fn() => new BooleanType())
                ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                    sprintf(
                        '[%s] between %s and %s is constant: it %s.',
                        $operator,
                        TypeDescriber::describe($left),
                        TypeDescriber::describe($right),
                        $negated ? 'always holds' : 'can never hold',
                    ),
                    [$cause],
                    dead: true,
                ));
        }

        if (!in_array($operator, self::orderingOperators)) {
            return Err(new TypeMismatch(sprintf('Comparison does not handle [%s].', $operator)));
        }

        $causes = [];

        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            if (!$operand->shape() instanceof UnknownShape && !TypeOrder::hasDefinedOrder($operand)) {
                $causes[] = new TypeMismatch(sprintf('The %s operand %s has no defined order.', $side, TypeDescriber::describe($operand)));
            }
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(
                sprintf('[%s] requires ordered operands; got %s and %s.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                $causes,
            ));
        }

        return Ok(new BooleanType());
    }
}
