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

            // Stream through records with memory-efficient processing
            // Instead of collecting all matches, we process them one at a time
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            
            // Initialize aggregate-specific state
            $aggregateState = $this->initializeAggregateState($source->aggregate);
            
            foreach ($records as $record) {
                if ($this->matchesExactFilters($record, $resolvedExactFilters) && $this->matchesRangeFilters($record, $resolvedRangeFilters)) {
                    // Process record immediately based on aggregate type
                    $aggregateState = $this->processRecordForAggregate($record, $aggregateState, $source->aggregate, $source->aggregateColumn);
                    
                    // Early exit optimization for 'first' aggregate
                    if ($source->aggregate === 'first' && $aggregateState['found']) {
                        break;
                    }
                }
            }

            // Finalize and extract result from aggregate state
            $result = $this->finalizeAggregate($aggregateState, $source->aggregate, $source->columns);
            
            if ($result === null) {
                return Ok(None());
            }

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
     * Initialize state for streaming aggregate processing
     * @param string $aggregate
     * @return array<string, mixed>
     */
    private function initializeAggregateState(string $aggregate): array
    {
        return match ($aggregate) {
            'first' => ['found' => false, 'row' => null],
            'last' => ['found' => false, 'row' => null],
            'count' => ['count' => 0],
            'sum' => ['sum' => 0, 'column' => null],
            'avg' => ['sum' => 0, 'count' => 0, 'column' => null],
            'min' => ['minRow' => null, 'minValue' => null, 'column' => null],
            'max' => ['maxRow' => null, 'maxValue' => null, 'column' => null],
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * Process a single matching record for the aggregate (memory efficient)
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string $aggregate
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processRecordForAggregate(array $record, array $state, string $aggregate, ?string $aggregateColumn): array
    {
        return match ($aggregate) {
            'first' => [
                'found' => true,
                'row' => $state['found'] ? $state['row'] : $record,
            ],
            'last' => [
                'found' => true,
                'row' => $record, // Always keep the latest
            ],
            'count' => [
                'count' => $state['count'] + 1,
            ],
            'sum' => $this->processSumRecord($record, $state, $aggregateColumn),
            'avg' => $this->processAvgRecord($record, $state, $aggregateColumn),
            'min' => $this->processMinRecord($record, $state, $aggregateColumn),
            'max' => $this->processMaxRecord($record, $state, $aggregateColumn),
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processSumRecord(array $record, array $state, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'sum' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && is_numeric($value)) {
            $state['sum'] += $value;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processAvgRecord(array $record, array $state, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'avg' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && is_numeric($value)) {
            $state['sum'] += $value;
            $state['count']++;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processMinRecord(array $record, array $state, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'min' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && ($state['minValue'] === null || $value < $state['minValue'])) {
            $state['minValue'] = $value;
            $state['minRow'] = $record;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processMaxRecord(array $record, array $state, ?string $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'max' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && ($state['maxValue'] === null || $value > $state['maxValue'])) {
            $state['maxValue'] = $value;
            $state['maxRow'] = $record;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * Finalize aggregate state and extract the result
     * @param array<string, mixed> $state
     * @param string $aggregate
     * @param array<string>|string $columns
     * @return mixed
     */
    private function finalizeAggregate(array $state, string $aggregate, array|string $columns): mixed
    {
        return match ($aggregate) {
            'first' => $state['found'] ? $this->extractColumns($state['row'], $columns) : null,
            'last' => $state['found'] ? $this->extractColumns($state['row'], $columns) : null,
            'count' => $state['count'] > 0 ? $state['count'] : null,
            'sum' => $state['sum'] !== 0 || $state['column'] !== null ? $state['sum'] : null,
            'avg' => $state['count'] > 0 ? $state['sum'] / $state['count'] : null,
            'min' => $state['minRow'] !== null ? $this->extractColumns($state['minRow'], $columns) : null,
            'max' => $state['maxRow'] !== null ? $this->extractColumns($state['maxRow'], $columns) : null,
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
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
