<?php

declare(strict_types=1);

namespace Superscript\Lookups;

use Superscript\Lookups\Support\Aggregates\AggregateEnum;
use Superscript\Lookups\Support\Filters\Filter;
use Superscript\Schema\Source;
use function Psl\Iter\first;

final readonly class LookupSource implements Source
{
    public string|int|null $aggregateColumn;

    public function __construct(
        public string $filePath,
        /** @var array<Filter> $filters */
        public array $filters = [],
        /** @var array<string|int> $columns */
        public array $columns = [],
        public AggregateEnum $aggregate = AggregateEnum::FIRST,
        string|int|null $aggregateColumn = null,
        public string $delimiter = ',',
        public bool $hasHeader = true,
    ) {
        $this->aggregateColumn = $aggregateColumn ?? (count($this->columns) === 1 && in_array($this->aggregate, AggregateEnum::numericAggregates())
            ? first($this->columns)
            : null);
    }
}
