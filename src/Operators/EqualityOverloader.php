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
 * The equality rule (==, ===, !=, !==). Unlike a dispatch row, its answer
 * is computed from the operand types:
 *
 * - Both operand types must consist of scalars, null, and arrays of them.
 *   Objects are refused — object equality belongs to the package that
 *   owns the type, as its own rule — and so is Unknown.
 * - The types must overlap: if no value could inhabit both, the
 *   comparison has a constant answer, and that is reported as a `dead`
 *   mismatch (a probable author bug) rather than resolved to a boolean.
 * - Absence is fine: options always overlap, so x == null works as the
 *   emptiness test.
 *
 * Evaluation is {@see ValueEquality}, so 1 == 1.0 and 5 != '5'; === and
 * !== are plain aliases of == and != (strictness only meant something
 * when == juggled types). Negation is baked into the closure when the
 * operator is != or !==.
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
