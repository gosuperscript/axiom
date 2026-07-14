<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use RuntimeException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
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
            // The resolution channel has one representation of null: None.
            // A bound null still shadows a definition — shadowing lives in
            // has(), checked above — but its value is honestly absent.
            $value = $context->bindings->get($source->name, $source->namespace)
                ->andThen(fn(mixed $v) => Option::from($v));
            $context->inspector?->annotate('label', $key);
            $value->inspect(fn(mixed $v) => $context->inspector?->annotate('result', $v));

            return Ok($value);
        }

        if ($context->hasMemoizedSymbol($key)) {
            $context->inspector?->annotate('label', $key);
            $context->inspector?->annotate('memo', 'hit');

            return $context->getMemoizedSymbol($key);
        }

        if (!$context->beginSymbolResolution($key)) {
            return Err(new RuntimeException(sprintf(
                'Cyclic symbol definition [%s]; the definition graph must be well-founded. Running check() reports the full cycle statically.',
                $key,
            )));
        }

        $result = $context->definitions->get($source->name, $source->namespace)
            ->andThen(fn(Source $definition) => $this->resolver->resolve($definition, $context)->transpose())
            ->transpose();

        $context->endSymbolResolution($key);

        $context->memoizeSymbol($key, $result);

        $context->inspector?->annotate('label', $key);
        $context->inspector?->annotate('memo', 'miss');
        $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        return $result;
    }
}
