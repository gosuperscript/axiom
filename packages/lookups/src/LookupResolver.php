<?php

declare(strict_types=1);

namespace Superscript\Lookups;

use League\Csv\Reader;
use Superscript\Lookups\Support\Aggregates\Aggregate;
use Superscript\Lookups\Support\Aggregates\AggregateEnum;
use Superscript\Lookups\Support\Aggregates\Average;
use Superscript\Lookups\Support\Aggregates\Count;
use Superscript\Lookups\Support\Aggregates\First;
use Superscript\Lookups\Support\Aggregates\Last;
use Superscript\Lookups\Support\Aggregates\Max;
use Superscript\Lookups\Support\Aggregates\Min;
use Superscript\Lookups\Support\Aggregates\Sum;
use Superscript\Lookups\Support\Filters\RangeFilter;
use Superscript\Lookups\Support\Filters\ValueFilter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Throwable;

use function Psl\Iter\all;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<LookupSource>
 */
final readonly class LookupResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    /**
     * @param  LookupSource  $source
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        try {
            // Read and parse the CSV/TSV file
            $reader = Reader::from($source->filePath);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Resolve all filters
            $resolvedExactFilters = [];
            $resolvedRangeFilters = [];

            foreach ($source->filters as $filter) {
                $result = $this->resolver->resolve($filter->value);

                if ($result->isErr()) {
                    return $result;
                }

                $option = $result->unwrap();
                if ($option->isNone()) {
                    return Ok(None());
                }

                $resolvedValue = $option->unwrap();

                if ($filter instanceof ValueFilter) {
                    $resolvedExactFilters[$filter->column] = $resolvedValue;
                } elseif ($filter instanceof RangeFilter) {
                    $resolvedRangeFilters[] = [
                        'value' => $resolvedValue,
                        'minColumn' => $filter->minColumn,
                        'maxColumn' => $filter->maxColumn,
                    ];
                }
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Initialize aggregate-specific state using value objects
            $aggregateState = $this->createAggregateState($source->aggregate);

            foreach ($records as $record) {
                /** @var array<string, mixed> $record */
                $csvRecord = CsvRecord::from($record);

                if ($this->matchesExactFilters($csvRecord, $resolvedExactFilters) && $this->matchesRangeFilters($csvRecord, $resolvedRangeFilters)) {
                    // Process record immediately with immutable value object
                    $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);

                    // Early exit optimization for 'first' aggregate
                    if ($aggregateState->canEarlyExit()) {
                        break;
                    }
                }
            }

            // Finalize and extract result from aggregate state
            $result = $aggregateState->finalize($source->columns);

            if ($result === null) {
                return Ok(None());
            }

            return Ok(Some($result));
        } catch (Throwable $e) {
            return new Err($e);
        }
    }

    /**
     * Create appropriate aggregate state value object
     */
    private function createAggregateState(AggregateEnum $aggregate): Aggregate
    {
        return match ($aggregate) {
            AggregateEnum::FIRST => First::initial(),
            AggregateEnum::LAST => Last::initial(),
            AggregateEnum::COUNT => Count::initial(),
            AggregateEnum::SUM => Sum::initial(),
            AggregateEnum::AVG => Average::initial(),
            AggregateEnum::MIN => Min::initial(),
            AggregateEnum::MAX => Max::initial(),
        };
    }

    /**
     * @param  array<string|int, mixed>  $filters
     */
    private function matchesExactFilters(CsvRecord $record, array $filters): bool
    {
        foreach ($filters as $column => $value) {
            $recordValue = $record->getString($column);
            $compareValue = is_scalar($value) ? (string) $value : null;

            if ($recordValue === null || $compareValue === null || $recordValue !== $compareValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array{value: mixed, minColumn: string|int, maxColumn: string|int}>  $rangeFilters
     */
    private function matchesRangeFilters(CsvRecord $record, array $rangeFilters): bool
    {
        return all(
            $rangeFilters,
            function (array $rangeConfig) use ($record): bool {
                $value = $rangeConfig['value'];
                $minColumn = $rangeConfig['minColumn'];
                $maxColumn = $rangeConfig['maxColumn'];

                if (! $record->has($minColumn) || ! $record->has($maxColumn)) {
                    return false;
                }

                $minValue = $record->get($minColumn);
                $maxValue = $record->get($maxColumn);

                // Check if value falls within the range [min, max)
                // Using min <= value < max for banding scenarios
                // This prevents overlap at boundaries (e.g., 100k matches 100k-200k, not 0-100k)
                if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
                    return $value >= $minValue && $value < $maxValue;
                }

                // String comparison fallback
                return $value >= $minValue && $value < $maxValue;
            },
        );
    }
}
