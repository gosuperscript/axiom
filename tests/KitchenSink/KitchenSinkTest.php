<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\KitchenSink;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\InfixResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\SymbolResolver;
use Superscript\Schema\Resolvers\ValueResolver;
use Superscript\Schema\Sources\InfixExpression;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\SymbolRegistry;
use Superscript\Schema\Types\NumberType;

#[CoversNothing]
class KitchenSinkTest extends TestCase
{
    #[Test]
    public function something_complex(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            ValueDefinition::class => ValueResolver::class,
            SymbolSource::class => SymbolResolver::class,
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
                    source: new StaticSource('3'),
                ),
            ),
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(7, $result->unwrap()->unwrap());
    }

    #[Test]
    public function transforming_a_value(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            ValueDefinition::class => ValueResolver::class,
        ]);

        $source = new ValueDefinition(
            type: new NumberType(),
            source: new StaticSource('5'),
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(5, $result->unwrap()->unwrap());
    }

    #[Test]
    public function lookup_with_dynamic_filter(): void
    {
        // Create test CSV file
        $csvPath = sys_get_temp_dir() . '/test_lookup_' . uniqid() . '.csv';
        file_put_contents($csvPath, "id,name,price\n1,Apple,1.50\n2,Banana,0.75\n3,Orange,2.00\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Lookup the price of Banana
        $source = new \Superscript\Schema\Sources\LookupSource(
            filePath: $csvPath,
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Banana')],
            columns: 'price',
        );

        $result = $resolver->resolve($source);
        $this->assertEquals('0.75', $result->unwrap()->unwrap());

        // Cleanup
        unlink($csvPath);
    }
}
