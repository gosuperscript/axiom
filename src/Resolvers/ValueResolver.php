<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\ResolutionInspector;
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
        private ?ResolutionInspector $inspector = null,
    ) {}

    /**
     * @return Result<Option<mixed>, mixed>
     */
    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', class_basename($source->type::class));

        return $this->resolver->resolve($source->source)
            ->andThen(
                fn(Option $option) => $option
                ->andThen(function (mixed $result) use ($source) {
                    return $source->type->coerce($result)
                        ->inspect(function (Option $coerced) use ($result) {
                            $coerced->inspect(function (mixed $coercedValue) use ($result) {
                                if ($coercedValue !== $result) {
                                    $this->inspector?->annotate('coercion', get_debug_type($result) . ' -> ' . get_debug_type($coercedValue));
                                }
                            });
                        })
                        ->transpose();
                })
                ->transpose(),
            );
    }
}
