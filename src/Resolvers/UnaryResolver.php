<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * Resolves unary expressions through the unary overloader stack. Absence is
 * resolver-level: an absent operand short-circuits before any rule runs, so
 * optionality propagates and rules only ever see present values.
 *
 * @implements Resolver<UnaryExpression>
 */
final readonly class UnaryResolver implements Resolver
{
    private UnaryOverloader $overloader;

    public function __construct(
        public Resolver $resolver,
        ?UnaryOverloader $overloader = null,
    ) {
        $this->overloader = $overloader ?? UnaryOverloaderManager::default();
    }

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->operand, $context)
            ->andThen(fn(Option $option) => $option
                ->map(fn(mixed $value) => $this->overloader->evaluate($value, $source->operator))
            ->transpose())
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        $context->inspector?->annotate('label', $source->operator);

        return $result;
    }
}
