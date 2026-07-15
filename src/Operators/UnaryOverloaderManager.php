<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Superscript\Monads\Result\Err;

/**
 * The composed unary dialect, resolved exactly like the binary one: one
 * owner per operand type, ambiguity refused, unhandled refusals kept out
 * of the diagnostics.
 */
class UnaryOverloaderManager implements UnaryOverloader
{
    public function __construct(
        /** @var list<UnaryOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, UnaryOverloader::class);
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $operand): Result
    {
        $resolutions = [];
        $owners = [];
        $engaged = [];

        foreach ($this->overloaders as $overloader) {
            $result = $overloader->resolve($operator, $operand);

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
                'Unary operator [%s] over %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand type.',
                $operator,
                TypeDescriber::describe($operand),
                implode('], [', $owners),
            )));
        }

        if ($resolutions !== []) {
            return $resolutions[0];
        }

        if ($engaged === []) {
            return Err(new TypeMismatch(sprintf('Unary operator [%s] is not supported.', $operator), unhandled: true));
        }

        return count($engaged) === 1
            ? Err($engaged[0])
            : Err(new TypeMismatch(
                sprintf('No overload of unary [%s] accepts %s.', $operator, TypeDescriber::describe($operand)),
                $engaged,
            ));
    }
}
