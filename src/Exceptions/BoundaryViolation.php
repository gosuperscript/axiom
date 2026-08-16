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
 *    "not answerable yet", an ordinary state rather than a fault. Which
 *    reads are required is a per-declaration fact
 *    ({@see \Superscript\Axiom\Input::demandsBinding()}): a type admitting
 *    absence may be omitted unless the declaration demanded a binding anyway,
 *    which is how a host keeps "answered none" apart from "unanswered".
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
 * `$rejections` is the refusal itself: one {@see RejectedBinding} per input
 * at fault, each carrying the input's name and the message about it together,
 * so no reader has to align two lists. `$violations` is the messages
 * projected out of them, in the same order.
 */
abstract class BoundaryViolation extends RuntimeException
{
    /**
     * The messages of `$rejections`, in the same order.
     *
     * @var non-empty-list<string>
     */
    public readonly array $violations;

    /**
     * @param non-empty-list<RejectedBinding> $rejections
     */
    public function __construct(
        public readonly array $rejections,
    ) {
        $this->violations = array_map(static fn(RejectedBinding $rejection) => $rejection->message, $rejections);

        parent::__construct("Bindings rejected at the boundary:\n- " . implode("\n- ", $this->violations));
    }
}
