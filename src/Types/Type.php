<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * @template T = mixed
 */
interface Type
{
    /**
     * @param mixed $value
     * @return Result<Option<T>, Throwable>
     */
    public function coerce(mixed $value): Result;

    /**
     * @param mixed $value
     * @return Result<Option<T>, Throwable>
     */
    public function assert(mixed $value): Result;

    /**
     * @param T $a
     * @param T $b
     * @return bool
     */
    public function compare(mixed $a, mixed $b): bool;
}
