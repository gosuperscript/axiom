<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;

/**
 * Represents an exact match filter
 */
final readonly class ExactFilter
{
    public function __construct(
        public string|int $column,
        public Source $value,
    ) {}
}
