<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use InvalidArgumentException;

/**
 * Where a node sits in a {@see \Superscript\Axiom\Source} tree, addressed by
 * the property that holds it: `$.left.operand`, `$.arms[1].expression`.
 *
 * This is deliberately not the `$.children[0].node` language compilation
 * failures and analyses speak. That one numbers the children a *compiler*
 * recorded, and a compiler records what it needs: a wildcard arm records no
 * pattern child at all, so the same arm sits at a different index depending
 * on which patterns precede it. A coordinate into stored source has to name
 * the same node before anything is compiled, and the property names do —
 * they are the tree's own structure rather than an artifact of walking it.
 */
final readonly class SourcePath
{
    /** @param list<string> $segments */
    private function __construct(private array $segments) {}

    public static function root(): self
    {
        return new self([]);
    }

    /**
     * @param string $segment The property holding the child, optionally
     *                        subscripted for a list slot: `arms[1]`.
     */
    public function child(string $segment): self
    {
        if ($segment === '') {
            throw new InvalidArgumentException('A source path segment names a property and cannot be empty.');
        }

        return new self([...$this->segments, $segment]);
    }

    public function describe(): string
    {
        if ($this->segments === []) {
            return '$';
        }

        return '$.' . implode('.', $this->segments);
    }
}
