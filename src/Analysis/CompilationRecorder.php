<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/** @internal Mutable state scoped to compiling one source node. */
final class CompilationRecorder
{
    /** @var list<CompilationChild> */
    private array $children = [];

    /** @var list<OperatorSelection> */
    private array $operators = [];

    public function child(CompilationNode $node, ?string $role): void
    {
        $this->children[] = new CompilationChild($node, $role);
    }

    public function operator(OperatorSelection $operator): void
    {
        $this->operators[] = $operator;
    }

    /** @return list<CompilationChild> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<OperatorSelection> */
    public function operators(): array
    {
        return $this->operators;
    }
}
