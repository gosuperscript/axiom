<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

final readonly class OperatorEntry
{
    public function __construct(
        public string $symbol,
        public int $precedence,
        public Associativity $associativity,
        public OperatorPosition $position,
        public bool $isKeyword = false,
    ) {}
}
