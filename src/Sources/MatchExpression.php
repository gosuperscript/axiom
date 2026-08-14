<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class MatchExpression implements Source, Describable
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Source $subject,
        public array $arms,
    ) {}

    public function describe(): string
    {
        $subject = $this->subject instanceof Describable
            ? $this->subject->describe()
            : (new \ReflectionClass($this->subject))->getShortName();

        $arms = implode(', ', array_map(fn (MatchArm $arm) => $arm->describe(), $this->arms));

        return sprintf('match %s { %s }', $subject, $arms);
    }
}
