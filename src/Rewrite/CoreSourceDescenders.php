<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\DefaultValue;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;

/**
 * The language's descent registry: for each core node, how to reach its
 * children and how to put the node back together around rewritten ones.
 *
 * The core node set is closed, so this map is exhaustive by law rather than
 * by habit — `CoreDescentExhaustivenessTest` reflects over `src/Sources` and
 * fails the moment a node class exists without an arm. Without that law a new
 * node would quietly become an opaque leaf and every rewrite beneath it would
 * silently stop happening.
 *
 * Every arm shares structure: a node whose children all came back identical
 * is returned as-is rather than reconstructed, so an untouched subtree is the
 * same instance in the new tree and `===` answers "did anything change?" for
 * the whole run.
 *
 * Patterns get their own map because they are not sources: they cannot be
 * rewritten by a rule and cannot be extended by a host — the match compiler
 * refuses a pattern it does not know — but an expression pattern holds a
 * source, and a rewrite inside one is an ordinary rewrite.
 */
final readonly class CoreSourceDescenders
{
    /**
     * @return array<class-string<Source>, callable(Source, Descent): Source>
     */
    public static function sources(): array
    {
        /** @var array<class-string<Source>, callable(Source, Descent): Source> */
        return [
            StaticSource::class => self::leaf(...),
            SymbolSource::class => self::leaf(...),
            Coerce::class => self::coerce(...),
            Ascription::class => self::ascription(...),
            DefaultValue::class => self::defaultValue(...),
            MemberAccessSource::class => self::memberAccess(...),
            UnaryExpression::class => self::unary(...),
            InfixExpression::class => self::infix(...),
            MatchExpression::class => self::matchExpression(...),
        ];
    }

    /**
     * @return array<class-string<MatchPattern>, callable(MatchPattern, Descent): MatchPattern>
     */
    public static function patterns(): array
    {
        /** @var array<class-string<MatchPattern>, callable(MatchPattern, Descent): MatchPattern> */
        return [
            WildcardPattern::class => self::leafPattern(...),
            LiteralPattern::class => self::leafPattern(...),
            ExpressionPattern::class => self::expressionPattern(...),
        ];
    }

    /** A node with no source children: there is nothing under it to rewrite. */
    private static function leaf(Source $source, Descent $descent): Source
    {
        return $source;
    }

    private static function leafPattern(MatchPattern $pattern, Descent $descent): MatchPattern
    {
        return $pattern;
    }

    private static function coerce(Coerce $source, Descent $descent): Source
    {
        $inner = $descent->child($source->source, 'source');

        return $inner === $source->source ? $source : new Coerce($source->type, $inner);
    }

    private static function ascription(Ascription $source, Descent $descent): Source
    {
        $inner = $descent->child($source->source, 'source');

        return $inner === $source->source ? $source : new Ascription($source->type, $inner);
    }

    private static function defaultValue(DefaultValue $source, Descent $descent): Source
    {
        $inner = $descent->child($source->source, 'source');

        return $inner === $source->source ? $source : new DefaultValue($inner, $source->default);
    }

    private static function memberAccess(MemberAccessSource $source, Descent $descent): Source
    {
        $object = $descent->child($source->object, 'object');

        return $object === $source->object ? $source : new MemberAccessSource($object, $source->property);
    }

    private static function unary(UnaryExpression $source, Descent $descent): Source
    {
        $operand = $descent->child($source->operand, 'operand');

        return $operand === $source->operand ? $source : new UnaryExpression($source->operator, $operand);
    }

    private static function infix(InfixExpression $source, Descent $descent): Source
    {
        $left = $descent->child($source->left, 'left');
        $right = $descent->child($source->right, 'right');

        return $left === $source->left && $right === $source->right
            ? $source
            : new InfixExpression($left, $source->operator, $right);
    }

    private static function matchExpression(MatchExpression $source, Descent $descent): Source
    {
        $subject = $descent->child($source->subject, 'subject');
        $arms = [];
        $moved = $subject !== $source->subject;

        foreach ($source->arms as $index => $arm) {
            $rewritten = $descent->arm($arm, sprintf('arms[%d]', $index));
            $moved = $moved || $rewritten !== $arm;
            $arms[] = $rewritten;
        }

        return $moved ? new MatchExpression($subject, $arms) : $source;
    }

    private static function expressionPattern(ExpressionPattern $pattern, Descent $descent): MatchPattern
    {
        $inner = $descent->child($pattern->source, 'source');

        return $inner === $pattern->source ? $pattern : new ExpressionPattern($inner);
    }
}
