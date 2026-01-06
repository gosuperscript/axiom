<?php

declare(strict_types=1);

namespace Superscript\Lookups\Support\Filters;

use Superscript\Schema\Source;

interface Filter
{
    public Source $value {get; }
}