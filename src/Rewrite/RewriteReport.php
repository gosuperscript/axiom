<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * Everything one run of a rule set learned: every site a rule fired, every
 * site a rule was refused, and every shape the walk could not see inside.
 *
 * A report is the whole product of a dry run. {@see RewriteRun} hands back a
 * tree as well, and a caller that never takes the tree has run the rule set
 * in report-only mode — there is no second entry point and no mode flag to
 * pass, because the two runs would otherwise have to be kept identical by
 * hand.
 */
final readonly class RewriteReport
{
    /**
     * @param list<RewriteRecord> $records
     * @param list<OpaqueSource> $opaque
     */
    public function __construct(
        public array $records,
        public array $opaque,
    ) {}

    /** @return list<RewriteRecord> */
    public function applied(): array
    {
        return array_values(array_filter($this->records, fn(RewriteRecord $record): bool => $record->outcome === RewriteOutcome::Applied));
    }

    /** @return list<RewriteRecord> */
    public function refused(): array
    {
        return array_values(array_filter($this->records, fn(RewriteRecord $record): bool => $record->outcome === RewriteOutcome::Refused));
    }

    public function describe(): string
    {
        $lines = array_map(fn(RewriteRecord $record): string => $record->describe(), $this->records);
        $lines = [...$lines, ...array_map(fn(OpaqueSource $opaque): string => $opaque->describe(), $this->opaque)];

        return $lines === [] ? 'no rewrites, nothing opaque' : implode("\n", $lines);
    }
}
