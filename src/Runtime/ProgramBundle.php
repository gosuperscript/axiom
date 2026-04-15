<?php

declare(strict_types=1);

namespace Superscript\Axiom\Runtime;

use Superscript\Axiom\Artifacts\ArtifactRepository;
use Superscript\Axiom\Extensions\Extension;

/**
 * @param array<string, string> $sources
 * @param list<Extension> $extensions
 */
final readonly class ProgramBundle
{
    /**
     * @param array<string, string> $sources
     * @param list<Extension> $extensions
     */
    public function __construct(
        public array $sources,
        public ArtifactRepository $artifacts,
        public array $extensions = [],
    ) {}
}
