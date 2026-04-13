<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use InvalidArgumentException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Psl\Type\num;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
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
                ->map(fn(mixed $value) => match ($source->operator) {
                    '!', 'not' => Ok(!$value),
                    '-' => num()->matches($value) ? Ok(-$value) : Err(new InvalidArgumentException("not numeric")),
                    default => Err(new InvalidArgumentException("Unsupported operator: {$source->operator}")),
                })
            ->transpose())
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        $context->inspector?->annotate('label', $source->operator);

        return $result;
    }
}
