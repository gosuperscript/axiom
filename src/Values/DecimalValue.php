<?php

declare(strict_types=1);

namespace Superscript\Axiom\Values;

use Brick\Math\BigDecimal;

final readonly class DecimalValue implements Value
{
    public function __construct(
        public BigDecimal $value,
    ) {}

    public function unwrap(): BigDecimal
    {
        return $this->value;
    }
}
