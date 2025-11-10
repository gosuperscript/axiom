<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Closure;
use Illuminate\Container\Container;
use Superscript\Schema\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final class DelegatingResolver implements Resolver
{
    private Container $container;

    /**
     * @var array<string, Closure(never, never, never, never, never, never, never): Result<Option<mixed>, Throwable>>
     */
    private array $resolveUsing = [];

    public function __construct()
    {
        $this->container = new Container();
        $this->container->instance(Resolver::class, $this);
    }

    /**
     * @param class-string $key
     */
    public function instance(string $key, mixed $concrete): void
    {
        $this->container->instance($key, $concrete);
    }

    public function resolveUsing(string $source, Closure $resolver): self
    {
        $this->resolveUsing[$source] = $resolver;

        return $this;
    }

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        /** @var Result<Option<mixed>, Throwable> */
        return $this->container->call($this->getResolver($source));
    }

    /**
     * @return Closure(never, never, never, never, never, never, never): Result<Option<mixed>, Throwable>
     */
    private function getResolver(Source $source): Closure
    {
        return $this->resolveUsing[$source::class] ?? $source->resolver();
    }
}
