<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * Recursive compilation capability handed to an extension's source
 * compiler. It keeps TypeInference and TypeEnvironment behind the compiler
 * seam while allowing a host source to compile the Source children it owns.
 */
final readonly class SourceCompilation
{
    /**
     * @internal Constructed by TypeInference for the current environment.
     * @param Closure(Source): Result<CompiledNode, TypeMismatch> $compileNode
     */
    public function __construct(private Closure $compileNode) {}

    /** @return Result<CompiledNode, TypeMismatch> */
    public function compile(Source $source): Result
    {
        return ($this->compileNode)($source);
    }

    /**
     * @param list<Source> $sources
     * @return Result<list<CompiledNode>, TypeMismatch>
     */
    public function compileAll(array $sources): Result
    {
        $compiled = [];

        foreach ($sources as $source) {
            $node = $this->compile($source);

            if ($node->isErr()) {
                return $node;
            }

            $compiled[] = $node->unwrap();
        }

        return Ok($compiled);
    }
}
