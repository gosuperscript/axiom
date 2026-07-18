<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/** A computed binary rule guarded by concrete operand Type classes. */
final readonly class MatchingInfixOperatorRule implements BinaryOperatorRule
{
    /**
     * @param class-string<Type> $left
     * @param class-string<Type> $right
     */
    public function __construct(
        private string $operator,
        private string $left,
        private string $right,
        private Closure $resolve,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        if (!$left instanceof $this->left || !$right instanceof $this->right) {
            return new UnsupportedOperation(sprintf(
                '[%s] does not match this rule for %s and %s; got %s and %s.',
                $this->operator,
                TypeDescriber::describeClass($this->left),
                TypeDescriber::describeClass($this->right),
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
            ));
        }

        $resolution = ($this->resolve)($left, $right);

        /** @var OperatorResolution $resolution */
        return $resolution;
    }
}
