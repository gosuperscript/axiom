<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(SymbolResolver::class)]
#[CoversClass(SymbolSource::class)]
#[CoversClass(SymbolRegistry::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
class SymbolResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'A' => new StaticSource(2),
        ]));
        $source = new SymbolSource('A');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_namespaced_symbol(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
                'e' => new StaticSource(2.71),
            ],
        ]));

        $source = new SymbolSource('pi', 'math');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(3.14, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_for_nonexistent_namespaced_symbol(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
            ],
        ]));

        // Wrong namespace
        $source = new SymbolSource('pi', 'physics');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_distinguishes_between_namespaced_and_non_namespaced_symbols(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'value' => new StaticSource(10),
            'ns' => [
                'value' => new StaticSource(20),
            ],
        ]));

        // Resolve without namespace
        $source = new SymbolSource('value');
        $result = $resolver->resolve($source);
        $this->assertEquals(10, $result->unwrap()->unwrap());

        // Resolve with namespace
        $source = new SymbolSource('value', 'ns');
        $result = $resolver->resolve($source);
        $this->assertEquals(20, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_preserves_backward_compatibility_with_null_namespace(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'A' => new StaticSource(42),
        ]));

        // SymbolSource with null namespace (default)
        $source = new SymbolSource('A', null);
        $result = $resolver->resolve($source);
        $this->assertEquals(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_caches_resolved_values(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver(
            $innerResolver,
            new SymbolRegistry(['A' => new StaticSource(42)]),
        );

        $result1 = $resolver->resolve(new SymbolSource('A'));
        $result2 = $resolver->resolve(new SymbolSource('A'));

        $this->assertEquals(42, $result1->unwrap()->unwrap());
        $this->assertEquals(42, $result2->unwrap()->unwrap());
        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function it_caches_namespaced_symbols(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver(
            $innerResolver,
            new SymbolRegistry(['math' => ['pi' => new StaticSource(3.14)]]),
        );

        $result1 = $resolver->resolve(new SymbolSource('pi', 'math'));
        $result2 = $resolver->resolve(new SymbolSource('pi', 'math'));

        $this->assertEquals(3.14, $result1->unwrap()->unwrap());
        $this->assertEquals(3.14, $result2->unwrap()->unwrap());
        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function it_caches_different_symbols_separately(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver(
            $innerResolver,
            new SymbolRegistry([
                'A' => new StaticSource(1),
                'B' => new StaticSource(2),
            ]),
        );

        $resultA = $resolver->resolve(new SymbolSource('A'));
        $resultB = $resolver->resolve(new SymbolSource('B'));
        $resultA2 = $resolver->resolve(new SymbolSource('A'));
        $resultB2 = $resolver->resolve(new SymbolSource('B'));

        $this->assertEquals(1, $resultA->unwrap()->unwrap());
        $this->assertEquals(2, $resultB->unwrap()->unwrap());
        $this->assertEquals(1, $resultA2->unwrap()->unwrap());
        $this->assertEquals(2, $resultB2->unwrap()->unwrap());
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function it_caches_none_results_for_unknown_symbols(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;

                return Ok(Some(42));
            });

        $resolver = new SymbolResolver(
            $innerResolver,
            new SymbolRegistry([]),
        );

        $result1 = $resolver->resolve(new SymbolSource('unknown'));
        $result2 = $resolver->resolve(new SymbolSource('unknown'));

        $this->assertTrue($result1->unwrap()->isNone());
        $this->assertTrue($result2->unwrap()->isNone());
        $this->assertSame(0, $callCount);
    }

    #[Test]
    public function it_annotates_cache_miss_on_first_resolve(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['A' => new StaticSource(2)]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('A'));

        $this->assertSame('miss', $inspector->annotations['cache']);
    }

    #[Test]
    public function it_annotates_cache_hit_on_subsequent_resolves(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['A' => new StaticSource(2)]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('A'));
        $resolver->resolve(new SymbolSource('A'));

        $this->assertSame('hit', $inspector->annotations['cache']);
    }

    #[Test]
    public function it_annotates_label_on_cache_hit(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['A' => new StaticSource(2)]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('A'));
        $resolver->resolve(new SymbolSource('A'));

        $this->assertSame('A', $inspector->annotations['label']);
    }

    #[Test]
    public function it_annotates_namespaced_label_on_cache_hit(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['math' => ['pi' => new StaticSource(3.14)]]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('pi', 'math'));
        $resolver->resolve(new SymbolSource('pi', 'math'));

        $this->assertSame('math.pi', $inspector->annotations['label']);
        $this->assertSame('hit', $inspector->annotations['cache']);
    }
}
