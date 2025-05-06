<?php

namespace Superscript\Abacus\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Resolvers\DelegatingResolver;
use Superscript\Abacus\Resolvers\Resolver;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Resolvers\SymbolResolver;
use Superscript\Abacus\Source;
use Superscript\Abacus\Sources\StaticSource;
use Superscript\Abacus\Sources\SymbolSource;
use Superscript\Abacus\Sources\ValueDefinition;
use Superscript\Abacus\SymbolRegistry;
use Superscript\Abacus\Types\StringType;
use Superscript\Abacus\Resolvers\ValueResolver;
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
    public function it_can_resolve_a_value()
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
}
