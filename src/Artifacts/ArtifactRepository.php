<?php

declare(strict_types=1);

namespace Superscript\Axiom\Artifacts;

interface ArtifactRepository
{
    public function has(string $tableName): bool;

    public function fetch(string $tableName): string;
}
