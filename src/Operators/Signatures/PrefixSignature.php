<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The unary twin of {@see InfixSignature}: one declaration, both faces,
 * drift unrepresentable.
 */
final readonly class PrefixSignature implements UnaryOverloader
{
    /** @param Closure(mixed): mixed $evaluation */
    public function __construct(
        private string $operator,
        private Type $operand,
        private Type $returns,
        private Closure $evaluation,
    ) {}

    public function supportsOverloading(mixed $operand, string $operator): bool
    {
        return $operator === $this->operator && $this->operand->assert($operand)->isOk();
    }

    /**
     * The closure only ever sees values the operand type asserted, so a
     * throw inside it is a defect of the extension — it propagates.
     *
     * @return Result<mixed, \Throwable>
     */
    public function evaluate(mixed $operand, string $operator): Result
    {
        $value = ($this->evaluation)($operand);

        if ($value instanceof Result) {
            /** @var Result<mixed, \Throwable> $value */
            return $value;
        }

        return Ok($value);
    }

    public function handles(string $operator): bool
    {
        return $operator === $this->operator;
    }

    /** @return Result<Type, TypeMismatch> */
    public function typeOf(string $operator, Type $operand): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('The [%s] signature does not handle [%s].', $this->operator, $operator)));
        }

        return TypeRelations::admits($operand, $this->operand)
            ->map(fn() => $this->returns)
            ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                sprintf(
                    '[%s] expects %s; got %s.',
                    $this->operator,
                    TypeDescriber::describe($this->operand),
                    TypeDescriber::describe($operand),
                ),
                [$cause],
            ));
    }
}
