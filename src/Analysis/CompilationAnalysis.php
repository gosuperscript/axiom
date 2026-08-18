<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use InvalidArgumentException;
use JsonSerializable;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Types\RecordProperty;
use Superscript\Axiom\Types\RecordType;

/**
 * The typed, serializable explanation of one successful compilation. Its
 * nodes come in the two kinds {@see CompilationNode} describes: compilations
 * the compiler certified, and positions a compiler abandoned — which claim
 * no type and no owning compiler, and hold nothing under them.
 */
final readonly class CompilationAnalysis implements JsonSerializable
{
    /**
     * Construction goes through {@see certified()} and is private, so the
     * only analysis that exists is one over a tree the compiler certified.
     *
     */
    private function __construct(
        public CompilationNode $root,
        public RecordType $declarations,
        public Boundary $boundary,
    ) {}

    /**
     * The explanation of a compilation the compiler certified, root and all.
     *
     * Two roots are refused. Neither claims a type or an owning compiler, so
     * an analysis holding one could not be rendered: {@see toArray()} would
     * refuse partway through, on the path where an analysis is usually
     * already being written to a log or a build artifact. The root is
     * answered for here instead, where the caller still has somewhere to put
     * the answer. They are different mistakes and say so.
     *
     * A **failed** root, or one with a failure anywhere beneath it: nothing
     * was certified there. {@see CompilationNode::$containsFailure} answers
     * for the whole subtree, so a failure absorbed under an ordinary type is
     * caught with it.
     *
     * An **abandoned** root: a position a compiler declined to fill. It
     * carries no failure — the parent that abandoned it compiled without it —
     * so the failure question alone lets it through, and only its state
     * catches it. Abandonment befalls a child and never a root, so an
     * abandoned root is a caller handing over a position where a compilation
     * belongs.
     *
     */
    public static function certified(CompilationNode $root, RecordType $declarations, Boundary $boundary): self
    {
        // Checked rather than asserted: production runs with assertions
        // compiled out, and these are the invariants every reader of an
        // analysis relies on.
        if ($root->state === CompilationState::Abandoned) {
            throw new InvalidArgumentException('An analysis explains a compilation the compiler certified, and this root was abandoned: it is a position held so paths stay stable, claiming no type and no owning compiler for an analysis to report.');
        }

        if ($root->containsFailure) {
            throw new InvalidArgumentException('An analysis explains a compilation the compiler certified, and something under this root failed to compile. Read Expression::diagnose() for what refused.');
        }

        return new self($root, $declarations, $boundary);
    }

    /** @return list<LocatedOperatorSelection> */
    public function operators(): array
    {
        $operators = [];
        $this->collectOperators($this->root, '$', $operators);

        return $operators;
    }

    /**
     * Literal values are redacted by default because analyses commonly leave
     * the process as publication findings, logs, or build artifacts.
     *
     * @return array{
     *     version: 2,
     *     boundary: string,
     *     declarations: array<string, array{type: string, optional: bool}>,
     *     root: array<string, mixed>
     * }
     */
    public function toArray(bool $revealLiterals = false): array
    {
        return [
            'version' => 2,
            'boundary' => $this->boundary->name,
            'declarations' => array_map(
                fn(RecordProperty $property): array => [
                    'type' => AnalysisTypeDescriber::describe($property->type, $revealLiterals),
                    'optional' => $property->optional,
                ],
                $this->declarations->properties,
            ),
            'root' => $this->root->toArray('$', $revealLiterals),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<LocatedOperatorSelection> $operators */
    private function collectOperators(CompilationNode $node, string $path, array &$operators): void
    {
        foreach ($node->operators as $index => $selection) {
            $operators[] = new LocatedOperatorSelection(
                "{$path}.operators[{$index}]",
                $path,
                $selection,
            );
        }

        foreach ($node->children as $index => $child) {
            $this->collectOperators($child->node, CompilationNode::childPath($path, $index), $operators);
        }
    }
}
