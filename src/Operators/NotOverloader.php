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

/**
 * Logical negation: booleans only. PHP truthiness on other values is not a
 * defined negation.
 */
final readonly class NotOverloader implements UnaryOverloader
{
    private const operators = ['!', 'not'];

    public function supportsOverloading(mixed $operand, string $operator): bool
    {
        return is_bool($operand) && in_array($operator, self::operators, strict: true);
    }

    /**
     * @param bool $operand
     * @return Result<bool, never>
     */
    public function evaluate(mixed $operand, string $operator): Result
    {
        return Ok(!$operand);
    }

    public function handles(string $operator): bool
    {
        return in_array($operator, self::operators, strict: true);
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $operand): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('Negation does not handle [%s].', $operator)));
        }

        $boolean = new BooleanType();

        return TypeRelations::admits($operand, $boolean)
            ->map(fn() => $boolean)
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf('[%s] requires a present boolean; got %s.', $operator, TypeDescriber::describe($operand)),
                [$cause],
            ));
    }
}
