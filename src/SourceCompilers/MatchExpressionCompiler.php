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
use Superscript\Axiom\Types\Type;
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
        $claims = [];
        $wildcard = false;
        $subjectReference = self::subjectReference($source->subject);

        foreach ($source->arms as $index => $arm) {
            if ($arm->pattern instanceof WildcardPattern) {
                $wildcard = true;
            }

            // Every judgment about an arm reads its one claim: the predicate
            // asks whether the subject inhabits it, coverage asks whether the
            // claims exhaust the subject, and liveness asks whether it can
            // hold at all.
            $claim = self::claim($arm->pattern, $compilation);

            if ($claim !== null) {
                $claims[] = $claim->shape();

                // A claim sharing no values with the subject is an arm that
                // can never match — an authoring mistake, not a fallback.
                if (!$subject->failed()) {
                    $overlap = $compilation->overlaps($compilation->typeOf($subject), $claim);

                    if ($overlap->isErr()) {
                        $compilation->reject(new TypeMismatch(sprintf(
                            'Match arm %d can never match: %s shares no values with the subject %s.',
                            $index,
                            TypeDescriber::describe($claim),
                            TypeDescriber::describe($subject->returns),
                        ), [$overlap->unwrapErr()]));
                    }
                }
            }

            $pattern = $compilation->within(
                sprintf('The pattern of match arm %d cannot be compiled.', $index),
                fn() => self::compilePattern($arm->pattern, $claim, $index, $compilation),
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
        if (!$wildcard && !$subject->failed() && !self::covers($subject->returns->shape(), $claims)) {
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
     * The type an arm claims its subject inhabits — the one judgment the
     * predicate, coverage, and liveness all read, so they can never
     * disagree. A type pattern claims its type; a scalar or null literal
     * claims the type the compiler's own literal inference gives that value
     * (`5` claims `Literal(5)`, `null` claims `Option<Never>` — absence
     * only). Wildcards claim nothing by design, and so do expression
     * patterns and array literals, whose matching is value comparison
     * rather than membership.
     */
    private static function claim(MatchPattern $pattern, SourceCompilation $compilation): ?Type
    {
        return match (true) {
            $pattern instanceof TypePattern => $pattern->type,
            $pattern instanceof LiteralPattern && ($pattern->value === null || is_scalar($pattern->value))
                => $compilation->typeOfValue($pattern->value),
            default => null,
        };
    }

    /**
     * A pattern compiles to a predicate over the subject value. An arm with
     * a claim matches by inhabitation of that claim — total over any
     * subject, where raw value equality is not: a union can hold members
     * (money, dates) whose equality belongs to their own packages, and a
     * literal arm beside them asks only "is this that scalar?", never "are
     * these two comparable?". Array literals keep entry-wise value
     * equality.
     *
     * The arm's index reaches here only to name the child an expression
     * pattern compiles. A role is how a caller tells one child from another,
     * so a name shared by every arm's pattern would name none of them.
     *
     * @return Closure(mixed, SourceEvaluation): bool
     */
    private static function compilePattern(
        MatchPattern $pattern,
        ?Type $claim,
        int $index,
        SourceCompilation $compilation,
    ): Closure {
        if ($claim !== null) {
            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => $claim->assert($subject)->isOk();
        }

        if ($pattern instanceof WildcardPattern) {
            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => true;
        }

        if ($pattern instanceof LiteralPattern) {
            $value = $pattern->value;

            return static fn(mixed $subject, SourceEvaluation $evaluation): bool => ValueEquality::equals($value, $subject);
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
     * Is every value of the subject claimed by some arm? One subset
     * question — is this shape assignable to a claim? — asked directly of
     * the subject, and of each component when the subject decomposes: a
     * union member by member, an option as its null component ({null} is
     * Option<Never>) beside its inner, a boolean as its two literals.
     *
     * @param list<Shape> $claims
     */
    private static function covers(Shape $subject, array $claims): bool
    {
        if (self::claimed($subject, $claims)) {
            return true;
        }

        // Never has no inhabitants, so any set of patterns covers it.
        if ($subject instanceof Shapes\NeverShape) {
            return true;
        }

        if ($subject instanceof OptionShape) {
            return self::claimed(new OptionShape(new Shapes\NeverShape()), $claims)
                && self::covers($subject->inner, $claims);
        }

        if ($subject instanceof BooleanShape) {
            return self::covers(new LiteralShape(true), $claims) && self::covers(new LiteralShape(false), $claims);
        }

        if ($subject instanceof UnionShape) {
            return array_all($subject->members, fn(Shape $member) => self::covers($member, $claims));
        }

        return false;
    }

    /** @param list<Shape> $claims */
    private static function claimed(Shape $subject, array $claims): bool
    {
        return array_any($claims, fn(Shape $claim) => TypeRelations::assignable($subject, $claim)->isOk());
    }
}
