<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/** A computed unary rule guarded by a concrete operand Type class. */
final readonly class MatchingPrefixOperatorRule implements UnaryOperatorRule, IdentifiedOperatorRule
{
    /**
     * @param class-string<Type> $operand
     */
    public function __construct(
        private string $operator,
        private string $operand,
        private Closure $resolve,
        private ?string $identifier = null,
    ) {}

    public function operator(): string
    {
        return $this->operator;
    }

    public function identifier(): string
    {
        return $this->identifier ?? sprintf('%s:%s(%s)', self::class, $this->operator, $this->operand);
    }

    public function resolve(Type $operand): OperatorResolution
    {
        if (!$operand instanceof $this->operand) {
            return new UnsupportedOperation(sprintf(
                '[%s] does not match this rule for %s; got %s.',
                $this->operator,
                TypeDescriber::describeClass($this->operand),
                TypeDescriber::describe($operand),
            ));
        }

        $resolution = ($this->resolve)($operand);

        /** @var OperatorResolution $resolution */
        return $resolution;
    }
}
