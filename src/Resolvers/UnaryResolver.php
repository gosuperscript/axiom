<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * Resolves unary expressions through the unary overloader stack of the
 * context's dialect. Absence is resolver-level: an absent operand
 * short-circuits before any rule runs, so optionality propagates and rules
 * only ever see present values.
 *
 * @implements Resolver<UnaryExpression>
 */
final readonly class UnaryResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->operand, $context)
            ->andThen(fn(Option $option) => $option
                ->map(fn(mixed $value) => $context->unaryOperators()->evaluate($value, $source->operator))
            ->transpose())
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        $context->inspector?->annotate('label', $source->operator);

        return $result;
    }
}
