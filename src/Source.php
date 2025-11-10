<?php

declare(strict_types=1);

namespace Superscript\Schema;

use Closure;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * @template T = mixed
 */
interface Source
{
    /** @return Closure(never, never, never, never, never, never, never): Result<Option<T>, \Throwable> */
    public function resolver(): Closure;
}
