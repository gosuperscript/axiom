<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\LookupResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Sources\LookupSource;
use Superscript\Schema\Sources\StaticSource;

#[CoversClass(LookupResolver::class)]
#[CoversClass(LookupSource::class)]
class LookupResolverTest extends TestCase
{
    private DelegatingResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            LookupSource::class => LookupResolver::class,
        ]);
    }

    private function getFixturePath(string $filename): string
    {
        return __DIR__ . '/Fixtures/Lookup/' . $filename;
    }

    #[Test]
    public function it_can_lookup_single_column_from_csv_with_single_filter(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Alice')],
            columns: 'age',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_multiple_columns_from_csv(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Bob')],
            columns: ['name', 'age', 'city'],
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $expected = [
            'name' => 'Bob',
            'age' => '25',
            'city' => 'LA',
        ];
        $this->assertEquals($expected, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_from_tsv_file(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('products.tsv'),
            delimiter: "\t",
            filterKeys: ['product' => new StaticSource('Laptop')],
            columns: 'price',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('999.99', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_filter_with_multiple_keys(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: [
                'city' => new StaticSource('NYC'),
                'age' => new StaticSource('30'),
            ],
            columns: 'name',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_first_match_by_default(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => new StaticSource('NYC')],
            columns: 'name',
            strategy: 'first',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_last_match_with_last_strategy(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => new StaticSource('NYC')],
            columns: 'name',
            strategy: 'last',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('Charlie', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_min_match_with_min_strategy(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => new StaticSource('NYC')],
            columns: 'salary',
            strategy: 'min',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('75000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_max_match_with_max_strategy(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => new StaticSource('NYC')],
            columns: 'salary',
            strategy: 'max',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('85000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_when_no_match_found(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('NonExistent')],
            columns: 'age',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_all_columns_when_columns_is_empty(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Alice')],
            columns: [],
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $row = $result->unwrap()->unwrap();
        $this->assertIsArray($row);
        $this->assertEquals('Alice', $row['name']);
        $this->assertEquals('30', $row['age']);
        $this->assertEquals('NYC', $row['city']);
    }

    #[Test]
    public function it_can_work_with_file_without_header(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('no_header.csv'),
            delimiter: ',',
            filterKeys: [0 => new StaticSource('2')],
            columns: 1,
            hasHeader: false,
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('Bob', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_resolves_filter_key_values_dynamically(): void
    {
        // Using a nested LookupSource as a filter value
        $cityLookup = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Bob')],
            columns: 'city',
        );

        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => $cityLookup],
            columns: ['name', 'age'],
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $matches = $result->unwrap()->unwrap();
        $this->assertIsArray($matches);
        // Should find Bob and Eve (both in LA)
        $this->assertContains($matches['name'], ['Bob', 'Eve']);
    }

    #[Test]
    public function it_returns_error_for_non_existent_file(): void
    {
        $source = new LookupSource(
            filePath: '/non/existent/file.csv',
            delimiter: ',',
            filterKeys: [],
            columns: 'name',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_handles_min_strategy_with_multiple_columns(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('products.tsv'),
            delimiter: "\t",
            filterKeys: ['category' => new StaticSource('Electronics')],
            columns: ['product', 'price'],
            strategy: 'min',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $data = $result->unwrap()->unwrap();
        $this->assertEquals('Mouse', $data['product']);
        $this->assertEquals('29.99', $data['price']);
    }

    #[Test]
    public function it_handles_max_strategy_with_multiple_columns(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('products.tsv'),
            delimiter: "\t",
            filterKeys: ['category' => new StaticSource('Electronics')],
            columns: ['product', 'price'],
            strategy: 'max',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $data = $result->unwrap()->unwrap();
        $this->assertEquals('Laptop', $data['product']);
        $this->assertEquals('999.99', $data['price']);
    }

    #[Test]
    public function it_supports_streaming_large_files(): void
    {
        // Create a large CSV file for testing streaming
        $largeCsvPath = $this->getFixturePath('large_test.csv');
        $handle = fopen($largeCsvPath, 'w');
        fputcsv($handle, ['id', 'value']);
        
        for ($i = 1; $i <= 1000; $i++) {
            fputcsv($handle, [$i, "value_{$i}"]);
        }
        fclose($handle);

        $source = new LookupSource(
            filePath: $largeCsvPath,
            delimiter: ',',
            filterKeys: ['id' => new StaticSource('500')],
            columns: 'value',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertEquals('value_500', $result->unwrap()->unwrap());

        // Cleanup
        unlink($largeCsvPath);
    }

    #[Test]
    public function it_returns_none_when_filter_source_resolves_to_none(): void
    {
        $noneSource = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('NonExistent')],
            columns: 'city',
        );

        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['city' => $noneSource],
            columns: 'name',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_handles_empty_filter_keys(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: [],
            columns: 'name',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isOk());
        // Should return first row when no filters
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_error_for_unknown_strategy(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            delimiter: ',',
            filterKeys: ['name' => new StaticSource('Alice')],
            columns: 'age',
            strategy: 'invalid_strategy',
        );

        $result = $this->resolver->resolve($source);
        
        $this->assertTrue($result->isErr());
    }
}
