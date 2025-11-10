<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Tests\Resolvers\Fixtures\Dependency;
use Superscript\Schema\Types\NumberType;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(ValueDefinition::class)]
#[UsesClass(NumberType::class)]
class DelegatingResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_by_delegating_to_another_resolver(): void
    {
        $resolver = new DelegatingResolver();

        $result = $resolver->resolve(new StaticSource('Hello world!'));
        $this->assertEquals('Hello world!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_override_source_resolution_with_resolve_using(): void
    {
        $resolver = new DelegatingResolver();
        $resolver->resolveUsing(StaticSource::class, static fn() => Ok(Some('Overridden')));

        $result = $resolver->resolve(new StaticSource('Original'));

        $this->assertEquals('Overridden', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_depending_on_other_resolvers(): void
    {
        $resolver = new DelegatingResolver();

        $result = $resolver->resolve(new ValueDefinition(new NumberType(), new StaticSource('42')));
        $this->assertEquals(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_with_dependencies(): void
    {
        $resolver = new DelegatingResolver();
        $resolver->instance(Dependency::class, new class implements Dependency {
            public string $value = 'default';
        });

        $source = new class implements Source {
            public function resolver(): Closure
            {
                return fn(Dependency $dependency) => Ok(Some($dependency->value));
            }
        };

        $this->assertEquals('default', $resolver->resolve($source)->unwrap()->unwrap());
    }
}
