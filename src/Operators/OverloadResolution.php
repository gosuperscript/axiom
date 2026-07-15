<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * The composition rule shared by the binary and unary managers: resolve
 * the operator against every rule in the list, then reconcile —
 *
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
final class OverloadResolution
{
    /**
     * @template T of object
     * @param list<T> $overloaders
     * @param Closure(T): Result<ResolvedOperation, TypeMismatch> $resolve
     * @param Closure(list<class-string>): string $ambiguity
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public static function across(array $overloaders, Closure $resolve, Closure $ambiguity, string $unsupported, string $unaccepted): Result
    {
        $resolutions = [];
        $owners = [];
        $engaged = [];

        foreach ($overloaders as $overloader) {
            $result = $resolve($overloader);

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
            return Err(new TypeMismatch($ambiguity($owners)));
        }

        if ($resolutions !== []) {
            return $resolutions[0];
        }

        if ($engaged === []) {
            return Err(new TypeMismatch($unsupported, unhandled: true));
        }

        return count($engaged) === 1
            ? Err($engaged[0])
            : Err(new TypeMismatch($unaccepted, $engaged));
    }
}
