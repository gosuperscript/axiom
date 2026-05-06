<?php

declare(strict_types=1);

namespace Superscript\Axiom;

final readonly class Schema
{
    public function __construct(
        public SchemaVersion $version,
        public Source $source,
    ) {}
}
