<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * An omittable record property. This qualifies the property, not its value:
 * when the key is present, the value must still inhabit {@see $type}.
 *
 * `new Optional(T)` and `new OptionType(T)` therefore answer different
 * questions. The former permits an omitted key and makes access return
 * `Option<T>`; the latter requires the key and permits an absent value.
 */
final readonly class Optional
{
    public function __construct(
        public Type $type,
    ) {}
}
