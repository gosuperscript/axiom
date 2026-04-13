<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use InvalidArgumentException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<MemberAccessSource>
 */
final readonly class MemberAccessResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->object, $context)
            ->andThen(fn(Option $option) => $option
                ->mapOr(Ok(None()), fn(mixed $value) => $this->access($value, $source->property)))
            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $context->inspector?->annotate('result', $value)));

        $context->inspector?->annotate('label', ".{$source->property}");

        return $result;
    }

    /**
     * @return Result<Option<mixed>, \Throwable>
     */
    private function access(mixed $value, string $property): Result
    {
        if (is_array($value) && array_key_exists($property, $value)) {
            return Ok(Option::from($value[$property]));
        }

        if (is_object($value) && property_exists($value, $property)) {
            return Ok(Option::from($value->{$property}));
        }

        if (is_array($value) || is_object($value)) {
            return Err(new InvalidArgumentException("Property '{$property}' does not exist"));
        }

        return Err(new InvalidArgumentException("Cannot access property '{$property}' on " . get_debug_type($value)));
    }
}
