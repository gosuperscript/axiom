<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Extension;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Types\NumberType;

final class SourceCompilerExtension extends Extension
{
    public function __construct(private readonly ?EvaluationCounter $counter = null) {}

    public function sourceCompilers(): array
    {
        return [
            HostValueSource::class => $this->compileValue(...),
            CountingSource::class => $this->compileCounting(...),
        ];
    }

    private function compileValue(HostValueSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->constant($source->claims, $source->value);
    }

    private function compileCounting(CountingSource $source, SourceCompilation $compilation): CompiledSource
    {
        $counter = $this->counter;

        return $compilation->produces(new NumberType(), function () use ($source, $counter) {
            if ($counter !== null) {
                $counter->evaluations++;
            }

            return $source->value;
        });
    }
}
