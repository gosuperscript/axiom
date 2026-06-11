<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Illuminate\Container\Container;
use RuntimeException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final readonly class DelegatingResolver implements BindableResolver
{
    protected Container $container;

    /**
     * @param array<class-string<Source>, class-string<Resolver>> $resolverMap
     */
    public function __construct(public array $resolverMap = [])
    {
        $this->container = new Container();
        $this->container->instance(Resolver::class, $this);

        foreach ($this->resolverMap as $resolver) {
            $this->container->singleton($resolver, $resolver);
        }
    }

    /**
     * @param class-string $key
     */
    public function instance(string $key, mixed $concrete): void
    {
        $this->container->instance($key, $concrete);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * Dispatches to the resolver registered for the source's type. Every {@see Source} carries the
     * value type it resolves to as its `T` (defaulting to `mixed`), so the result is statically
     * narrowed to that type — `resolve(new TypeDefinition(new FooType(), $source))` is
     * `Result<Option<Foo>, Throwable>` and callers never have to re-coerce, while an ordinary source
     * still resolves to `Result<Option<mixed>, Throwable>`. The dispatch itself is dynamic, so the
     * narrowing is asserted here once (the delegate resolver produces the value the source describes).
     *
     * @template TSource of Source
     * @param TSource $source
     * @return Result<Option<template-type<TSource, Source, 'T'>>, Throwable>
     */
    public function resolve(Source $source, Context $context): Result
    {
        $sourceClass = get_class($source);

        if (isset($this->resolverMap[$sourceClass]) && $this->container->has($this->resolverMap[$sourceClass])) {
            /** @var Result<Option<template-type<TSource, Source, 'T'>>, Throwable> $result */
            $result = $this->container->make($this->resolverMap[$sourceClass])->resolve($source, $context);

            return $result;
        }

        throw new RuntimeException("No resolver found for source of type " . $sourceClass);
    }
}
