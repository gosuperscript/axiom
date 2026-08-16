<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * One rule, at one site, with what it proposed and what became of it. A
 * refused record is as much a result as an applied one: it is the run saying
 * a rewrite was available and was not sound here.
 */
final readonly class RewriteRecord
{
    /** @param list<ObligationVerdict> $verdicts */
    private function __construct(
        public RewriteOutcome $outcome,
        public string $path,
        public string $rule,
        public string $before,
        public string $after,
        public array $verdicts,
    ) {}

    /** @param list<ObligationVerdict> $verdicts */
    public static function applied(SourcePath $path, RewriteRule $rule, object $before, object $after, array $verdicts): self
    {
        return new self(RewriteOutcome::Applied, $path->describe(), $rule->identifier(), Describes::node($before), Describes::node($after), $verdicts);
    }

    /** @param list<ObligationVerdict> $verdicts */
    public static function refused(SourcePath $path, RewriteRule $rule, object $before, object $after, array $verdicts): self
    {
        return new self(RewriteOutcome::Refused, $path->describe(), $rule->identifier(), Describes::node($before), Describes::node($after), $verdicts);
    }

    public function describe(): string
    {
        $lines = sprintf('%s %s at %s: %s => %s', $this->outcome->value, $this->rule, $this->path, $this->before, $this->after);

        foreach ($this->verdicts as $verdict) {
            $lines .= "\n  " . $verdict->describe();
        }

        return $lines;
    }
}
