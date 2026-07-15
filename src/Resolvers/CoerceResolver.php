<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use RuntimeException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function class_basename;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Evaluates the Coerce boundary node: the resolved value is converted into
 * the declared type via coerce(), the lenient admission policy.
 *
 * Absence cannot cross a non-optional Coerce: the node certifies its
 * declared type, and absence only inhabits an Option — so when the inner
 * source resolves absent, or coercion reads the value as missing ('' under
 * Number), resolution is an Err naming the requirement, mirroring the
 * boundary. Silently passing None through would deliver null into an
 * expression certified to receive the declared type.
 *
 * @implements Resolver<Coerce>
 */
final readonly class CoerceResolver implements Resolver
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
            )
            ->andThen(fn(Option $option) => $this->required($option, $source));

        $context->inspector?->annotate('label', class_basename($source->type::class));

        return $result;
    }

    /**
     * @param Option<mixed> $option
     * @return Result<Option<mixed>, RuntimeException>
     */
    private function required(Option $option, Coerce $source): Result
    {
        if ($option->isNone() && !($source->type->shape() instanceof OptionShape)) {
            return Err(new RuntimeException(sprintf(
                'The coerced value reads as missing, but %s is required; coerce to %s instead if absence is legal here.',
                TypeDescriber::describe($source->type),
                TypeDescriber::describe(new OptionType($source->type)),
            )));
        }

        return Ok($option);
    }
}
