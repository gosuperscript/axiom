<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use RuntimeException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function class_basename;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Evaluates the Ascription node: the resolved value is verified against the
 * claimed type via assert() — strict membership, no conversion. A lying
 * ascription fails loudly here, so the author's claim is a tripwire rather
 * than a rot vector.
 *
 * Absence cannot cross a non-optional claim: the checker takes the claimed
 * type at its word, so an absent value under a claim that is not
 * Option-shaped is a runtime Err — null only inhabits an Option, and a
 * claim of Number? is how an author says absence is legal here.
 *
 * @implements Resolver<Ascription>
 */
final readonly class AscriptionResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->source, $context)
            ->andThen(
                fn(Option $option) => $option
                ->andThen(fn(mixed $value) => $source->type->assert($value)->transpose())
                ->transpose(),
            )
            ->andThen(fn(Option $option) => $this->required($option, $source));

        $context->inspector?->annotate('label', 'is ' . class_basename($source->type::class));

        return $result;
    }

    /**
     * @param Option<mixed> $option
     * @return Result<Option<mixed>, RuntimeException>
     */
    private function required(Option $option, Ascription $source): Result
    {
        if ($option->isNone() && !($source->type->shape() instanceof OptionShape)) {
            return Err(new RuntimeException(sprintf(
                'The ascribed value reads as missing, but the claim %s is required; claim %s instead if absence is legal here.',
                TypeDescriber::describe($source->type),
                TypeDescriber::describe(new OptionType($source->type)),
            )));
        }

        return Ok($option);
    }
}
