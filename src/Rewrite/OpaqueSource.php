<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

/**
 * A node the run could not see inside: its class has no descent arm, so the
 * walk stopped there. Nothing beneath it was visited and no rule was offered
 * it — including rules that name its own class, because a rule cannot be
 * trusted to rebuild a shape the toolkit cannot take apart.
 *
 * Reporting one is the whole point. A silent skip would let a host add a
 * source class, forget its descent arm, and read "no rewrites needed" as
 * "nothing to do" for as long as the omission lasted.
 */
final readonly class OpaqueSource
{
    /** @param class-string $class */
    private function __construct(
        public string $path,
        public string $class,
        public string $describe,
    ) {}

    public static function at(SourcePath $path, object $node): self
    {
        return new self($path->describe(), $node::class, Describes::node($node));
    }

    public function describe(): string
    {
        return sprintf('opaque %s at %s: %s', $this->class, $this->path, $this->describe);
    }
}
