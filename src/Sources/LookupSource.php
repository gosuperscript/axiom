<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;

/**
 * Represents a lookup operation on CSV/TSV files.
 * 
 * @property string $filePath The path to the CSV/TSV file
 * @property string $delimiter The field delimiter (e.g., ',' for CSV, "\t" for TSV)
 * @property array<string, Source> $filterKeys Array of column names to filter values (values are Sources to be resolved)
 * @property array<string>|string $columns Column name(s) to retrieve from matching rows
 * @property string $strategy Strategy to use when multiple rows match (first, last, min, max)
 * @property string|null $sortColumn Column to use for min/max strategies (required when using min/max)
 * @property bool $hasHeader Whether the file has a header row
 */
final readonly class LookupSource implements Source
{
    /**
     * @param string $filePath
     * @param string $delimiter
     * @param array<string, Source> $filterKeys
     * @param array<string>|string $columns
     * @param string $strategy
     * @param string|null $sortColumn
     * @param bool $hasHeader
     */
    public function __construct(
        public string $filePath,
        public string $delimiter = ',',
        public array $filterKeys = [],
        public array|string $columns = [],
        public string $strategy = 'first',
        public ?string $sortColumn = null,
        public bool $hasHeader = true,
    ) {}
}
