<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Program;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\TypeMismatch;
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
 * $diagnosis->diagnostics; // list<TypeMismatch>, in the order the compiler met them
 * $diagnosis->references;  // symbols read, including ones that failed to resolve
 * $diagnosis->returns;     // the root type, or null when the root itself failed
 * $diagnosis->program();   // Ok(Program) iff diagnostics === []
 * ```
 *
 * A diagnostic is a {@see TypeMismatch} — the same value `compile()` refuses
 * with, carrying its message, the node it names in `$path`, and the cause
 * chain `describe()` renders.
 *
 * What it guarantees:
 *
 *  - **Zero diagnostics ⟺ a certified program.** `program()` is Ok exactly
 *    when nothing refused, and the `Program` it returns holds the very node
 *    the walk built — there is no second compilation. The first diagnostic
 *    is the same refusal, with the same message and the same path, that
 *    `Expression::compile()` returns; the rest are what compilation goes on
 *    to find once that node is set aside.
 *  - **A failed root has no type.** `$returns` is null exactly when the root
 *    node itself did not compile; a type there is one compilation certified.
 *    The compiler's internal mark for a node it gave up on ({@see ErrorType})
 *    appears nowhere in this library's public surface, and `Program`'s
 *    constructor independently refuses any node tree containing one.
 *  - **References survive broken regions.** A symbol read before a sibling
 *    failed is still reported, and a symbol that failed to resolve is
 *    reported too — which is the difference from `Program::$references`,
 *    the same reads minus the ones that never resolved.
 *
 * Diagnostics **converge**: a refusal a failed source absorbs is not
 * reported, so fixing one fault can reveal the next.
 * `match postcode { 'SW1' => 1 }` over an unbound `postcode` reports only the
 * unbound symbol; the missing wildcard is reported once `postcode` is
 * declared, because until then the subject is uninhabited and any set of
 * patterns covers it. This is the usual
 * behaviour of a checker that recovers — one fault produces one diagnostic,
 * and the ones it hides are the ones it made unanswerable.
 */
final readonly class Diagnosis
{
    /**
     * @param list<TypeMismatch> $diagnostics
     * @param list<string> $references
     * @param ?Type $returns What the expression returns, or null when the root
     *                      node itself did not compile and so returns nothing.
     *                      A type here is one compilation certified, which is
     *                      not the same as an expression that is accepted: a
     *                      fault under a node that recovers — a broken match
     *                      arm, absorbed into the union of its siblings —
     *                      leaves a real root type alongside the diagnostic
     *                      that refuses the expression.
     * @param ?Program $program The program the compiler certified, or null
     *                      when something refused. Null and a non-empty
     *                      $diagnostics are the same verdict seen from two
     *                      sides: the compiler mints a program exactly when
     *                      the attempt that succeeded reported nothing.
     */
    public function __construct(
        public array $diagnostics,
        public array $references,
        public ?Type $returns,
        private ?Program $program,
    ) {}

    /**
     * The certified program, or everything that stands in its way.
     *
     * @return Result<Program, non-empty-list<TypeMismatch>>
     */
    public function program(): Result
    {
        if ($this->program !== null) {
            return Ok($this->program);
        }

        // The compiler mints a program exactly when nothing refused, so a
        // missing program means at least one diagnostic explains it.
        assert($this->diagnostics !== []);

        return Err($this->diagnostics);
    }
}
