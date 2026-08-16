<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite\Rules;

use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\RewriteRule;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\DefaultValue;

/**
 * `x ?? 0` becomes `x` where `x` can never be absent.
 *
 * A `DefaultValue` over a source whose type is not an option is already the
 * identity: the compiler returns the inner compiled source untouched, default
 * and all. The node is then pure noise in stored source — an author's guess
 * about a question that turned out to be required — and it reads as if
 * absence were possible here, which is worse than noise.
 *
 * This rule is the shape of one whose applicability is not decided by
 * matching. Whether the inner source can be absent is a fact about *types*,
 * which no amount of looking at the tree can settle, so the rule proposes the
 * removal everywhere and lets type preservation settle it: over an optional
 * inner the original certifies the present type `T` while the replacement
 * certifies `Option<T>`, the types differ, and the site is refused. Over a
 * definite inner both compile to the same type — necessarily, since they
 * compile to the same node — and the rewrite is taken.
 *
 * Removing the node does change what an execution observer sees: the
 * `default` label and its annotations go with it. That is a change to the
 * trace of the program, not to what the program answers.
 */
final readonly class RemoveRedundantDefault implements RewriteRule
{
    public function identifier(): string
    {
        return 'axiom.rewrite.remove-redundant-default';
    }

    public function visits(): array
    {
        return [DefaultValue::class];
    }

    public function preserves(): array
    {
        return [Preservation::Verdict];
    }

    public function rewrite(Source $source): ?Source
    {
        return $source instanceof DefaultValue ? $source->source : null;
    }
}
