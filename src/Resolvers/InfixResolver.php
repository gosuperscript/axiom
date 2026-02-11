<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<InfixExpression>
 */
final readonly class InfixResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public OperatorOverloader $operatorOverloader,
        private ?ResolutionInspector $inspector = null,
    ) {}

    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', $source->operator);

        return $this->resolver->resolve($source->left)
            ->andThen(fn(Option $left) => $this->resolver->resolve($source->right)->map(fn(Option $right) => [$left, $right]))
            ->andThen(/** @param array{Option, Option} $option */ function (array $option) use ($source) {
                [$left, $right] = $option;

                try {
                    $result = $this->operatorOverloader->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $source->operator);
                    $this->inspector?->annotate('result', $result);

                    return Ok(Option::from($result));
                } catch (Throwable $e) {
                    return Err($e);
                }
            });
    }
}
