<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\MemberAccessResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;

#[CoversClass(MemberAccessSource::class)]
#[CoversClass(MemberAccessResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(SymbolRegistry::class)]
class MemberAccessResolverTest extends TestCase
{
    #[Test]
    public function it_can_access_an_array_key(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource(['name' => 'John']),
            property: 'name',
        );

        $this->assertEquals('John', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_for_null_array_value(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource(['name' => null]),
            property: 'name',
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_err_for_missing_array_key(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource(['name' => 'John']),
            property: 'age',
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }

    #[Test]
    public function it_can_access_an_object_property(): void
    {
        $object = new \stdClass();
        $object->name = 'John';

        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource($object),
            property: 'name',
        );

        $this->assertEquals('John', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_chained_access_through_delegating_resolver(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MemberAccessSource::class => MemberAccessResolver::class,
        ]);

        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'quote' => new StaticSource([
                'address' => ['postcode' => 'SW1A 1AA'],
            ]),
        ]));

        $source = new MemberAccessSource(
            object: new MemberAccessSource(
                object: new SymbolSource('quote'),
                property: 'address',
            ),
            property: 'postcode',
        );

        $this->assertEquals('SW1A 1AA', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_propagates_none_when_object_resolves_to_none(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource(null),
            property: 'name',
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_err_when_accessing_property_on_string(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource('hello'),
            property: 'name',
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }

    #[Test]
    public function it_returns_err_when_accessing_property_on_integer(): void
    {
        $resolver = new MemberAccessResolver(new StaticResolver());
        $source = new MemberAccessSource(
            object: new StaticSource(42),
            property: 'name',
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }
}
