<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\TypeCheckException;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;

/**
 * Statically type-checks a {@see Source} tree against the declarations
 * available in a {@see Context} (parameter schema on {@see Bindings} and
 * named sources in {@see Definitions}).
 *
 * Returns Ok(Type) if the expression resolves to a concrete type, or
 * Err({@see TypeCheckException}) pointing at the first node that cannot
 * be typed.
 */
final class TypeChecker
{
    /**
     * @return Result<Type, TypeCheckException>
     */
    public static function check(Source $source, Context $context): Result
    {
        try {
            $type = $source->type($context);
        } catch (TypeCheckException $e) {
            return new Err($e);
        }

        if ($type instanceof UnresolvedType) {
            return new Err(new TypeCheckException(
                self::locate($source, $context) . ': ' . $type->reason,
                $source,
            ));
        }

        return new Ok($type);
    }

    /**
     * Short label describing where in the tree we are — best-effort, only
     * rendered inside error messages.
     */
    private static function locate(Source $source, Context $context): string
    {
        return match (true) {
            $source instanceof InfixExpression => 'infix ' . self::operandLabel($source->left, $context) . ' ' . $source->operator . ' ' . self::operandLabel($source->right, $context),
            $source instanceof UnaryExpression => "unary '{$source->operator}'",
            $source instanceof MemberAccessSource => "member access '.{$source->property}'",
            $source instanceof MatchExpression => 'match',
            default => 'expression',
        };
    }

    private static function operandLabel(Source $source, Context $context): string
    {
        try {
            $type = $source->type($context);
            return $type->name();
        } catch (\Throwable) {
            return '?';
        }
    }
}
