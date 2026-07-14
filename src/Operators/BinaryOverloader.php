<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\attempt;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class BinaryOverloader implements OperatorOverloader
{
    private const operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return (is_int($left) || is_float($left))
            && (is_int($right) || is_float($right))
            && in_array($operator, self::operators);
    }

    /**
     * @param int|float $left
     * @param int|float $right
     * @param value-of<self::operators> $operator
     * @return Result<int|float, \Throwable>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return attempt(fn () => match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
        });
    }

    public function handles(string $operator): bool
    {
        return in_array($operator, self::operators);
    }

    /**
     * Two present numbers → Number. Refuses Option: null + 1 is unsupported
     * here (a dialect that reads absence as zero ships its own rule).
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Arithmetic does not handle [%s].', $operator)));
        }

        $number = new NumberType();
        $causes = [];

        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            $admitted = TypeRelations::admits($operand, $number);

            if ($admitted->isErr()) {
                $causes[] = new TypeMismatch(sprintf('The %s operand is not a present number.', $side), [$admitted->unwrapErr()]);
            }
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(
                sprintf('[%s] requires two present numbers; got %s and %s.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                $causes,
            ));
        }

        return Ok($number);
    }
}
