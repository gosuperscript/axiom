<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * The negative verdict of a type relation: a message plus a nested cause
 * chain. Relations have exactly two outcomes — Ok, or Err(TypeMismatch);
 * there is no boolean channel.
 *
 * A mismatch is $dead when the operation is well-formed but statically
 * meaningless — a comparison or membership test that can never hold. Dead
 * mismatches flag probable author bugs; ordinary mismatches flag
 * operations no rule resolves.
 *
 * $path is where in the source tree the refusal was made, in the same
 * `$`-rooted language {@see \Superscript\Axiom\Analysis\CompilationNode::toArray()}
 * uses on the success path, so a caller addresses a failed node exactly as
 * it addresses a compiled one:
 *
 * ```php
 * $expression->compile()->unwrapErr()->path; // '$.children[0].node'
 * ```
 *
 * It is null when the verdict is not about a node. Two kinds of refusal stay
 * unlocated on purpose: whole-program properties (a definition cycle is a
 * property of the graph, not of a position), and claims about types rather
 * than nodes — the cause `String is not assignable to Number.` comes from a
 * relation given two types, and nothing at that level knows which node
 * produced either one. So null reads as "this is not a node's fault", not as
 * "location unknown".
 */
final readonly class TypeMismatch
{
    /**
     * @param list<TypeMismatch> $causes
     */
    public function __construct(
        public string $message,
        public array $causes = [],
        public bool $dead = false,
        public ?string $path = null,
    ) {}

    /**
     * The same verdict, located at $path — or unchanged if it already has a
     * path. First location wins, because a refusal is stamped as it leaves
     * the node that made it and then travels up through that node's
     * ancestors: keeping the first stamp names the deepest node that
     * refused, which is the one to point at. An ancestor's own refusal
     * arrives here unlocated and takes the ancestor's path.
     */
    public function at(string $path): self
    {
        return $this->path === null
            ? new self($this->message, $this->causes, $this->dead, $path)
            : $this;
    }

    public function describe(): string
    {
        return $this->render(0);
    }

    private function render(int $depth): string
    {
        $lines = str_repeat('  ', $depth) . $this->message;

        foreach ($this->causes as $cause) {
            $lines .= "\n" . $cause->render($depth + 1);
        }

        return $lines;
    }
}
