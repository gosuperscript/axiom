<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * One place a rule offered a replacement, and everything an {@see Obligation}
 * needs to judge it: the two subtrees, and the expression they live in —
 * its dialect, definitions, declarations and boundary.
 *
 * Both subtrees are compiled *standalone in the whole expression's scope*,
 * which is exact rather than approximate because the language has no binding
 * form: no node introduces a name for its children, so a subtree reads the
 * same environment wherever it sits, and match arms are ordinary sources
 * rather than a scope. A subtree therefore compiles here to the very type it
 * contributes in place.
 *
 * The two compilations are memoized because obligations stack: type
 * preservation and verdict preservation both want the same two programs, and
 * a corpus run wants one of them once per bindings set. Nothing here is
 * mutated after construction that a caller can observe — the answers are
 * derived from readonly inputs.
 */
final class RewriteSite
{
    /** @var ?Result<Program, TypeMismatch> */
    private ?Result $compiledBefore = null;

    /** @var ?Result<Program, TypeMismatch> */
    private ?Result $compiledAfter = null;

    public function __construct(
        public readonly Expression $context,
        public readonly SourcePath $path,
        public readonly Source $before,
        public readonly Source $after,
    ) {}

    /** @return Result<Program, TypeMismatch> */
    public function compileBefore(): Result
    {
        return $this->compiledBefore ??= $this->compile($this->before);
    }

    /** @return Result<Program, TypeMismatch> */
    public function compileAfter(): Result
    {
        return $this->compiledAfter ??= $this->compile($this->after);
    }

    /** @return Result<Program, TypeMismatch> */
    private function compile(Source $source): Result
    {
        return (new Expression(
            source: $source,
            definitions: $this->context->definitions,
            dialect: $this->context->dialect,
            declarations: $this->context->declarations,
            boundary: $this->context->boundary,
        ))->compile();
    }
}
