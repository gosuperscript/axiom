<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<SymbolSource>
 */
final readonly class SymbolResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $key = $source->namespace !== null
            ? "{$source->namespace}.{$source->name}"
            : $source->name;

        if ($context->bindings->has($source->name, $source->namespace)) {
            $value = $context->bindings->get($source->name, $source->namespace);
            $context->inspector?->annotate('label', $key);
            $value->inspect(fn(mixed $v) => $context->inspector?->annotate('result', $v));

            return Ok($value);
        }

        if ($context->hasMemoizedSymbol($key)) {
            $context->inspector?->annotate('label', $key);
            $context->inspector?->annotate('memo', 'hit');

            return $context->getMemoizedSymbol($key);
        }

        $result = $context->definitions->get($source->name, $source->namespace)
            ->andThen(fn(Source $definition) => $this->resolver->resolve($definition, $context)->transpose())
            ->transpose();

        $context->memoizeSymbol($key, $result);

        $context->inspector?->annotate('label', $key);
        $context->inspector?->annotate('memo', 'miss');
        $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        return $result;
    }
}
