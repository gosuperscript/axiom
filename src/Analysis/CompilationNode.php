<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Types\Type;

/** One certified source node and the compile-time decisions made for it. */
final readonly class CompilationNode
{
    /**
     * @param class-string $source
     * @param list<CompilationChild> $children
     * @param list<OperatorSelection> $operators
     */
    public function __construct(
        public string $source,
        public Type $returns,
        public string $extension,
        public array $children = [],
        public array $operators = [],
    ) {}

    /**
     * @return array{
     *     path: string,
     *     source: class-string,
     *     extension: string,
     *     returns: string,
     *     operators: list<array<string, mixed>>,
     *     children: list<array{role: ?string, node: array<string, mixed>}>
     * }
     */
    public function toArray(string $path = '$', bool $revealLiterals = false): array
    {
        $operators = [];

        foreach ($this->operators as $index => $operator) {
            $operators[] = $operator->toArray("{$path}.operators[{$index}]", $revealLiterals);
        }

        $children = [];

        foreach ($this->children as $index => $child) {
            $children[] = [
                'role' => $child->role,
                'node' => $child->node->toArray("{$path}.children[{$index}].node", $revealLiterals),
            ];
        }

        return [
            'path' => $path,
            'source' => $this->source,
            'extension' => $this->extension,
            'returns' => AnalysisTypeDescriber::describe($this->returns, $revealLiterals),
            'operators' => $operators,
            'children' => $children,
        ];
    }
}
