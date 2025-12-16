<?php

declare(strict_types=1);

namespace Superscript\Lookups\Support\Filters;

use Superscript\Lookups\CsvRecord;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OperatorOverloader;
use Superscript\Schema\Operators\OverloaderManager;
use Superscript\Schema\Source;

final readonly class ValueFilter implements Filter
{
    public function __construct(
        public Source $value,
        public string|int|null $column = null,
        public string $operator = '=',
    ) {}

    public function matches(CsvRecord $record, mixed $value): bool
    {
        return $this->getOperatorOverloader()->evaluate($record->get($this->column), $value, $this->operator);
    }

    private function getOperatorOverloader(): OperatorOverloader
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }
}
