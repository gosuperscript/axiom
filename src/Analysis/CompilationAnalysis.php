<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use JsonSerializable;
use Superscript\Axiom\Boundary;
use Superscript\Axiom\Types\Type;

/**
 * The typed, serializable explanation of one successful compilation. Its
 * nodes come in the two kinds {@see CompilationNode} describes: compilations
 * the compiler certified, and positions a compiler abandoned — which claim
 * no type and no owning compiler, and hold nothing under them.
 */
final readonly class CompilationAnalysis implements JsonSerializable
{
    /** @param array<string, Type> $declarations */
    public function __construct(
        public CompilationNode $root,
        public array $declarations,
        public Boundary $boundary,
    ) {}

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
     *     version: 1,
     *     boundary: string,
     *     declarations: array<string, string>,
     *     root: array<string, mixed>
     * }
     */
    public function toArray(bool $revealLiterals = false): array
    {
        return [
            'version' => 1,
            'boundary' => $this->boundary->name,
            'declarations' => array_map(
                fn(Type $type): string => AnalysisTypeDescriber::describe($type, $revealLiterals),
                $this->declarations,
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
