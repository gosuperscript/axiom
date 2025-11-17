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
                /** @var array<string, mixed> $record */
                if ($this->matchesExactFilters($record, $resolvedExactFilters) && $this->matchesRangeFilters($record, $resolvedRangeFilters)) {
                    // Process record immediately based on aggregate type
                    $aggregateState = $this->processRecordForAggregate($record, $aggregateState, $source->aggregate, $source->aggregateColumn);
                    
                    // Early exit optimization for 'first' aggregate
                    if ($source->aggregate === 'first' && isset($aggregateState['found']) && $aggregateState['found']) {
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
     * @param array<string|int, mixed> $filters
     */
    private function matchesExactFilters(array $record, array $filters): bool
    {
        foreach ($filters as $column => $value) {
            $recordValue = $record[$column] ?? null;
            $compareValue = is_scalar($value) ? (string) $value : null;
            
            if ($recordValue === null || $compareValue === null || $recordValue !== $compareValue) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<array{value: mixed, minColumn: string|int, maxColumn: string|int}> $rangeFilters
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
            
            // Check if value falls within the range [min, max)
            // Using min <= value < max for banding scenarios
            // This prevents overlap at boundaries (e.g., 100k matches 100k-200k, not 0-100k)
            if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
                if ($value < $minValue || $value >= $maxValue) {
                    return false;
                }
            } else {
                // String comparison fallback
                if ($value < $minValue || $value >= $maxValue) {
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
     * @param string|int|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processRecordForAggregate(array $record, array $state, string $aggregate, string|int|null $aggregateColumn): array
    {
        return match ($aggregate) {
            'first' => [
                'found' => true,
                'row' => isset($state['found']) && $state['found'] ? $state['row'] : $record,
            ],
            'last' => [
                'found' => true,
                'row' => $record, // Always keep the latest
            ],
            'count' => [
                'count' => (isset($state['count']) && is_int($state['count']) ? $state['count'] : 0) + 1,
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
     * @param string|int|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processSumRecord(array $record, array $state, string|int|null $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'sum' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && is_numeric($value)) {
            $currentSum = isset($state['sum']) && is_numeric($state['sum']) ? $state['sum'] : 0;
            $state['sum'] = (float) $currentSum + (float) $value;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|int|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processAvgRecord(array $record, array $state, string|int|null $aggregateColumn): array
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'avg' aggregate");
        }

        $value = $record[$aggregateColumn] ?? null;
        if ($value !== null && is_numeric($value)) {
            $currentSum = isset($state['sum']) && is_numeric($state['sum']) ? $state['sum'] : 0;
            $currentCount = isset($state['count']) && is_int($state['count']) ? $state['count'] : 0;
            $state['sum'] = (float) $currentSum + (float) $value;
            $state['count'] = $currentCount + 1;
        }
        $state['column'] = $aggregateColumn;
        
        return $state;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $state
     * @param string|int|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processMinRecord(array $record, array $state, string|int|null $aggregateColumn): array
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
     * @param string|int|null $aggregateColumn
     * @return array<string, mixed>
     */
    private function processMaxRecord(array $record, array $state, string|int|null $aggregateColumn): array
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
     * @param array<string|int>|string|int $columns
     * @return mixed
     */
    private function finalizeAggregate(array $state, string $aggregate, array|string|int $columns): mixed
    {
        return match ($aggregate) {
            'first' => isset($state['found']) && $state['found'] && isset($state['row']) && is_array($state['row']) 
                ? $this->extractColumns($this->ensureStringKeyedArray($state['row']), $columns) 
                : null,
            'last' => isset($state['found']) && $state['found'] && isset($state['row']) && is_array($state['row']) 
                ? $this->extractColumns($this->ensureStringKeyedArray($state['row']), $columns) 
                : null,
            'count' => isset($state['count']) && is_int($state['count']) && $state['count'] > 0 
                ? $state['count'] 
                : null,
            'sum' => (isset($state['sum']) && is_numeric($state['sum']) && ($state['sum'] !== 0 || isset($state['column']))) 
                ? $state['sum'] 
                : null,
            'avg' => (isset($state['count']) && is_int($state['count']) && $state['count'] > 0 && isset($state['sum']) && is_numeric($state['sum'])) 
                ? $state['sum'] / $state['count'] 
                : null,
            'min' => isset($state['minRow']) && is_array($state['minRow']) 
                ? $this->extractColumns($this->ensureStringKeyedArray($state['minRow']), $columns) 
                : null,
            'max' => isset($state['maxRow']) && is_array($state['maxRow']) 
                ? $this->extractColumns($this->ensureStringKeyedArray($state['maxRow']), $columns) 
                : null,
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * Ensure array has string keys (helper for PHPStan)
     * @param array<mixed, mixed> $array
     * @return array<string, mixed>
     */
    private function ensureStringKeyedArray(array $array): array
    {
        /** @var array<string, mixed> */
        return $array;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string|int>|string|int $columns
     * @return mixed
     */
    private function extractColumns(array $row, array|string|int $columns): mixed
    {
        if (empty($columns)) {
            return $row;
        }
        
        if (is_string($columns) || is_int($columns)) {
            return $row[$columns] ?? null;
        }
        
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = $row[$column] ?? null;
        }
        
        return $result;
    }
}
