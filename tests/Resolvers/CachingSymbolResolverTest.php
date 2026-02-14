<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Resolvers\CachingSymbolResolver;
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

#[CoversClass(CachingSymbolResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(SymbolRegistry::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(StaticResolver::class)]
class CachingSymbolResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_a_value(): void
    {
        $resolver = new CachingSymbolResolver(new StaticResolver(), new SymbolRegistry([
            'A' => new StaticSource(2),
        ]));
        $source = new SymbolSource('A');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
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

        $resolver = new CachingSymbolResolver(
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

        $resolver = new CachingSymbolResolver(
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

        $resolver = new CachingSymbolResolver(
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
    public function it_returns_none_for_unknown_symbols(): void
    {
        $resolver = new CachingSymbolResolver(new StaticResolver(), new SymbolRegistry([]));

        $result = $resolver->resolve(new SymbolSource('unknown'));

        $this->assertTrue($result->unwrap()->isNone());
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

        $resolver = new CachingSymbolResolver(
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
        $resolver = new CachingSymbolResolver(
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
        $resolver = new CachingSymbolResolver(
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
        $resolver = new CachingSymbolResolver(
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
        $resolver = new CachingSymbolResolver(
            new StaticResolver(),
            new SymbolRegistry(['math' => ['pi' => new StaticSource(3.14)]]),
            $inspector,
        );

        $resolver->resolve(new SymbolSource('pi', 'math'));
        $resolver->resolve(new SymbolSource('pi', 'math'));

        $this->assertSame('math.pi', $inspector->annotations['label']);
        $this->assertSame('hit', $inspector->annotations['cache']);
    }

    #[Test]
    public function it_isolates_namespaced_and_global_symbols_with_same_name(): void
    {
        $resolver = new CachingSymbolResolver(new StaticResolver(), new SymbolRegistry([
            'value' => new StaticSource(10),
            'ns' => [
                'value' => new StaticSource(20),
            ],
        ]));

        $global = $resolver->resolve(new SymbolSource('value'));
        $namespaced = $resolver->resolve(new SymbolSource('value', 'ns'));

        $this->assertEquals(10, $global->unwrap()->unwrap());
        $this->assertEquals(20, $namespaced->unwrap()->unwrap());
    }
}
