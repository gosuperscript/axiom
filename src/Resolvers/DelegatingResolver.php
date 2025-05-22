<?php

declare(strict_types=1);

namespace Superscript\Abacus\Resolvers;

use Illuminate\Container\Container;
use RuntimeException;
use Superscript\Abacus\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final readonly class DelegatingResolver implements Resolver
{
    protected Container $container;

    /**
     * @param list<class-string<Resolver>> $resolvers
     */
    public function __construct(public array $resolvers = [])
    {
        $this->container = new Container();
        $this->instance(Resolver::class, $this);
    }

    /**
     * @param class-string $key
     */
    public function instance(string $key, mixed $concrete): void
    {
        $this->container->instance($key, $concrete);
    }

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        foreach ($this->resolvers as $resolver) {
            // Not sure about this yet.
            if ($resolver::supports($source)) {
                return $this->container->make($resolver)->resolve($source);
            }

        }

        throw new RuntimeException("No resolver found for source of type " . get_class($source));
    }

    public static function supports(Source $source): bool
    {
        return true;
    }
}
