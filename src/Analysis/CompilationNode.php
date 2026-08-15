<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Type;

/** One certified source node and the compile-time decisions made for it. */
final readonly class CompilationNode
{
    /**
     * Did this node, or anything under it, fail to compile? Carried
     * bottom-up so the question is answered by reading a boolean instead of
     * walking the tree: {@see \Superscript\Axiom\Program} asks it of every
     * program it mints, including the overwhelming majority in which nothing
     * ever went wrong.
     */
    public bool $failed;

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
    ) {
        $this->failed = $returns instanceof ErrorType
            || array_any($children, static fn(CompilationChild $child): bool => $child->node->failed);
    }

    /**
     * Where the child at $index under the node at $path sits. This is the
     * `$`-rooted path language itself: every path in a compilation — the one
     * a refusal is stamped with, the one the analysis prints, the one a
     * quarantine entry names — is this call, nested.
     */
    public static function childPath(string $path, int $index): string
    {
        return sprintf('%s.children[%d].node', $path, $index);
    }

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
                'node' => $child->node->toArray(self::childPath($path, $index), $revealLiterals),
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
