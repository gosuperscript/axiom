<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Expression;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchPattern;

/**
 * One pass of a rule set over one tree, and the notebook it writes as it
 * goes. A walk is used once: {@see Rewriter} builds it per run, so the
 * records it accumulates belong to that run and nothing is carried between
 * two.
 *
 * @internal
 */
final class RewriteWalk
{
    /** @var list<RewriteRecord> */
    private array $records = [];

    /** @var list<OpaqueSource> */
    private array $opaque = [];

    /**
     * @param array<class-string<Source>, list<RewriteRule>> $rules Indexed by the exact class each rule visits.
     * @param array<string, Obligation> $obligations Keyed by the preservation each discharges.
     */
    public function __construct(
        private readonly Expression $context,
        private readonly array $rules,
        private readonly SourceDescenders $descenders,
        private readonly array $obligations,
    ) {}

    public function run(): RewriteRun
    {
        $source = $this->source($this->context->source, SourcePath::root());

        return new RewriteRun($this->context, $source, new RewriteReport($this->records, $this->opaque));
    }

    /**
     * Bottom-up: a node's children are rewritten, the node is rebuilt around
     * whatever came back, and only then are rules offered the rebuilt node.
     * That order is what lets one pass collapse a nest — `!!!!x` reduces at
     * every level on the way out — and it means a rule always sees the shape
     * that will actually be stored, never one a deeper rewrite is about to
     * change.
     */
    public function source(Source $node, SourcePath $path): Source
    {
        $descender = $this->descenders->sources[$node::class] ?? null;

        if ($descender === null) {
            $this->opaque[] = OpaqueSource::at($path, $node);

            return $node;
        }

        return $this->apply($descender($node, new Descent($this, $path)), $path);
    }

    public function pattern(MatchPattern $node, SourcePath $path): MatchPattern
    {
        $descender = $this->descenders->patterns[$node::class] ?? null;

        if ($descender === null) {
            $this->opaque[] = OpaqueSource::at($path, $node);

            return $node;
        }

        return $descender($node, new Descent($this, $path));
    }

    public function arm(MatchArm $arm, SourcePath $path): MatchArm
    {
        $descent = new Descent($this, $path);
        $pattern = $descent->pattern($arm->pattern, 'pattern');
        $expression = $descent->child($arm->expression, 'expression');

        return $pattern === $arm->pattern && $expression === $arm->expression
            ? $arm
            : new MatchArm($pattern, $expression);
    }

    /**
     * The rules registered for this exact class, in registration order, until
     * one of them takes the site. A rule that offers nothing is passed over; a
     * rule whose replacement breaks an obligation is recorded refused and the
     * next rule is asked. The first sound replacement wins and ends the visit:
     * two rules that both want a node would otherwise compose in an order
     * nobody chose, and a rule offered its own output could rewrite forever.
     * Opportunities a rewrite creates are found by running the rewriter again
     * — a decision the caller makes, and can see in the report.
     */
    private function apply(Source $node, SourcePath $path): Source
    {
        foreach ($this->rules[$node::class] ?? [] as $rule) {
            $replacement = $rule->rewrite($node);

            if ($replacement === null) {
                continue;
            }

            $verdicts = $this->judge($rule, new RewriteSite($this->context, $path, $node, $replacement));

            if (array_any($verdicts, static fn(ObligationVerdict $verdict): bool => $verdict->broken)) {
                $this->records[] = RewriteRecord::refused($path, $rule, $node, $replacement, $verdicts);

                continue;
            }

            $this->records[] = RewriteRecord::applied($path, $rule, $node, $replacement, $verdicts);

            return $replacement;
        }

        return $node;
    }

    /**
     * Type preservation is checked at every site whether or not the rule
     * claims it: the toolkit always has that oracle, and a rewrite that
     * changes a program's type is not one anybody asked for. Everything else
     * a rule claims is checked when the run was given the oracle for it, and
     * reported unchecked when it was not. A rule free to name type
     * preservation among its own claims costs nothing by doing so: a claim
     * made twice is judged once.
     *
     * @return list<ObligationVerdict>
     */
    private function judge(RewriteRule $rule, RewriteSite $site): array
    {
        $verdicts = [];

        foreach (array_unique([Preservation::CertifiedType, ...$rule->preserves()], SORT_REGULAR) as $preservation) {
            $obligation = $this->obligations[$preservation->value] ?? null;

            $verdicts[] = $obligation === null
                ? ObligationVerdict::unchecked($preservation, 'the run was given no oracle for this claim')
                : $obligation->check($site);
        }

        return $verdicts;
    }
}
