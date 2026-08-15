<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use InvalidArgumentException;
use Superscript\Axiom\Program;
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
 *    Failure is a compilation state rather than a type, so there is no marker
 *    to appear here in a type's place, and `Program`'s constructor
 *    independently refuses any node tree in which something failed.
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
     * Construction goes through {@see certified()} and {@see refused()}, and
     * is private so no third shape exists: the two states this can be in are
     * "a program, nothing reported" and "at least one diagnostic, no
     * program". A value holding neither would be a verdict with nothing
     * behind it, and {@see program()} would have nothing to answer with.
     *
     * @param non-empty-list<TypeMismatch>|array{} $diagnostics
     * @param list<string> $references
     * @param ?Type $returns What the expression returns, or null when the root
     *                      node itself did not compile and so returns nothing.
     * @param ?Program $program The program the compiler certified, or null
     *                      when something refused.
     */
    private function __construct(
        public array $diagnostics,
        public array $references,
        public ?Type $returns,
        private ?Program $program,
    ) {}

    /**
     * An expression the compiler certified: the program, and the type it
     * returns read from the program itself rather than carried alongside it.
     * Nothing refused, so there is nothing to report.
     *
     * @param list<string> $references
     */
    public static function certified(Program $program, array $references): self
    {
        return new self([], $references, $program->returns, $program);
    }

    /**
     * An expression something refused: every refusal, and no program.
     *
     * A root type may still be present. A fault under a node that recovers —
     * a broken match arm, absorbed into the union of its siblings — leaves a
     * type the compilation genuinely certified beside the diagnostic that
     * refuses the expression; null means the root itself did not compile.
     *
     * @param non-empty-list<TypeMismatch> $diagnostics
     * @param list<string> $references
     */
    public static function refused(array $diagnostics, array $references, ?Type $returns): self
    {
        if ($diagnostics === []) {
            // Checked here rather than asserted: production runs with
            // assertions compiled out, and this is the invariant program()
            // answers with an Err on. Without it a caller reads Err([]) from
            // a return type that promises at least one refusal.
            throw new InvalidArgumentException('A diagnosis without a program reports what stands in the way of one, and this one reports nothing. Certify it with Diagnosis::certified() instead.');
        }

        return new self($diagnostics, $references, $returns, null);
    }

    /**
     * The certified program, or everything that stands in its way. The two
     * are exclusive by construction, so this reads which one it holds.
     *
     * @return Result<Program, non-empty-list<TypeMismatch>>
     */
    public function program(): Result
    {
        if ($this->program !== null) {
            return Ok($this->program);
        }

        /** @var non-empty-list<TypeMismatch> */
        $diagnostics = $this->diagnostics;

        return Err($diagnostics);
    }
}
