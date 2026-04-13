<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\CoerceSource;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function class_basename;

/**
 * Resolves a {@see CoerceSource}: evaluates the inner source, then runs
 * the target {@see \Superscript\Axiom\Types\Type}'s coerce() on the result.
 *
 * @implements Resolver<CoerceSource>
 */
final readonly class CoerceResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->source, $context)
            ->andThen(fn(Option $option) => $option
                ->andThen(function (mixed $value) use ($source, $context) {
                    return $source->target->coerce($value)
                        ->inspect(function (Option $coerced) use ($value, $context) {
                            $coerced->inspect(function (mixed $coercedValue) use ($value, $context) {
                                if ($coercedValue !== $value) {
                                    $context->inspector?->annotate('coercion', get_debug_type($value) . ' -> ' . get_debug_type($coercedValue));
                                }
                            });
                        })
                        ->transpose();
                })
                ->transpose());

        $context->inspector?->annotate('label', class_basename($source->target::class));

        return $result;
    }
}
