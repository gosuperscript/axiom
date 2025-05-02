<?php

namespace Superscript\Abacus\Resolvers;

use Superscript\Abacus\Operators\DefaultOverloader;
use Superscript\Abacus\Operators\OperatorOverloader;
use Superscript\Abacus\Operators\OverloaderManager;
use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\InfixExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * @implements Resolver<InfixExpression>
 */
final readonly class InfixResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
    ) {
    }

    public function resolve(Source $source): Result
    {
        return $this->resolver->resolve($source->left)
            ->andThen(fn(Option $left) => $this->resolver->resolve($source->right)->map(fn(Option $right) => [$left, $right]))
            ->map(function (array $option) use ($source) {
                [$left, $right] = $option;

                $result = $this->getOperatorOverloader()->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $source->operator);
                return Option::from($result);
            });
    }

    private function getOperatorOverloader(): OperatorOverloader
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }

    public static function supports(Source $source): bool
    {
        return $source instanceof InfixExpression;
    }
}