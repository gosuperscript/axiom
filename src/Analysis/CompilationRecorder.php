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

    private readonly References $references;

    /** @param string $path Where the node being compiled sits in the source tree. */
    public function __construct(private readonly string $path = '$')
    {
        $this->references = new References();
    }

    /**
     * The path of the next child to be compiled, numbered off the children
     * recorded so far — the same counting {@see CompilationNode::toArray()}
     * does when it derives paths for a finished tree, so a path a failure
     * reports and a path the analysis reports for one node are the same
     * string.
     *
     * Every child compilation records, whether it produced a node or refused
     * ({@see CompilationNode::abandoned()}), and that is the invariant paths
     * rely on: an index names the same child in every attempt, so a
     * quarantine entry written in one attempt still names the node that
     * refused in the next. Were a refusing child to record nothing, the
     * sibling after it would slide into its index in the attempts where it
     * refuses and out of it in the attempts where it is set aside — and a
     * path would name two different nodes.
     */
    public function childPath(): string
    {
        return CompilationNode::childPath($this->path, count($this->children));
    }

    public function child(CompilationNode $node, ?string $role): void
    {
        $this->children[] = new CompilationChild($node, $role);
    }

    public function operator(OperatorSelection $operator): void
    {
        $this->operators[] = $operator;
    }

    /** @param list<string> $references */
    public function recordReferences(array $references): void
    {
        $this->references->record($references);
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

    /** @return list<string> */
    public function references(): array
    {
        return $this->references->all();
    }
}
