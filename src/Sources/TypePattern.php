<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/**
 * A match arm selected by type: the arm matches when the subject value
 * inhabits the pattern's type, and inside the arm the subject reference is
 * narrowed to it.
 *
 * ```php
 * // limit : 'unanswered' | 'novalue' | Number
 * new MatchExpression(new ReferencePath('limit'), [
 *     new MatchArm(new TypePattern(new NumberType()), $limitAbove100k),
 *     new MatchArm(new WildcardPattern(), new StaticSource(false)),
 * ]);
 * ```
 *
 * Within the first arm, `limit` types as `Number`, so `limit > 100000`
 * resolves against the number rules — the union never reaches an operator.
 * Matching uses the type's own {@see Type::assert()} judgment, the same one
 * that certifies values at the program boundary, so a value can never reach
 * an arm whose narrowed compilation did not admit it.
 *
 * A type pattern also proves coverage: a union subject is exhaustively
 * matched once every member is claimed by a literal arm, a type arm it is
 * assignable to, or a wildcard.
 */
final readonly class TypePattern implements MatchPattern, Describable
{
    public function __construct(
        public Type $type,
    ) {}

    public function describe(): string
    {
        return TypeDescriber::describe($this->type);
    }
}
