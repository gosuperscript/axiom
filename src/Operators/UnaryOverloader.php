<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * A unary operator rule owning both semantics, mirroring OperatorOverloader.
 *
 * Absence never reaches a unary rule: UnaryResolver short-circuits an absent
 * operand before any rule runs, so rules only see present values and
 * optionality propagates structurally in the inference layer.
 */
interface UnaryOverloader
{
    public function supportsOverloading(mixed $operand, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $operand, string $operator): Result;

    public function handles(string $operator): bool;

    /**
     * The certification contract of OperatorOverloader::typeOf(), one
     * operand: Ok(T) means every value of the operand type is handled and
     * the result inhabits T.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $operand): Result;
}
