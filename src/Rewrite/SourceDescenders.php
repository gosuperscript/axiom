<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use InvalidArgumentException;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MatchPattern;

/**
 * Who knows how to take a node apart. The core map is always there; a host
 * joins its own through the extension it already ships, so one package
 * declares both how its node compiles ({@see Extension::sourceCompilers()})
 * and how it is descended ({@see Extension::sourceDescenders()}), and the two
 * cannot end up in different places.
 *
 * Ownership is exact and unranked, exactly as it is for source compilers: two
 * extensions claiming one class is a configuration error, never a precedence
 * question, and a class no extension claims is not an error at all — it is an
 * opaque leaf the run reports.
 */
final readonly class SourceDescenders
{
    /**
     * @param array<class-string<Source>, callable(Source, Descent): Source> $sources
     * @param array<class-string<MatchPattern>, callable(MatchPattern, Descent): MatchPattern> $patterns
     */
    private function __construct(
        public array $sources,
        public array $patterns,
    ) {}

    public static function core(): self
    {
        return new self(CoreSourceDescenders::sources(), CoreSourceDescenders::patterns());
    }

    public function with(Extension ...$extensions): self
    {
        $sources = $this->sources;

        foreach ($extensions as $extension) {
            foreach ($extension->sourceDescenders() as $class => $descender) {
                if (array_key_exists($class, $sources)) {
                    throw new InvalidArgumentException(sprintf(
                        'Source class [%s] has two descent arms; descent ownership is exact and extension order carries no precedence.',
                        $class,
                    ));
                }

                $sources[$class] = $descender;
            }
        }

        return new self($sources, $this->patterns);
    }
}
