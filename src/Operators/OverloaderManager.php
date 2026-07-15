<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The composed binary dialect: one list of rules, resolved once per
 * operator node at compile time.
 *
 * Resolution across the list:
 * - exactly one rule resolves → that resolution, bound into the program;
 * - two or more resolve → a compile error naming the competing rules —
 *   with dispatch on types, multiple owners for the same operand types is
 *   a miscomposed dialect, never a precedence question (list order decides
 *   nothing);
 * - none resolve → the lone engaged refusal directly, or an aggregate of
 *   the refusals that said something about the operand types (`unhandled`
 *   refusals — rules the operator simply isn't for — stay out of the
 *   diagnostics).
 */
class OverloaderManager implements OperatorOverloader
{
    public function __construct(
        /** @var list<OperatorOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, OperatorOverloader::class);
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        $resolutions = [];
        $owners = [];
        $engaged = [];

        foreach ($this->overloaders as $overloader) {
            $result = $overloader->resolve($operator, $left, $right);

            if ($result->isOk()) {
                $resolutions[] = $result;
                $owners[] = $overloader::class;

                continue;
            }

            $mismatch = $result->unwrapErr();

            if (!$mismatch->unhandled) {
                $engaged[] = $mismatch;
            }
        }

        if (count($resolutions) > 1) {
            return Err(new TypeMismatch(sprintf(
                'Operator [%s] over %s and %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand types.',
                $operator,
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
                implode('], [', $owners),
            )));
        }

        if ($resolutions !== []) {
            return $resolutions[0];
        }

        if ($engaged === []) {
            return Err(new TypeMismatch(sprintf('Operator [%s] is not supported.', $operator), unhandled: true));
        }

        return count($engaged) === 1
            ? Err($engaged[0])
            : Err(new TypeMismatch(
                sprintf('No overload of [%s] accepts %s and %s.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                $engaged,
            ));
    }
}
