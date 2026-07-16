<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Types\NumberType;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

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

    /** @return Result<CompiledNode, \Superscript\Axiom\Types\TypeMismatch> */
    private function compileValue(HostValueSource $source, SourceCompilation $compilation): Result
    {
        return Ok(new CompiledNode(
            $source->claims,
            fn(Runtime $runtime) => Ok($source->value === null ? None() : Some($source->value)),
        ));
    }

    /** @return Result<CompiledNode, \Superscript\Axiom\Types\TypeMismatch> */
    private function compileCounting(CountingSource $source, SourceCompilation $compilation): Result
    {
        $counter = $this->counter;

        return Ok(new CompiledNode(new NumberType(), function (Runtime $runtime) use ($source, $counter) {
            if ($counter !== null) {
                $counter->evaluations++;
            }

            return Ok(Some($source->value));
        }));
    }
}
