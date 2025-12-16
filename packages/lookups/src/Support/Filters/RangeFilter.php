<?php

declare(strict_types=1);

namespace Superscript\Lookups\Support\Filters;

use Superscript\Lookups\CsvRecord;
use Superscript\Schema\Source;

/**
 * Represents a range-based filter for banding scenarios
 */
final readonly class RangeFilter implements Filter
{
    public function __construct(
        public string|int $minColumn,
        public string|int $maxColumn,
        public Source $value,
    ) {}
}
