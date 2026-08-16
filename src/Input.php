<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;

/**
 * A declared input: its type, plus whether the caller must supply a binding
 * for it. Those are two questions, and a type alone answers only the first.
 *
 * A type says what values are admissible, absence included — `String?`
 * admits absence, so `== none` is a compilable comparison against it. The
 * boundary needs a second answer a type cannot give: must the *call* say
 * something? A host asking "did they choose the no-value option, or have
 * they not answered yet?" needs both — the type must admit absence for the
 * chosen no-value answer to be expressible, and the call must still be
 * required to say so, or an unanswered question reads as that answer.
 *
 * ```php
 * declarations: ['excess' => Input::demanded(new OptionType($monetary))]
 * // omitted        → MissingRequiredInput — nobody has answered yet
 * // bound null/''  → Ok(None)             — answered, and the answer is "none"
 * ```
 *
 * {@see Input::of()} derives the demand from the shape, which is what a bare
 * {@see Type} declaration means: absence-admitting shapes may be omitted,
 * every other shape must be bound. {@see Input::demanded()} keeps the type's
 * answer about absence and overrides the answer about supply.
 *
 * An Input is not a Type and never reaches inference: the compiler sees the
 * types these carry and nothing else, so demanding a binding cannot change
 * what an expression means or what it returns — only what a call must bring.
 */
final readonly class Input
{
    private function __construct(
        public Type $type,
        private bool $demanded,
    ) {}

    /**
     * An input whose demand follows its type: bound unless the shape admits
     * absence.
     */
    public static function of(Type $type): self
    {
        return new self($type, false);
    }

    /**
     * An input the caller must bind, whatever its type admits. On a type that
     * already requires presence the flag adds nothing and is accepted in
     * silence — `demanded()` is composable over any declaration, and a host
     * marking a whole scope demanded should not have to ask which members
     * were optional.
     */
    public static function demanded(Type $type): self
    {
        return new self($type, true);
    }

    /**
     * Read a declaration map either form may be written in: bare types take
     * the {@see Input::of()} reading of themselves, and everything past
     * ingestion sees one kind of declaration.
     *
     * @param array<string, Type|Input> $declarations
     * @return array<string, Input>
     */
    public static function normalize(array $declarations): array
    {
        return array_map(
            static fn(Type|Input $declaration) => $declaration instanceof self ? $declaration : self::of($declaration),
            $declarations,
        );
    }

    /**
     * May the value be absent? A property of the shape the type projects, not
     * of the concrete class and not of member order: `Union(String,
     * Option<Number>)` and `Union(Option<Number>, String)` both project
     * `(String | Number)?`, because a union with any absence-admitting member
     * admits absence ({@see \Superscript\Axiom\Types\Shapes\UnionShape}, where
     * Option members hoist).
     */
    public function admitsAbsence(): bool
    {
        return $this->type->shape() instanceof OptionShape;
    }

    /**
     * Must the call supply a binding? Demanded explicitly, or by a type with
     * no absence to fall back on.
     */
    public function demandsBinding(): bool
    {
        return $this->demanded || !$this->admitsAbsence();
    }
}
