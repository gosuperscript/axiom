<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\CoerceResolver;
use Superscript\Axiom\Sources\CoerceSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\Dependency;
use Superscript\Axiom\Tests\Resolvers\Fixtures\ResolverWithDependency;
use Superscript\Axiom\Types\NumberType;

#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(CoerceResolver::class)]
#[UsesClass(CoerceSource::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
class DelegatingResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_by_delegating_to_another_resolver(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $result = $resolver->resolve(new StaticSource('Hello world!'), new Context());
        $this->assertEquals('Hello world!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_depending_on_other_resolvers(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            CoerceSource::class => CoerceResolver::class,
        ]);

        $result = $resolver->resolve(new CoerceSource(new StaticSource('42'), new NumberType()), new Context());
        $this->assertEquals(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_with_dependencies(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => ResolverWithDependency::class,
        ]);

        $resolver->instance(Dependency::class, new Dependency('hello'));

        $this->assertEquals('hello', $resolver->resolve(new StaticSource(42), new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_retrieve_a_registered_instance_via_get(): void
    {
        $resolver = new DelegatingResolver([]);
        $dependency = new Dependency('hello');

        $resolver->instance(Dependency::class, $dependency);

        $this->assertSame($dependency, $resolver->get(Dependency::class));
    }

    #[Test]
    public function it_can_check_if_an_instance_is_registered_via_has(): void
    {
        $resolver = new DelegatingResolver([]);

        $this->assertFalse($resolver->has(Dependency::class));

        $resolver->instance(Dependency::class, new Dependency('hello'));

        $this->assertTrue($resolver->has(Dependency::class));
    }

    #[Test]
    public function it_throws_an_exception_if_no_resolver_can_handle_the_source(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No resolver found for source of type ' . StaticSource::class);

        $resolver = new DelegatingResolver([]);
        $resolver->resolve(new StaticSource('Hello world!'), new Context());
    }
}
