<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

/** An operator selection paired with its deterministic compilation path. */
final readonly class LocatedOperatorSelection
{
    public function __construct(
        public string $path,
        public string $sourcePath,
        public OperatorSelection $selection,
    ) {}
}
