<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Closure;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Schema\Types\Type;

/**
 * @template T
 * @implements Source<T>
 */
final readonly class ValueDefinition implements Source
{
    /**
     * @param Type<T> $type
     */
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}

    public function resolver(): Closure
    {
        return fn(Resolver $resolver) => $resolver->resolve($this->source)
            ->andThen(
                fn(Option $option) => $option
                    ->andThen(fn(mixed $result) => $this->type->coerce($result)->transpose())
                    ->transpose(),
            );
    }
}
