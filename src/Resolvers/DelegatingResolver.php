<?php

namespace Superscript\Abacus\Resolvers;

use Closure;
use Illuminate\Container\Container;
use RuntimeException;
use Superscript\Abacus\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final readonly class DelegatingResolver implements Resolver
{
    protected Container $container;

    public function __construct(
        /** @var array<class-string<Resolver> */
        public array $resolvers = [],
    )
    {
        $this->container = new Container();
        $this->instance(Resolver::class, $this);
    }

    /**
     * @param class-string $key
     * @param Closure(): mixed $concrete
     */
    public function bind(string $key, Closure $concrete): void
    {
        $this->container->bind($key, $concrete);
    }

    /**
     * @param class-string $key
     */
    public function instance(string $key, mixed $concrete): void
    {
        $this->container->instance($key, $concrete);
    }

    /**
     * @template T
     * @param class-string<T> $abstract
     * @phpstan-return T
     */
    public function make(string $abstract): mixed
    {
        return $this->container->make($abstract);
    }

    public function call(callable $callable): mixed
    {
        return $this->container->call($callable);
    }

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        foreach ($this->resolvers as $resolver) {
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