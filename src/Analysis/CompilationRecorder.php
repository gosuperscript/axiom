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

    /** @param string $path Where the node being compiled sits in the source tree. */
    public function __construct(private readonly string $path = '$') {}

    /**
     * The path of the next child to be compiled, numbered off the children
     * recorded so far — the same counting {@see CompilationNode::toArray()}
     * does when it derives paths for a finished tree, so a path a failure
     * reports and a path the analysis reports for one node are the same
     * string. A child that records no compilation advances neither count.
     *
     * Compilation stops at the first failure, so a child that fails before it
     * is recorded may safely claim the index it would have had: no sibling
     * ever comes to claim it too.
     */
    public function childPath(): string
    {
        return sprintf('%s.children[%d].node', $this->path, count($this->children));
    }

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
