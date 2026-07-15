<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Equality is a type function, not a dispatch row: its verdict is computed
 * from the operand types. Both operand types must lie within the claimed
 * domain (scalar/null/array values — no objects, whose equality belongs to
 * the rule that owns the type, now expressible as an ordinary row; no
 * Unknown, which is inert) and must overlap — a comparison that can never
 * hold is dead code, not a boolean. Absence is tolerated: options always
 * overlap, and equality against the null literal is the emptiness test.
 *
 * Evaluation is value equality ({@see ValueEquality}), never PHP juggling;
 * === and !== are aliases of ==/!= — strictness was only distinct while ==
 * juggled. The negation is baked into the closure at resolution.
 */
final readonly class EqualityOverloader implements OperatorOverloader
{
    private const operators = ['=', '==', '===', '!=', '!=='];

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        if (!in_array($operator, self::operators, strict: true)) {
            return Err(new TypeMismatch(sprintf('Equality does not resolve [%s].', $operator), unhandled: true));
        }

        // Totality: the resolution certifies EVERY value of the operand
        // types, so a type with object inhabitants — values ValueEquality
        // makes no claim about — is refused, universally over union members.
        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            if (!ShapeDomain::all($operand->shape(), fn(Shape $leaf) => !$leaf instanceof OpaqueShape)) {
                return Err(new TypeMismatch(sprintf(
                    '[%s] does not claim the %s operand: %s has object or Unknown values; object equality belongs to the rule that owns the type, and an Unknown value is bridged with Coerce or Ascription first.',
                    $operator,
                    $side,
                    TypeDescriber::describe($operand),
                )));
            }
        }

        $negated = in_array($operator, ['!=', '!=='], strict: true);

        return TypeRelations::overlaps($left, $right)
            ->map(fn() => new ResolvedOperation(
                new BooleanType(),
                fn(mixed $l, mixed $r) => $negated
                    ? !ValueEquality::equals($l, $r)
                    : ValueEquality::equals($l, $r),
            ))
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
}
