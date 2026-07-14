<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\StaticAnalysis;

use Superscript\Axiom\Context;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

use function PHPStan\Testing\assertType;

/**
 * Static-analysis proof that {@see DelegatingResolver::resolve()} infers the resolved value type from
 * a {@see Source}'s `T` (a {@see Coerce} or a {@see StaticSource} carries it; ordinary
 * sources default to `mixed`). These `assertType()` calls are checked by PHPStan during
 * `composer test:types`; a regression in the return type fails the build. Not executed at runtime.
 */
function resolve_infers_value_type_from_type_definition(DelegatingResolver $resolver, Context $context): void
{
    // The Coerce itself carries the Type's value parameter.
    assertType(
        'Superscript\Axiom\Sources\Coerce<string>',
        new Coerce(new StringType(), new StaticSource('x')),
    );

    // Resolving it narrows Option to that value type — no coercion needed by the caller.
    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<string>, Throwable>',
        $resolver->resolve(new Coerce(new StringType(), new StaticSource('x')), $context),
    );

    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<bool>, Throwable>',
        $resolver->resolve(new Coerce(new BooleanType(), new StaticSource(true)), $context),
    );

    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<float|int>, Throwable>',
        $resolver->resolve(new Coerce(new NumberType(), new StaticSource(1)), $context),
    );
}

/**
 * Composed types propagate end to end: a `Type<list<T>>` or `Type<array<string, T>>` built from an
 * inner `Type<T>` resolves to `Option<list<T>>` / `Option<array<string, T>>` through the same
 * machinery — the return only reads the `T` the source carries, however deep the type nests.
 */
function resolve_infers_composed_value_types(DelegatingResolver $resolver, Context $context): void
{
    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<list<float|int>>, Throwable>',
        $resolver->resolve(new Coerce(new ListType(new NumberType()), new StaticSource([1])), $context),
    );

    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<array<string, string>>, Throwable>',
        $resolver->resolve(new Coerce(new DictType(new StringType()), new StaticSource(['k' => 'v'])), $context),
    );
}

/**
 * A {@see StaticSource} carries its value type directly, so it infers the type of
 * the value it holds without a wrapping {@see Coerce}.
 */
function resolve_infers_static_source_value_type(DelegatingResolver $resolver, Context $context): void
{
    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<string>, Throwable>',
        $resolver->resolve(new StaticSource('x'), $context),
    );
}

/**
 * Sources that do not carry a narrower value type keep the default `Option<mixed>`
 * fallback, so the conditional return type is additive and does not over-narrow other sources.
 */
function resolve_falls_back_to_mixed_for_plain_sources(DelegatingResolver $resolver, Context $context): void
{
    assertType(
        'Superscript\Monads\Result\Result<Superscript\Monads\Option\Option<mixed>, Throwable>',
        $resolver->resolve(new SymbolSource('x'), $context),
    );
}
