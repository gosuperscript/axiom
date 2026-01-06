<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Monads\Result\Result;

#[CoversClass(SymbolSource::class)]
#[CoversClass(SymbolRegistry::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
class SymbolResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value(): void
    {
        $registry = new SymbolRegistry([
            'A' => new StaticSource(2),
        ]);

        $source = new SymbolSource('A');
        $resolver = $source->resolver();

        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14159),
                'e' => new StaticSource(2.71828),
            ],
        ]);

        $source = new SymbolSource('pi', 'math');
        $resolver = $source->resolver();

        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(3.14159, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_for_nonexistent_symbol(): void
    {
        $registry = new SymbolRegistry([
            'A' => new StaticSource(2),
        ]);

        $source = new SymbolSource('B');
        $resolver = $source->resolver();

        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_none_for_nonexistent_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14159),
            ],
        ]);

        // Wrong namespace
        $source = new SymbolSource('pi', 'physics');
        $resolver = $source->resolver();

        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_distinguishes_between_namespaced_and_non_namespaced_symbols(): void
    {
        $registry = new SymbolRegistry([
            'value' => new StaticSource(1),
            'ns' => [
                'value' => new StaticSource(2),
            ],
        ]);

        // Resolve without namespace
        $source = new SymbolSource('value');
        $resolver = $source->resolver();
        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertEquals(1, $result->unwrap()->unwrap());

        // Resolve with namespace
        $source = new SymbolSource('value', 'ns');
        $resolver = $source->resolver();
        $result = $resolver(registry: $registry, resolver: new DelegatingResolver());
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }
}
