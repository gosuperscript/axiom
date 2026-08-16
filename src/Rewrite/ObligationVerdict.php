<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * The answer one {@see Obligation} gives about one site, in three states
 * that a report must keep apart: upheld (checked, and it holds), broken
 * (checked, and it does not — the rewrite is refused), and unchecked (no
 * oracle was available, so nothing is known either way). Unchecked never
 * blocks a rewrite and never counts as evidence for one; it is the run
 * saying out loud what it did not prove.
 */
final readonly class ObligationVerdict
{
    private function __construct(
        public Preservation $preservation,
        public bool $checked,
        public bool $broken,
        public string $explanation,
    ) {}

    public static function upheld(Preservation $preservation, string $explanation): self
    {
        return new self($preservation, checked: true, broken: false, explanation: $explanation);
    }

    public static function broken(Preservation $preservation, string $explanation): self
    {
        return new self($preservation, checked: true, broken: true, explanation: $explanation);
    }

    public static function unchecked(Preservation $preservation, string $explanation): self
    {
        return new self($preservation, checked: false, broken: false, explanation: $explanation);
    }

    public function describe(): string
    {
        $status = match (true) {
            ! $this->checked => 'unchecked',
            $this->broken => 'broken',
            default => 'upheld',
        };

        return sprintf('%s %s: %s', $this->preservation->describe(), $status, $this->explanation);
    }
}
