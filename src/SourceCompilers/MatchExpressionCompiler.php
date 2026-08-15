<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnionType;

use function Superscript\Monads\Result\Err;

/** @internal Compiler for the core match-expression source. */
final readonly class MatchExpressionCompiler
{
    /**
     * The type of a match is the union of its arm types. Exhaustiveness is
     * mandatory: unprovable coverage is a compile error.
     *
     * Both judgments are made over the parts that compiled: an arm that
     * failed is left out of the union, and a subject that failed is not put
     * to the coverage question at all. That keeps one fault to one
     * diagnostic — the match reports what is wrong with the match — and the
     * failure below is answered for where a type stops being a claim, by
     * {@see \Superscript\Axiom\Program}'s certification of the whole tree.
     */
    public static function compile(MatchExpression $source, SourceCompilation $compilation): CompiledSource
    {
        $subject = $compilation->within(
            'The match subject cannot be typed.',
            fn() => $compilation->child($source->subject, 'subject'),
        );

        $armTypes = [];
        $arms = [];
        $literals = [];
        $wildcard = false;

        foreach ($source->arms as $index => $arm) {
            if ($arm->pattern instanceof WildcardPattern) {
                $wildcard = true;
            }

            if ($arm->pattern instanceof LiteralPattern) {
                $literals[] = $arm->pattern->value;
            }

            $pattern = $compilation->within(
                sprintf('The pattern of match arm %d cannot be compiled.', $index),
                fn() => self::compilePattern($arm->pattern, $index, $compilation),
            );
            $body = $compilation->within(
                sprintf('Match arm %d cannot be typed.', $index),
                fn() => $compilation->child($arm->expression, "arm.{$index}.expression"),
            );

            // An arm that did not compile contributes no type. The match is
            // still typed, from the arms that did — an honest lower bound on
            // what it can produce — and the broken arm is answered for by
            // certification, which reads the whole tree and not the root
            // type.
            if (!$body->failed()) {
                $armTypes[] = $body->returns;
            }

            $arms[] = [$pattern, $body];
        }

        // A subject that did not compile promises no values, so any set of
        // patterns covers it and there is no exhaustiveness to judge;
        // refusing here would blame this match for the fault below it.
        if (!$wildcard && !$subject->failed() && !self::covers($subject->returns->shape(), $literals)) {
            $compilation->reject(new TypeMismatch(sprintf(
                'This match over %s may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.',
                TypeDescriber::describe($subject->returns),
            )));
        }

        // Exhaustiveness implies at least one arm: a wildcard is an arm, and
        // zero literals cover no shape.
        return $compilation->custom(UnionType::join(...$armTypes), static function (SourceEvaluation $evaluation) use ($subject, $arms) {
            try {
                $subjectValue = $evaluation->value($subject);
                $evaluation->annotate('subject', $subjectValue);

                foreach ($arms as $index => [$matches, $body]) {
                    if (!$matches($subjectValue, $evaluation)) {
                        continue;
                    }

                    $evaluation->annotate('matched_arm', $index);
                    $result = $evaluation->value($body);

                    if ($result !== null) {
                        $evaluation->annotate('result', $result);
                    }

                    return $result;
                }

                return Err(new RuntimeException('No match arm matched the subject; add a wildcard arm to handle unmatched values.'));
            } finally {
                $evaluation->annotate('label', 'match');
            }
        });
    }

    /**
     * A pattern compiles to a predicate over the subject value. Matching and
     * coverage analysis consume the same value-equality definition.
     *
     * The arm's index reaches here only to name the child an expression
     * pattern compiles. A role is how a caller tells one child from another,
     * so a name shared by every arm's pattern would name none of them.
     *
     * @return Closure(mixed, SourceEvaluation): bool
     */
    private static function compilePattern(
        MatchPattern $pattern,
        int $index,
        SourceCompilation $compilation,
    ): Closure {
        if ($pattern instanceof WildcardPattern) {
            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => true;
        }

        if ($pattern instanceof LiteralPattern) {
            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => ValueEquality::equals($pattern->value, $subject);
        }

        if ($pattern instanceof ExpressionPattern) {
            $compiled = $compilation->child($pattern->source, "arm.{$index}.pattern");

            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $evaluation->value($compiled) === $subject;
        }

        $compilation->reject(new TypeMismatch(sprintf('No pattern rule exists for [%s].', get_class($pattern))));
    }

    /** @param list<mixed> $literals */
    private static function covers(Shape $subject, array $literals): bool
    {
        // Never has no inhabitants, so any set of patterns covers it.
        if ($subject instanceof Shapes\NeverShape) {
            return true;
        }

        if ($subject instanceof OptionShape) {
            return in_array(null, $literals, strict: true) && self::covers($subject->inner, $literals);
        }

        if ($subject instanceof BooleanShape) {
            return in_array(true, $literals, strict: true) && in_array(false, $literals, strict: true);
        }

        if ($subject instanceof LiteralShape) {
            return array_any(
                $literals,
                fn(mixed $value) => (is_bool($value) || is_int($value) || is_float($value) || is_string($value))
                    && (new LiteralShape($value))->equals($subject),
            );
        }

        if ($subject instanceof UnionShape) {
            return array_all($subject->members, fn(Shape $member) => self::covers($member, $literals));
        }

        return false;
    }
}
