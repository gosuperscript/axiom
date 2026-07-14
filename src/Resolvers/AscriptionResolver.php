<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function class_basename;

/**
 * Evaluates the Ascription node: the resolved value is verified against the
 * claimed type via assert() — strict membership, no conversion. A lying
 * ascription fails loudly here, so the author's claim is a tripwire rather
 * than a rot vector. An absent value passes through: null inhabits the
 * option the checker gave the claim.
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
            );

        $context->inspector?->annotate('label', 'is ' . class_basename($source->type::class));

        return $result;
    }
}
