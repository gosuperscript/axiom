<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * @implements Resolver<InfixExpression>
 */
final readonly class InfixResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public OperatorOverloader $operatorOverloader,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->left, $context)
            ->andThen(fn(Option $left) => $this->resolver->resolve($source->right, $context)->map(fn(Option $right) => [$left, $right]))
            ->andThen(/** @param array{Option, Option} $option */ function (array $option) use ($source, $context) {
                [$left, $right] = $option;

                $context->inspector?->annotate('left', $left->unwrapOr(null));
                $context->inspector?->annotate('right', $right->unwrapOr(null));

                return $this->operatorOverloader->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $source->operator)
                    ->inspect(fn($result) => $context->inspector?->annotate('result', $result))
                    ->map(fn($result) => Option::from($result));
            });

        $context->inspector?->annotate('label', $source->operator);

        return $result;
    }
}
