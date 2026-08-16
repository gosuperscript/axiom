<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Expression;
use Superscript\Axiom\Source;

/**
 * The result of applying a rule set to one expression: the tree that came
 * out, and the {@see RewriteReport} explaining it.
 *
 * `changed` is answered by identity, not comparison: a run that applied
 * nothing returns the very source it was given, because every descent arm
 * returns its own node when no child moved. A host can therefore store the
 * result unconditionally and let identity decide whether anything is worth
 * persisting.
 */
final readonly class RewriteRun
{
    public bool $changed;

    public function __construct(
        public Expression $original,
        public Source $source,
        public RewriteReport $report,
    ) {
        $this->changed = $original->source !== $source;
    }

    /** The rewritten expression: the original's dialect, definitions, declarations and boundary over the new tree. */
    public function expression(): Expression
    {
        return new Expression(
            source: $this->source,
            definitions: $this->original->definitions,
            dialect: $this->original->dialect,
            declarations: $this->original->declarations,
            boundary: $this->original->boundary,
        );
    }
}
