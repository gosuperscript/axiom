<?php

declare(strict_types=1);

namespace Superscript\Lookups\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Lookups\CsvRecord;
use Superscript\Lookups\LookupResolver;
use Superscript\Lookups\LookupSource;
use Superscript\Lookups\Support\Aggregates;
use Superscript\Lookups\Support\Aggregates\AggregateEnum;
use Superscript\Lookups\Support\Filters\ValueFilter;
use Superscript\Lookups\Support\Filters\RangeFilter;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Sources\StaticSource;

#[CoversClass(LookupResolver::class)]
#[CoversClass(LookupSource::class)]
#[CoversClass(ValueFilter::class)]
#[CoversClass(RangeFilter::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(AggregateEnum::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\Last::class)]
#[UsesClass(Aggregates\Count::class)]
#[UsesClass(Aggregates\Sum::class)]
#[UsesClass(Aggregates\Average::class)]
#[UsesClass(Aggregates\Min::class)]
#[UsesClass(Aggregates\Max::class)]
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
        return __DIR__.'/Fixtures/'.$filename;
    }

    #[Test]
    public function it_can_lookup_single_column_from_csv_with_single_filter(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(
                value: new StaticSource('Alice'),
                column: 'name',
            )],
            columns: ['age'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_compare_lookup_values_using_filter_operator(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(
                value: new StaticSource('Charlie'),
                column: 'name',
                operator: '!='
            )],
            columns: ['age'],
            aggregate: AggregateEnum::MAX,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(32, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_multiple_columns_from_csv(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [
                new ValueFilter(
                    value: new StaticSource('Bob'),
                    column: 'name'
                ),
            ],
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
            filters: [
                new ValueFilter(
                    value: new StaticSource('Laptop'),
                    column: 'product',
                ),
            ],
            columns: ['price'],
            delimiter: "\t",
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
            filters: [
                new ValueFilter(new StaticSource('NYC'), 'city'),
                new ValueFilter(new StaticSource('30'), 'age'),
            ],
            columns: ['name'],
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
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['name'],
            aggregate: AggregateEnum::FIRST,
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
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['name'],
            aggregate: AggregateEnum::LAST,
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
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['salary'],
            aggregate: AggregateEnum::MIN,
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
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['salary'],
            aggregate: AggregateEnum::MAX,
            aggregateColumn: 'salary',
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
            filters: [new ValueFilter(new StaticSource('NonExistent'), 'city')],
            columns: ['age'],
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
            filters: [new ValueFilter(new StaticSource('Alice'), 'name')],
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
            filters: [new ValueFilter(new StaticSource('2'), 0)],
            columns: [1],
            hasHeader: false,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Bob', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_resolves_filter_key_values_dynamically(): void
    {
        $cityLookup = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('Bob'), 'name')],
            columns: ['city'],
        );

        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter($cityLookup, 'city')],
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
            filters: [],
            columns: ['name'],
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
            filters: [new ValueFilter(new StaticSource('Electronics'), 'category')],
            columns: ['product', 'price'],
            aggregate: AggregateEnum::MIN,
            aggregateColumn: 'price',
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
            filters: [new ValueFilter(new StaticSource('Electronics'), 'category')],
            columns: ['product', 'price'],
            aggregate: AggregateEnum::MAX,
            aggregateColumn: 'price',
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
        fputcsv($handle, ['id', 'value'], escape: '\\');

        for ($i = 1; $i <= 1000; $i++) {
            fputcsv($handle, [$i, "value_{$i}"], escape: '\\');
        }
        fclose($handle);

        $source = new LookupSource(
            filePath: $largeCsvPath,
            filters: [new ValueFilter(new StaticSource('500'), 'id')],
            columns: ['value'],
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
            filters: [new ValueFilter(new StaticSource('NonExistent'), 'name')],
            columns: ['city'],
        );

        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter($noneSource, 'city')],
            columns: ['name'],
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
            filters: [],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_error_for_min_aggregate_without_aggregate_column(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['salary', 'city'],
            aggregate: AggregateEnum::MIN,
        );
        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_uses_column_for_aggregate_when_single_column_is_selected(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [],
            columns: ['salary'],
            aggregate: AggregateEnum::MIN,
        );
        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('65000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_error_for_max_aggregate_without_aggregate_column(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            columns: ['salary', 'city'],
            aggregate: AggregateEnum::MAX,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_count_of_matching_rows(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            aggregate: AggregateEnum::COUNT,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->unwrap()->unwrap()); // Alice and Charlie are in NYC
    }

    #[Test]
    public function it_calculates_sum_of_column_values(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            aggregate: AggregateEnum::SUM,
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(160000, $result->unwrap()->unwrap()); // 75000 + 85000
    }

    #[Test]
    public function it_calculates_avg_of_column_values(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            aggregate: AggregateEnum::AVG,
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(80000.0, $result->unwrap()->unwrap()); // (75000 + 85000) / 2
    }

    #[Test]
    public function it_throws_error_for_sum_without_aggregate_column(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            aggregate: AggregateEnum::SUM,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_throws_error_for_avg_without_aggregate_column(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NYC'), 'city')],
            aggregate: AggregateEnum::AVG,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_supports_range_based_lookup_for_banding(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('premium_bands.csv'),
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('150000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 150k falls in 100k-200k band
    }

    #[Test]
    public function it_supports_range_lookup_for_lower_band(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('premium_bands.csv'),
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('50000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // 50k falls in 0-100k band
    }

    #[Test]
    public function it_supports_range_lookup_for_upper_band(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('premium_bands.csv'),
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('500000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('25', $result->unwrap()->unwrap()); // 500k falls in 300k+ band
    }

    #[Test]
    public function it_supports_range_lookup_at_band_boundary(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('premium_bands.csv'),
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('100000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 100k falls in 100k-200k band (inclusive)
    }

    #[Test]
    public function it_combines_range_lookup_with_exact_filters(): void
    {
        // Create a CSV with regions and banding
        $csvPath = $this->getFixturePath('regional_bands.csv');
        file_put_contents($csvPath, "region,min_value,max_value,rate\nNorth,0,100,5\nNorth,100,200,10\nSouth,0,100,7\nSouth,100,200,12\n");

        $source = new LookupSource(
            filePath: $csvPath,
            filters: [
                new ValueFilter(new StaticSource('North'), 'region'),
                new RangeFilter('min_value', 'max_value', new StaticSource('150')),
            ],
            columns: ['rate'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // North region, 150 in 100-200 band

        // Cleanup
        unlink($csvPath);
    }

    #[Test]
    public function it_returns_none_for_avg_when_count_is_zero(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NonExistentPerson'), 'name')],
            columns: ['age'],
            aggregate: AggregateEnum::AVG,
            aggregateColumn: 'age',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_none_for_sum_when_no_matches(): void
    {
        $source = new LookupSource(
            filePath: $this->getFixturePath('users.csv'),
            filters: [new ValueFilter(new StaticSource('NonExistentPerson'), 'name')],
            columns: ['age'],
            aggregate: AggregateEnum::SUM,
            aggregateColumn: 'age',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_zero_for_sum_when_all_values_are_zero(): void
    {
        // Create a CSV file with zero values
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        if ($tempFile === false) {
            $this->fail('Failed to create temp file');
        }

        $handle = fopen($tempFile, 'w');
        if ($handle === false) {
            $this->fail('Failed to open temp file');
        }

        fputcsv($handle, ['name', 'value'], escape: '\\');
        fputcsv($handle, ['Item1', '0'], escape: '\\');
        fputcsv($handle, ['Item2', '0'], escape: '\\');
        fclose($handle);

        $source = new LookupSource(
            filePath: $tempFile,
            filters: [new ValueFilter(new StaticSource('Item1'), 'name')],
            columns: ['value'],
            aggregate: AggregateEnum::SUM,
            aggregateColumn: 'value',
        );

        $result = $this->resolver->resolve($source);

        unlink($tempFile);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isSome());
        $this->assertEquals(0, $result->unwrap()->unwrap());
    }
}
