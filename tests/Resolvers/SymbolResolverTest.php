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
}
