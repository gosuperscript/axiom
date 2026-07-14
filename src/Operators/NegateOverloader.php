<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Arithmetic negation: numbers only.
 */
final readonly class NegateOverloader implements UnaryOverloader
{
    public function supportsOverloading(mixed $operand, string $operator): bool
    {
        return (is_int($operand) || is_float($operand)) && $operator === '-';
    }

    /**
     * @param int|float $operand
     * @return Result<int|float, never>
     */
    public function evaluate(mixed $operand, string $operator): Result
    {
        return Ok(-$operand);
    }

    public function handles(string $operator): bool
    {
        return $operator === '-';
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $operand): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Arithmetic negation does not handle [%s].', $operator)));
        }

        $number = new NumberType();

        return TypeRelations::admits($operand, $number)
            ->map(fn() => $number)
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf('[%s] requires a present number; got %s.', $operator, TypeDescriber::describe($operand)),
                [$cause],
            ));
    }
}
