<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;

/**
 * Bindings failed at the {@see \Superscript\Axiom\Program} boundary.
 * Violations are aggregated — every input the call got wrong is reported at
 * once, named by binding, before any evaluation happens.
 *
 * A boundary refusal is always one of two kinds, and hosts act on them
 * differently, so the kind is the class rather than a string a caller would
 * have to read:
 *
 *  - {@see MissingRequiredInput} — the call supplied nothing for a required
 *    input the program reads, and everything it did supply was admissible.
 *    A host calling a program against a partly-filled scope reads this as
 *    "not answerable yet", an ordinary state rather than a fault.
 *  - {@see InadmissibleBinding} — a value was supplied and does not inhabit
 *    its declared type, including one that reads as absent where the
 *    declaration requires presence. Something upstream of the call is wrong.
 *
 * A fault dominates absence: a call that both omits one required input and
 * supplies another badly is an {@see InadmissibleBinding}, because a supplied
 * value being wrong is a fault whatever else the call left out. So
 * `instanceof MissingRequiredInput` reads as "nothing here is wrong except
 * that inputs are still missing".
 *
 * `$violations` and `$inputs` are parallel: `$violations[$i]` is the message
 * about the binding named by `$inputs[$i]`.
 */
abstract class BoundaryViolation extends RuntimeException
{
    /**
     * @param non-empty-list<string> $violations
     * @param non-empty-list<string> $inputs The binding each violation names.
     */
    public function __construct(
        public readonly array $violations,
        public readonly array $inputs,
    ) {
        parent::__construct("Bindings rejected at the boundary:\n- " . implode("\n- ", $violations));
    }
}
