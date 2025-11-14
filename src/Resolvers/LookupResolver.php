<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use League\Csv\Reader;
use RuntimeException;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\ExactFilter;
use Superscript\Schema\Sources\LookupSource;
use Superscript\Schema\Sources\RangeFilter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
use Throwable;

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
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        try {
            // Read and parse the CSV/TSV file
            $reader = Reader::createFromPath($source->filePath);
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
                
                if ($filter instanceof ExactFilter) {
                    $resolvedExactFilters[$filter->column] = $resolvedValue;
                } elseif ($filter instanceof RangeFilter) {
                    $resolvedRangeFilters[] = [
                        'value' => $resolvedValue,
                        'minColumn' => $filter->minColumn,
                        'maxColumn' => $filter->maxColumn,
                    ];
                }
            }

            // Stream through records and find matching rows
            $matchingRows = [];
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            
            foreach ($records as $record) {
                if ($this->matchesExactFilters($record, $resolvedExactFilters) && $this->matchesRangeFilters($record, $resolvedRangeFilters)) {
                    $matchingRows[] = $record;
                }
            }

            // No matches found
            if (empty($matchingRows)) {
                return Ok(None());
            }

            // Apply aggregate function
            $result = $this->applyAggregate($matchingRows, $source->aggregate, $source->columns, $source->aggregateColumn);

            return Ok(Some($result));
        } catch (Throwable $e) {
            return new Err($e);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $filters
     */
    private function matchesExactFilters(array $record, array $filters): bool
    {
        foreach ($filters as $column => $value) {
            if (!isset($record[$column]) || $record[$column] !== (string) $value) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<array{value: mixed, minColumn: string, maxColumn: string}> $rangeFilters
     */
    private function matchesRangeFilters(array $record, array $rangeFilters): bool
    {
        foreach ($rangeFilters as $rangeConfig) {
            $value = $rangeConfig['value'];
            $minColumn = $rangeConfig['minColumn'];
            $maxColumn = $rangeConfig['maxColumn'];
            
            if (!isset($record[$minColumn]) || !isset($record[$maxColumn])) {
                return false;
            }
            
            $minValue = $record[$minColumn];
            $maxValue = $record[$maxColumn];
            
            // Check if value falls within the range [min, max]
            // Using numeric comparison if values are numeric
            if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
                if ($value < $minValue || $value > $maxValue) {
                    return false;
                }
            } else {
                // String comparison fallback
                if ($value < $minValue || $value > $maxValue) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string $aggregate
     * @param array<string>|string $columns
     * @param string|null $aggregateColumn
     * @return mixed
     */
    private function applyAggregate(array $rows, string $aggregate, array|string $columns, ?string $aggregateColumn): mixed
    {
        return match ($aggregate) {
            'first' => $this->extractColumns($rows[0], $columns),
            'last' => $this->extractColumns($rows[count($rows) - 1], $columns),
            'min' => $this->extractColumns($this->findMinRow($rows, $aggregateColumn), $columns),
            'max' => $this->extractColumns($this->findMaxRow($rows, $aggregateColumn), $columns),
            'count' => count($rows),
            'sum' => $this->calculateSum($rows, $aggregateColumn),
            'avg' => $this->calculateAvg($rows, $aggregateColumn),
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function findMinRow(array $rows, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'min' aggregate");
        }
        
        $minRow = $rows[0];
        $minValue = $rows[0][$aggregateColumn] ?? null;
        
        foreach ($rows as $row) {
            $value = $row[$aggregateColumn] ?? null;
            if ($value !== null && ($minValue === null || $value < $minValue)) {
                $minValue = $value;
                $minRow = $row;
            }
        }
        
        return $minRow;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function findMaxRow(array $rows, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'max' aggregate");
        }
        
        $maxRow = $rows[0];
        $maxValue = $rows[0][$aggregateColumn] ?? null;
        
        foreach ($rows as $row) {
            $value = $row[$aggregateColumn] ?? null;
            if ($value !== null && ($maxValue === null || $value > $maxValue)) {
                $maxValue = $value;
                $maxRow = $row;
            }
        }
        
        return $maxRow;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string|null $aggregateColumn
     * @return float|int
     */
    private function calculateSum(array $rows, ?string $aggregateColumn): float|int
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'sum' aggregate");
        }

        $sum = 0;
        foreach ($rows as $row) {
            $value = $row[$aggregateColumn] ?? null;
            if ($value !== null && is_numeric($value)) {
                $sum += $value;
            }
        }

        return $sum;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string|null $aggregateColumn
     * @return float
     */
    private function calculateAvg(array $rows, ?string $aggregateColumn): float
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'avg' aggregate");
        }

        if (empty($rows)) {
            return 0.0;
        }

        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$aggregateColumn] ?? null;
            if ($value !== null && is_numeric($value)) {
                $sum += $value;
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string>|string $columns
     * @return mixed
     */
    private function extractColumns(array $row, array|string $columns): mixed
    {
        if (empty($columns)) {
            return $row;
        }
        
        if (is_string($columns)) {
            return $row[$columns] ?? null;
        }
        
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = $row[$column] ?? null;
        }
        
        return $result;
    }
}
