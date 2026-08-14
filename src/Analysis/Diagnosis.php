<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Boundary;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Program;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Everything error-tolerant compilation learned about one expression:
 * every refusal it makes, every symbol it read on the way, what the
 * expression returns, and — when nothing refused — the certified
 * {@see Program} itself.
 *
 * ```php
 * $diagnosis = $expression->diagnose();
 *
 * $diagnosis->diagnostics; // list<Diagnostic>, in the order the compiler met them
 * $diagnosis->references;  // symbols read, including ones that failed to resolve
 * $diagnosis->returns;     // the root type; ErrorType when the root itself failed
 * $diagnosis->program();   // Ok(Program) iff diagnostics === []
 * ```
 *
 * What it guarantees:
 *
 *  - **Zero diagnostics ⟺ a certified program.** `program()` is Ok exactly
 *    when nothing refused, and the `Program` it returns holds the very node
 *    the walk built — there is no second compilation. The first diagnostic
 *    is the same refusal, with the same message and the same path, that
 *    `Expression::compile()` returns; the rest are what compilation goes on
 *    to find once that node is set aside.
 *  - **No ErrorType survives certification.** {@see ErrorType} is minted
 *    only for a node a diagnostic was recorded for, so a diagnostic-free
 *    diagnosis contains none. `Program`'s constructor enforces the same
 *    claim independently, over the whole node tree.
 *  - **References survive broken regions.** A symbol read before a sibling
 *    failed is still reported, and a symbol that failed to resolve is
 *    reported too — which is the difference from `Program::$references`,
 *    the same reads minus the ones that never resolved.
 *
 * Diagnostics **converge**: a refusal an ErrorType absorbs is not reported,
 * so fixing one fault can reveal the next. `match postcode { 'SW1' => 1 }`
 * over an unbound `postcode` reports only the unbound symbol; the missing
 * wildcard is reported once `postcode` is declared, because until then the
 * subject is ErrorType and any set of patterns covers it. This is the usual
 * behaviour of a checker that recovers — one fault produces one diagnostic,
 * and the ones it hides are the ones it made unanswerable.
 */
final readonly class Diagnosis
{
    /** What the expression returns. {@see ErrorType} when the root node itself failed. */
    public Type $returns;

    /**
     * @param list<Diagnostic> $diagnostics
     * @param list<string> $references
     * @param array<string, Type> $declarations
     */
    public function __construct(
        public array $diagnostics,
        public array $references,
        private CompiledNode $root,
        private array $declarations = [],
        private Boundary $boundary = Boundary::Coerce,
    ) {
        $this->returns = $root->returns;
    }

    /**
     * The certified program, or everything that stands in its way.
     *
     * @return Result<Program, non-empty-list<Diagnostic>>
     */
    public function program(): Result
    {
        if ($this->diagnostics !== []) {
            return Err($this->diagnostics);
        }

        return Ok(new Program($this->root, $this->declarations, $this->boundary));
    }
}
