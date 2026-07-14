<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class LogicalOverloader implements OperatorOverloader
{
    private const operators = ['&&', '||', 'xor'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return in_array($operator, self::operators) && is_bool($left) && is_bool($right);
    }

    /**
     * @param value-of<self::operators> $operator
     * @return Result<bool, never>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok(match ($operator) {
            '&&' => $left && $right,
            '||' => $left || $right,
            'xor' => $left xor $right,
        });
    }

    public function handles(string $operator): bool
    {
        return in_array($operator, self::operators);
    }

    /**
     * Two present booleans → Boolean. Refuses Option: the runtime has no arm
     * for an absent operand.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Logic does not handle [%s].', $operator)));
        }

        $boolean = new BooleanType();
        $causes = [];

        foreach (['left' => $left, 'right' => $right] as $side => $operand) {
            $admitted = TypeRelations::admits($operand, $boolean);

            if ($admitted->isErr()) {
                $causes[] = new TypeMismatch(sprintf('The %s operand is not a present boolean.', $side), [$admitted->unwrapErr()]);
            }
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(
                sprintf('[%s] requires two present booleans; got %s and %s.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                $causes,
            ));
        }

        return Ok($boolean);
    }
}
