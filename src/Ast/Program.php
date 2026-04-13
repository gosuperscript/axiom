<?php

declare(strict_types=1);

namespace Superscript\Axiom\Ast;

/**
 * @param list<Declaration> $declarations
 */
final readonly class Program implements Node
{
    /**
     * @param list<Declaration> $declarations
     */
    public function __construct(
        public array $declarations = [],
    ) {}
}
