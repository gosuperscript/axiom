<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use LogicException;
use Superscript\Axiom\Types\Type;

/**
 * One node of a compilation, in one of the three states a compilation can
 * leave a source in ({@see CompilationState}).
 *
 * A **certified** node is the ordinary one: a source the compiler typed,
 * carrying the type it returns, the extension whose compiler owns it, its
 * children, and the operator selections made for it.
 *
 * A **failed** node is a source the compiler gave up on — it refused, or a
 * child of it did and it absorbed the failure. Nothing was certified there,
 * so it carries no return type and no owning compiler and reading either
 * refuses. It keeps the children and operators the compiler got through
 * before it gave up, so what the walk did learn is not thrown away.
 *
 * An **abandoned** node is a position and nothing more. It stands where a
 * child refused and its parent caught the refusal and compiled without it —
 * {@see \Superscript\Axiom\SourceCompilers\CoerceSourceCompiler} does, for a
 * static value the literal registry cannot type. Nothing was built there, so
 * it claims no return type and no compiler either, and {@see toArray()}
 * renders `abandoned` in their place rather than an invented answer.
 *
 * It exists at all because paths are positional — `$.children[1].node` names
 * the second child — and a path must name the same node in every compilation
 * attempt a {@see Diagnosis} makes. Were a refusing child to record nothing,
 * the sibling after it would take its index in the attempts where it refuses
 * and a different one in the attempts where it is set aside, and one fault
 * would be reported at two paths.
 *
 * ## Which state blocks a program
 *
 * Only Failed. {@see $containsFailure} is that question asked of a whole
 * subtree, computed bottom-up at construction so
 * {@see \Superscript\Axiom\Program} answers it for a tree by reading one
 * boolean. An abandoned node never contributes to it: the parent that caught
 * the refusal compiled without it, and a program is certified over the nodes
 * it runs.
 *
 * A failed node therefore never appears in an analysis — an analysis is
 * built only for a certified program — which is why {@see toArray()} has no
 * rendering for one and refuses the same way reading its type does.
 */
final class CompilationNode
{
    /** What became of the source this node stands for. */
    public readonly CompilationState $state;

    /**
     * Did this node, or anything under it, fail to compile? Carried
     * bottom-up so the question is answered by reading a boolean instead of
     * walking the tree: {@see \Superscript\Axiom\Program} asks it of every
     * program it mints, including the overwhelming majority in which nothing
     * ever went wrong.
     */
    public readonly bool $containsFailure;

    /** The type this node was certified to return. */
    public Type $returns {
        get => $this->certifiedType ?? $this->unclaimed('return type');
    }

    /** The identity of the extension whose source compiler owns this node. */
    public string $extension {
        get => $this->owningExtension ?? $this->unclaimed('owning compiler');
    }

    private readonly ?Type $certifiedType;

    private readonly ?string $owningExtension;

    /**
     * The three states are minted by {@see certified()}, {@see failed()} and
     * {@see abandoned()}, and construction is private so no fourth exists: a
     * node either carries both the type it returns and the compiler that owns
     * it, or carries neither. A node holding one without the other would be a
     * compilation nobody made or one nobody can attribute, and both are
     * answered for in {@see toArray()} and in certification.
     *
     * @param class-string $source
     * @param list<CompilationChild> $children
     * @param list<OperatorSelection> $operators
     */
    private function __construct(
        public readonly string $source,
        CompilationState $state,
        ?Type $returns,
        ?string $extension,
        public readonly array $children = [],
        public readonly array $operators = [],
    ) {
        $this->state = $state;
        $this->certifiedType = $returns;
        $this->owningExtension = $extension;
        $this->containsFailure = $state === CompilationState::Failed
            || array_any($children, static fn(CompilationChild $child): bool => $child->node->containsFailure);
    }

    /**
     * A node the compiler typed: the type it returns and the extension whose
     * source compiler owns it, together, because a compilation is both.
     *
     * @param class-string $source
     * @param list<CompilationChild> $children
     * @param list<OperatorSelection> $operators
     */
    public static function certified(string $source, Type $returns, string $extension, array $children = [], array $operators = []): self
    {
        return new self($source, CompilationState::Certified, $returns, $extension, $children, $operators);
    }

    /**
     * A source the compiler gave up on. What it compiled and selected before
     * it gave up is kept; the type and the compiler are not, because neither
     * was ever settled.
     *
     * @param class-string $source
     * @param list<CompilationChild> $children
     * @param list<OperatorSelection> $operators
     */
    public static function failed(string $source, array $children = [], array $operators = []): self
    {
        return new self($source, CompilationState::Failed, null, null, $children, $operators);
    }

    /**
     * The position of a child whose compilation was abandoned: it refused,
     * and its parent caught the refusal and carried on without it.
     *
     * @param class-string $source
     */
    public static function abandoned(string $source): self
    {
        return new self($source, CompilationState::Abandoned, null, null);
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
     *     abandoned?: true,
     *     extension?: string,
     *     returns?: string,
     *     operators?: list<array<string, mixed>>,
     *     children?: list<array{role: ?string, node: array<string, mixed>}>
     * }
     */
    public function toArray(string $path = '$', bool $revealLiterals = false): array
    {
        if ($this->state === CompilationState::Abandoned) {
            // The position, and the reason there is nothing at it. A reader
            // that expects a type here is reading a node the compiler never
            // built, and says so rather than finding an invented one.
            return [
                'path' => $path,
                'source' => $this->source,
                'abandoned' => true,
            ];
        }

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

    /**
     * Neither an abandoned position nor a failed compilation settled a type
     * or a compiler, so answering for either would mean inventing one.
     * Reaching this is a reader treating the states alike.
     */
    private function unclaimed(string $missing): never
    {
        throw new LogicException(sprintf(
            $this->state === CompilationState::Abandoned
                ? 'This node was abandoned: its compilation refused and its parent compiled without it, so it has no %s. It holds its position so paths stay stable across compilation attempts, and nothing else.'
                : 'This node failed to compile and its refusal was reported as a diagnostic, so it has no %s. No program is certified from a tree containing it.',
            $missing,
        ));
    }
}
