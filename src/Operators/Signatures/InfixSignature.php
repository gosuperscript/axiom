<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use Closure;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A compiled dispatch-table row: one declaration of operand ownership,
 * projected onto both faces. The runtime claim is strict membership on the
 * declared operand types (assert — claiming never converts; conversion
 * belongs to the boundary); the static verdict is admissibility against the
 * same types. One statement of the fact, so the two faces cannot drift —
 * the agreement harness holds by construction.
 */
final readonly class InfixSignature implements OperatorOverloader
{
    /** @param Closure(mixed, mixed): mixed $evaluation */
    public function __construct(
        private string $operator,
        private Type $left,
        private Type $right,
        private Type $returns,
        private Closure $evaluation,
    ) {}

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === $this->operator
            && $this->left->assert($left)->isOk()
            && $this->right->assert($right)->isOk();
    }

    /**
     * The closure only ever sees values both operand types asserted, so a
     * throw inside it is a defect of the extension, not a property of the
     * input — it propagates instead of masquerading as an evaluation error.
     *
     * @return Result<mixed, \Throwable>
     */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        $value = ($this->evaluation)($left, $right);

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
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch(sprintf('The [%s] signature does not handle [%s].', $this->operator, $operator)));
        }

        $causes = [];

        foreach ([[$left, $this->left], [$right, $this->right]] as [$operand, $slot]) {
            $admitted = TypeRelations::admits($operand, $slot);

            if ($admitted->isErr()) {
                $causes[] = $admitted->unwrapErr();
            }
        }

        if ($causes !== []) {
            return Err(new TypeMismatch(
                sprintf(
                    '[%s] expects %s and %s; got %s and %s.',
                    $this->operator,
                    TypeDescriber::describe($this->left),
                    TypeDescriber::describe($this->right),
                    TypeDescriber::describe($left),
                    TypeDescriber::describe($right),
                ),
                $causes,
            ));
        }

        return Ok($this->returns);
    }
}
