<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchPattern;

/**
 * The straight-line capability a descent arm is handed: rewrite this child,
 * and tell me where it sits. It is bound to the node being descended, so an
 * arm names the property holding each child — `'left'`, `'arms[1]'` — and
 * never assembles a path.
 *
 * The rewritten child comes back; an arm compares it against the one it had
 * to decide whether to rebuild. That comparison is the arm's, because only
 * the arm knows how to put its node back together.
 */
final readonly class Descent
{
    /** @internal Built by the walk, one per node. */
    public function __construct(
        private RewriteWalk $walk,
        private SourcePath $path,
    ) {}

    public function child(Source $source, string $segment): Source
    {
        return $this->walk->source($source, $this->path->child($segment));
    }

    public function pattern(MatchPattern $pattern, string $segment): MatchPattern
    {
        return $this->walk->pattern($pattern, $this->path->child($segment));
    }

    public function arm(MatchArm $arm, string $segment): MatchArm
    {
        return $this->walk->arm($arm, $this->path->child($segment));
    }
}
