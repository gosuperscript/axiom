<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Closure;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Source;

use function Superscript\Monads\Result\Ok;

/**
 * @template T
 * @implements Source<T>
 */
final readonly class StaticSource implements Source
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value,
    ) {}

    public function resolver(): Closure
    {
        return fn() => Ok(Option::from($this->value));
    }
}
