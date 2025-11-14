<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use League\Csv\Reader;
use RuntimeException;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\LookupSource;
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

            // Resolve all filter key values dynamically
            $resolvedFilters = [];
            foreach ($source->filterKeys as $column => $filterSource) {
                $result = $this->resolver->resolve($filterSource);
                
                if ($result->isErr()) {
                    return $result;
                }
                
                $option = $result->unwrap();
                if ($option->isNone()) {
                    return Ok(None());
                }
                
                $resolvedFilters[$column] = $option->unwrap();
            }

            // Stream through records and find matching rows
            $matchingRows = [];
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            
            foreach ($records as $record) {
                if ($this->matchesFilters($record, $resolvedFilters)) {
                    $matchingRows[] = $record;
                }
            }

            // No matches found
            if (empty($matchingRows)) {
                return Ok(None());
            }

            // Apply strategy to select the appropriate row
            $selectedRow = $this->applyStrategy($matchingRows, $source->strategy, $source->columns);

            // Extract the requested column(s)
            $result = $this->extractColumns($selectedRow, $source->columns);

            return Ok(Some($result));
        } catch (Throwable $e) {
            return new Err($e);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $filters
     */
    private function matchesFilters(array $record, array $filters): bool
    {
        foreach ($filters as $column => $value) {
            if (!isset($record[$column]) || $record[$column] !== (string) $value) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param array<string>|string $columns
     * @return array<string, mixed>
     */
    private function applyStrategy(array $rows, string $strategy, array|string $columns): array
    {
        return match ($strategy) {
            'first' => $rows[0],
            'last' => $rows[count($rows) - 1],
            'min' => $this->findMinRow($rows, $columns),
            'max' => $this->findMaxRow($rows, $columns),
            default => throw new RuntimeException("Unknown strategy: {$strategy}"),
        };
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param array<string>|string $columns
     * @return array<string, mixed>
     */
    private function findMinRow(array $rows, array|string $columns): array
    {
        $compareColumn = is_array($columns) ? $columns[0] : $columns;
        
        $minRow = $rows[0];
        $minValue = $rows[0][$compareColumn] ?? null;
        
        foreach ($rows as $row) {
            $value = $row[$compareColumn] ?? null;
            if ($value !== null && ($minValue === null || $value < $minValue)) {
                $minValue = $value;
                $minRow = $row;
            }
        }
        
        return $minRow;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param array<string>|string $columns
     * @return array<string, mixed>
     */
    private function findMaxRow(array $rows, array|string $columns): array
    {
        $compareColumn = is_array($columns) ? $columns[0] : $columns;
        
        $maxRow = $rows[0];
        $maxValue = $rows[0][$compareColumn] ?? null;
        
        foreach ($rows as $row) {
            $value = $row[$compareColumn] ?? null;
            if ($value !== null && ($maxValue === null || $value > $maxValue)) {
                $maxValue = $value;
                $maxRow = $row;
            }
        }
        
        return $maxRow;
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
