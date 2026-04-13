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
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(SymbolResolver::class)]
#[CoversClass(SymbolSource::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
class SymbolResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value_from_definitions(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
        );

        $result = $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_namespaced_symbol_from_definitions(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions([
                'math' => [
                    'pi' => new StaticSource(3.14),
                    'e' => new StaticSource(2.71),
                ],
            ]),
        );

        $result = $resolver->resolve(new SymbolSource('pi', 'math'), $context);

        $this->assertEquals(3.14, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_for_nonexistent_namespaced_symbol(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions([
                'math' => ['pi' => new StaticSource(3.14)],
            ]),
        );

        $result = $resolver->resolve(new SymbolSource('pi', 'physics'), $context);

        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_distinguishes_between_namespaced_and_non_namespaced_symbols(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions([
                'value' => new StaticSource(10),
                'ns' => ['value' => new StaticSource(20)],
            ]),
        );

        $this->assertEquals(10, $resolver->resolve(new SymbolSource('value'), $context)->unwrap()->unwrap());
        $this->assertEquals(20, $resolver->resolve(new SymbolSource('value', 'ns'), $context)->unwrap()->unwrap());
    }

    #[Test]
    public function bindings_take_precedence_over_definitions(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            bindings: new Bindings(['A' => 99]),
            definitions: new Definitions(['A' => new StaticSource(1)]),
        );

        $this->assertEquals(99, $resolver->resolve(new SymbolSource('A'), $context)->unwrap()->unwrap());
    }

    #[Test]
    public function null_bindings_shadow_definitions(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            bindings: new Bindings(['A' => null]),
            definitions: new Definitions(['A' => new StaticSource('fallback')]),
        );

        $result = $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertTrue($result->unwrap()->isSome());
        $this->assertNull($result->unwrap()->unwrap());
    }

    #[Test]
    public function it_memoizes_resolved_definitions_within_a_context(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver($innerResolver);
        $context = new Context(definitions: new Definitions(['A' => new StaticSource(42)]));

        $result1 = $resolver->resolve(new SymbolSource('A'), $context);
        $result2 = $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertEquals(42, $result1->unwrap()->unwrap());
        $this->assertEquals(42, $result2->unwrap()->unwrap());
        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function memo_is_scoped_to_context_so_different_contexts_recompute(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver($innerResolver);
        $definitions = new Definitions(['A' => new StaticSource(42)]);

        $resolver->resolve(new SymbolSource('A'), new Context(definitions: $definitions));
        $resolver->resolve(new SymbolSource('A'), new Context(definitions: $definitions));

        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function it_memoizes_namespaced_symbols(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver($innerResolver);
        $context = new Context(definitions: new Definitions(['math' => ['pi' => new StaticSource(3.14)]]));

        $resolver->resolve(new SymbolSource('pi', 'math'), $context);
        $resolver->resolve(new SymbolSource('pi', 'math'), $context);

        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function it_memoizes_different_symbols_separately(): void
    {
        $callCount = 0;
        $innerResolver = $this->createStub(Resolver::class);
        $innerResolver->method('resolve')
            ->willReturnCallback(function (Source $source) use (&$callCount) {
                $callCount++;

                return Ok(Some($source->value));
            });

        $resolver = new SymbolResolver($innerResolver);
        $context = new Context(definitions: new Definitions([
            'A' => new StaticSource(1),
            'B' => new StaticSource(2),
        ]));

        $resolver->resolve(new SymbolSource('A'), $context);
        $resolver->resolve(new SymbolSource('B'), $context);
        $resolver->resolve(new SymbolSource('A'), $context);
        $resolver->resolve(new SymbolSource('B'), $context);

        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function it_returns_none_for_unknown_symbols(): void
    {
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context();

        $this->assertTrue($resolver->resolve(new SymbolSource('unknown'), $context)->unwrap()->isNone());
    }

    #[Test]
    public function it_annotates_memo_miss_on_first_resolve(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame('miss', $inspector->annotations['memo']);
    }

    #[Test]
    public function it_annotates_memo_hit_on_subsequent_resolves(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);
        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame('hit', $inspector->annotations['memo']);
    }

    #[Test]
    public function it_annotates_label_with_symbol_name(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['A' => new StaticSource(2)]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame('A', $inspector->annotations['label']);
    }

    #[Test]
    public function it_annotates_namespaced_label(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            definitions: new Definitions(['math' => ['pi' => new StaticSource(3.14)]]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('pi', 'math'), $context);

        $this->assertSame('math.pi', $inspector->annotations['label']);
    }

    #[Test]
    public function it_annotates_result_when_resolving_from_bindings(): void
    {
        $inspector = new SpyInspector();
        $resolver = new SymbolResolver(new StaticResolver());
        $context = new Context(
            bindings: new Bindings(['A' => 7]),
            inspector: $inspector,
        );

        $resolver->resolve(new SymbolSource('A'), $context);

        $this->assertSame(7, $inspector->annotations['result']);
    }
}
