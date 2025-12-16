<?php

declare(strict_types=1);

namespace Superscript\Lookups\Support\Filters;

use Superscript\Schema\Source;

final readonly class ValueFilter implements Filter
{
    public function __construct(
        public Source $value,
        public string|int|null $column = null,
        public string $operator = '=',
    ) {}
}
