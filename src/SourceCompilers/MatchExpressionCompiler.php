<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypePattern;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
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
        $patternShapes = [];
        $wildcard = false;
        $subjectReference = self::subjectReference($source->subject);

        foreach ($source->arms as $index => $arm) {
            if ($arm->pattern instanceof WildcardPattern) {
                $wildcard = true;
            }

            if ($arm->pattern instanceof LiteralPattern) {
                $literals[] = $arm->pattern->value;
            }

            if ($arm->pattern instanceof TypePattern) {
                $patternShapes[] = $arm->pattern->type->shape();

                // A type sharing no values with the subject is an arm that
                // can never match — an authoring mistake, not a fallback.
                if (!$subject->failed() && TypeRelations::overlaps($subject->returns, $arm->pattern->type)->isErr()) {
                    $compilation->reject(new TypeMismatch(sprintf(
                        'Match arm %d can never match: %s shares no values with the subject %s.',
                        $index,
                        TypeDescriber::describe($arm->pattern->type),
                        TypeDescriber::describe($subject->returns),
                    )));
                }
            }

            $pattern = $compilation->within(
                sprintf('The pattern of match arm %d cannot be compiled.', $index),
                fn() => self::compilePattern($arm->pattern, $index, $compilation),
            );
            $body = $compilation->within(
                sprintf('Match arm %d cannot be typed.', $index),
                // A type-pattern arm over a referenced subject knows the
                // member the value inhabits, so its body compiles with the
                // reference narrowed to it.
                fn() => $arm->pattern instanceof TypePattern && $subjectReference !== null
                    ? $compilation->narrowedChild($arm->expression, $subjectReference, $arm->pattern->type, "arm.{$index}.expression")
                    : $compilation->child($arm->expression, "arm.{$index}.expression"),
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
        if (!$wildcard && !$subject->failed() && !self::covers($subject->returns->shape(), $literals, $patternShapes)) {
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
     * A pattern compiles to a predicate over the subject value. Literal and
     * type patterns both match by inhabitation — the same judgment coverage
     * analysis reasons over — so a value can never match an arm its
     * compilation did not admit.
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
            $value = $pattern->value;

            // A scalar literal matches by inhabitation of its literal type —
            // total over any subject, where raw value equality is not: a
            // union can hold members (money, dates) whose equality belongs
            // to their own packages, and a literal arm beside them asks only
            // "is this that scalar?", never "are these two comparable?".
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $literal = new LiteralType($value);

                return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $literal->assert($subject)->isOk();
            }

            if ($value === null) {
                return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $subject === null;
            }

            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => ValueEquality::equals($value, $subject);
        }

        if ($pattern instanceof TypePattern) {
            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $pattern->type->assert($subject)->isOk();
        }

        if ($pattern instanceof ExpressionPattern) {
            $compiled = $compilation->child($pattern->source, "arm.{$index}.pattern");

            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $evaluation->value($compiled) === $subject;
        }

        $compilation->reject(new TypeMismatch(sprintf('No pattern rule exists for [%s].', get_class($pattern))));
    }

    /**
     * The reference a match narrows: the subject spelled as a path, when it
     * is one. Any other subject still matches and evaluates; its arms simply
     * compile without narrowing, because there is no symbol to retype.
     */
    private static function subjectReference(Source $subject): ?ReferencePath
    {
        return match (true) {
            $subject instanceof ReferencePath => $subject,
            $subject instanceof SymbolSource => $subject->reference(),
            default => null,
        };
    }

    /**
     * @param list<mixed> $literals
     * @param list<Shape> $patternShapes
     */
    private static function covers(Shape $subject, array $literals, array $patternShapes = []): bool
    {
        // A type pattern covers every subject assignable to it: the arm
        // admits all of the subject's values, so nothing can fall through.
        if (array_any($patternShapes, fn(Shape $pattern) => TypeRelations::assignable($subject, $pattern)->isOk())) {
            return true;
        }

        // Never has no inhabitants, so any set of patterns covers it.
        if ($subject instanceof Shapes\NeverShape) {
            return true;
        }

        if ($subject instanceof OptionShape) {
            return in_array(null, $literals, strict: true) && self::covers($subject->inner, $literals, $patternShapes);
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
            return array_all($subject->members, fn(Shape $member) => self::covers($member, $literals, $patternShapes));
        }

        return false;
    }
}
