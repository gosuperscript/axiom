<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\SymbolResolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Monads\Result\Result;

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
        $this->assertTrue($resolver::supports($source));
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_only_symbol_sources(): void
    {
        $this->assertTrue(SymbolResolver::supports(new SymbolSource('A')));
        $this->assertFalse(SymbolResolver::supports(new class implements Source {}));
    }
}
