<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite\Fixtures;

use Closure;
use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\RewriteRule;
use Superscript\Axiom\Source;

/** A rule whose matching, replacement and claims are handed to it. */
final readonly class StubRule implements RewriteRule
{
    /**
     * @param non-empty-list<class-string<Source>> $visits
     * @param Closure(Source): ?Source $rewrite
     * @param list<Preservation> $preserves
     */
    public function __construct(
        private string $identifier,
        private array $visits,
        private Closure $rewrite,
        private array $preserves = [],
    ) {}

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function visits(): array
    {
        return $this->visits;
    }

    public function rewrite(Source $source): ?Source
    {
        return ($this->rewrite)($source);
    }

    public function preserves(): array
    {
        return $this->preserves;
    }
}
