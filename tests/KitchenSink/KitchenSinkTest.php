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

    #[Test]
    public function lookup_with_symbol_filter(): void
    {
        // Create test CSV file
        $csvPath = sys_get_temp_dir() . '/test_lookup_' . uniqid() . '.csv';
        file_put_contents($csvPath, "product,category,price,stock\nLaptop,Electronics,999.99,50\nMouse,Electronics,29.99,200\nChair,Furniture,199.99,30\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Register symbol for the category we want to search
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'searchCategory' => new StaticSource('Electronics'),
        ]));

        // Lookup using symbol as filter value
        $source = new \Superscript\Schema\Sources\LookupSource(
            filePath: $csvPath,
            delimiter: ',',
            filterKeys: ['category' => new SymbolSource('searchCategory')],
            columns: 'product',
            strategy: 'first',
        );

        $result = $resolver->resolve($source);
        $this->assertEquals('Laptop', $result->unwrap()->unwrap());

        // Cleanup
        unlink($csvPath);
    }

    #[Test]
    public function lookup_with_type_casting(): void
    {
        // Create test CSV file with numeric strings
        $csvPath = sys_get_temp_dir() . '/test_lookup_' . uniqid() . '.csv';
        file_put_contents($csvPath, "id,name,price,quantity\n1,Widget,42.50,100\n2,Gadget,18.75,250\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            ValueDefinition::class => ValueResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Lookup price and cast to number
        $lookupSource = new \Superscript\Schema\Sources\LookupSource(
            filePath: $csvPath,
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Widget')],
            columns: 'price',
        );

        $source = new ValueDefinition(
            type: new NumberType(),
            source: $lookupSource,
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(42.50, $result->unwrap()->unwrap());
        $this->assertIsFloat($result->unwrap()->unwrap());

        // Cleanup
        unlink($csvPath);
    }

    #[Test]
    public function lookup_with_symbols_and_type_casting_in_expression(): void
    {
        // Create test CSV files
        $pricesPath = sys_get_temp_dir() . '/prices_' . uniqid() . '.csv';
        $discountsPath = sys_get_temp_dir() . '/discounts_' . uniqid() . '.csv';
        file_put_contents($pricesPath, "product,price\nLaptop,1000.00\nMouse,50.00\n");
        file_put_contents($discountsPath, "product,discount\nLaptop,0.10\nMouse,0.15\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            ValueDefinition::class => ValueResolver::class,
            InfixExpression::class => InfixResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Register product name as symbol
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'productName' => new StaticSource('Laptop'),
        ]));

        // Lookup price and cast to number
        $priceLookup = new ValueDefinition(
            type: new NumberType(),
            source: new \Superscript\Schema\Sources\LookupSource(
                filePath: $pricesPath,
                filterKeys: ['product' => new SymbolSource('productName')],
                columns: 'price',
            ),
        );

        // Lookup discount and cast to number
        $discountLookup = new ValueDefinition(
            type: new NumberType(),
            source: new \Superscript\Schema\Sources\LookupSource(
                filePath: $discountsPath,
                filterKeys: ['product' => new SymbolSource('productName')],
                columns: 'discount',
            ),
        );

        // Calculate final price: price * (1 - discount)
        $source = new InfixExpression(
            left: $priceLookup,
            operator: '*',
            right: new InfixExpression(
                left: new StaticSource(1),
                operator: '-',
                right: $discountLookup,
            ),
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(900.0, $result->unwrap()->unwrap()); // 1000 * (1 - 0.10) = 900

        // Cleanup
        unlink($pricesPath);
        unlink($discountsPath);
    }

    #[Test]
    public function nested_lookup_with_symbol_and_multiple_type_casts(): void
    {
        // Create test CSV files
        $usersPath = sys_get_temp_dir() . '/users_' . uniqid() . '.csv';
        $ordersPath = sys_get_temp_dir() . '/orders_' . uniqid() . '.csv';
        file_put_contents($usersPath, "user_id,name,city\n101,Alice,NYC\n102,Bob,LA\n");
        file_put_contents($ordersPath, "order_id,user_id,amount,quantity\n1,101,250.50,3\n2,102,180.00,2\n3,101,420.75,5\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            ValueDefinition::class => ValueResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Register user name as symbol
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'userName' => new StaticSource('Alice'),
        ]));

        // Step 1: Lookup user_id by name
        $userIdLookup = new \Superscript\Schema\Sources\LookupSource(
            filePath: $usersPath,
            filterKeys: ['name' => new SymbolSource('userName')],
            columns: 'user_id',
        );

        // Step 2: Use the user_id to find max order amount
        $maxAmountLookup = new \Superscript\Schema\Sources\LookupSource(
            filePath: $ordersPath,
            filterKeys: ['user_id' => $userIdLookup],
            columns: 'amount',
            strategy: 'max',
        );

        // Step 3: Cast the amount to a number
        $source = new ValueDefinition(
            type: new NumberType(),
            source: $maxAmountLookup,
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(420.75, $result->unwrap()->unwrap());
        $this->assertIsFloat($result->unwrap()->unwrap());

        // Cleanup
        unlink($usersPath);
        unlink($ordersPath);
    }

    #[Test]
    public function lookup_with_multiple_symbols_and_string_type_casting(): void
    {
        // Create test CSV file
        $csvPath = sys_get_temp_dir() . '/inventory_' . uniqid() . '.csv';
        file_put_contents($csvPath, "product,category,location,stock\nLaptop,Electronics,Warehouse A,50\nMouse,Electronics,Warehouse B,200\nKeyboard,Electronics,Warehouse A,150\n");

        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            ValueDefinition::class => ValueResolver::class,
            \Superscript\Schema\Sources\LookupSource::class => \Superscript\Schema\Resolvers\LookupResolver::class,
        ]);

        // Register multiple symbols
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'targetCategory' => new StaticSource('Electronics'),
            'targetLocation' => new StaticSource('Warehouse A'),
        ]));

        // Lookup using multiple symbol filters and cast result to string
        $lookupSource = new \Superscript\Schema\Sources\LookupSource(
            filePath: $csvPath,
            filterKeys: [
                'category' => new SymbolSource('targetCategory'),
                'location' => new SymbolSource('targetLocation'),
            ],
            columns: 'stock',
            strategy: 'min',
        );

        $source = new ValueDefinition(
            type: new \Superscript\Schema\Types\StringType(),
            source: $lookupSource,
        );

        $result = $resolver->resolve($source);
        $this->assertEquals('50', $result->unwrap()->unwrap());
        $this->assertIsString($result->unwrap()->unwrap());

        // Cleanup
        unlink($csvPath);
    }
}
