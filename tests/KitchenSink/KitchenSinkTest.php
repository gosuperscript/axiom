<?php

namespace Superscript\Abacus\Tests\KitchenSink;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Resolvers\DelegatingResolver;
use Superscript\Abacus\Resolvers\InfixResolver;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Resolvers\SymbolResolver;
use Superscript\Abacus\Resolvers\ValueResolver;
use Superscript\Abacus\Sources\InfixExpression;
use Superscript\Abacus\Sources\StaticSource;
use Superscript\Abacus\Sources\SymbolSource;
use Superscript\Abacus\Sources\ValueDefinition;
use Superscript\Abacus\SymbolRegistry;
use Superscript\Abacus\Types\NumberType;

#[CoversNothing]
class KitchenSinkTest extends TestCase
{
    #[Test]
    public function something_complex(): void
    {
        $resolver = new DelegatingResolver([
            StaticResolver::class,
            InfixResolver::class,
            ValueResolver::class,
            SymbolResolver::class,
        ]);

        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'A' => new StaticSource(2),
        ]));

        $source = new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new InfixExpression(
                left: new SymbolSource('A'),
                operator: '*',
                right: new ValueDefinition(
                    type: new NumberType(),
                    source: new StaticSource('3')
                )
            ),
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(7, $result->unwrap()->unwrap());
    }
}