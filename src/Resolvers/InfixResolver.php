<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
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
    ) {}

    public function resolve(Source $source): Result
    {
        return $this->resolver->resolve($source->left)
            ->andThen(fn(Option $left) => $this->resolver->resolve($source->right)->map(fn(Option $right) => [$left, $right]))
            ->andThen(/** @param array{Option, Option} $option */ function (array $option) use ($source) {
                [$left, $right] = $option;

                return $this->getOperatorOverloader()->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $source->operator)
                    ->map(fn (mixed $result) => Option::from($result));
            });
    }

    private function getOperatorOverloader(): OverloaderManager
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }
}
