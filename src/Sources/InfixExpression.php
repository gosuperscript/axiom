<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Closure;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OperatorOverloader;
use Superscript\Schema\Operators\OverloaderManager;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;

final readonly class InfixExpression implements Source
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {}

    public function resolver(): Closure
    {
        return fn(Resolver $resolver) => $resolver->resolve($this->left)
            ->andThen(fn(Option $left) => $resolver->resolve($this->right)->map(fn(Option $right) => [$left, $right]))
            ->map(function (array $option) {
                [$left, $right] = $option;

                $result = $this->getOperatorOverloader()->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $this->operator);
                return Option::from($result);
            });
    }

    private function getOperatorOverloader(): OperatorOverloader
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }
}
