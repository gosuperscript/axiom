<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast;

interface Node
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    /** @param mixed[] $data */
    public static function fromArray(array $data): static;
}
