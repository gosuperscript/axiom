<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function class_basename;

/**
 * @implements Resolver<TypeDefinition>
 */
final readonly class ValueResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->source, $context)
            ->andThen(
                fn(Option $option) => $option
                ->andThen(function (mixed $result) use ($source, $context) {
                    return $source->type->coerce($result)
                        ->inspect(function (Option $coerced) use ($result, $context) {
                            $coerced->inspect(function (mixed $coercedValue) use ($result, $context) {
                                if ($coercedValue !== $result) {
                                    $context->inspector?->annotate('coercion', get_debug_type($result) . ' -> ' . get_debug_type($coercedValue));
                                }
                            });
                        })
                        ->transpose();
                })
                ->transpose(),
            );

        $context->inspector?->annotate('label', class_basename($source->type::class));

        return $result;
    }
}
