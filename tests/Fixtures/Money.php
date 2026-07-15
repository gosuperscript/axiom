<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

/**
 * A deliberately small value object standing in for a package such as
 * brick/money. Its equality includes both amount and currency.
 */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public function isSameValueAs(self $other): bool
    {
        return $this->minor === $other->minor
            && $this->currency === $other->currency;
    }
}
