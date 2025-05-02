<?php

namespace Superscript\Abacus\Types;

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
    public function transform(mixed $value): Result;

    /**
     * @param T $a
     * @param T $b
     * @return bool
     */
    public function compare(mixed $a, mixed $b): bool;

    /**
     * @param T $value
     * @return string
     */
    public function format(mixed $value): string;
}