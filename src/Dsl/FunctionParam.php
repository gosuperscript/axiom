<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

final readonly class FunctionParam
{
    public function __construct(
        public string $name,
        public string $type = 'mixed',
        public bool $optional = false,
    ) {}
}
