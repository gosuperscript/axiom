<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\SourceCompilation;
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
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Compiler for the core match-expression source. */
final readonly class MatchExpressionCompiler
{
    /**
     * The type of a match is the union of its arm types. Exhaustiveness is
     * mandatory: unprovable coverage is a compile error.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    public static function compile(MatchExpression $source, SourceCompilation $compilation): Result
    {
        $subject = $compilation->compile($source->subject);

        if ($subject->isErr()) {
            return Err(new TypeMismatch('The match subject cannot be typed.', [$subject->unwrapErr()]));
        }

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

            $pattern = self::compilePattern($arm->pattern, $compilation);

            if ($pattern->isErr()) {
                return Err(new TypeMismatch(sprintf('The pattern of match arm %d cannot be compiled.', $index), [$pattern->unwrapErr()]));
            }

            $body = $compilation->compile($arm->expression);

            if ($body->isErr()) {
                return Err(new TypeMismatch(sprintf('Match arm %d cannot be typed.', $index), [$body->unwrapErr()]));
            }

            $armTypes[] = $body->unwrap()->returns;
            $arms[] = [$pattern->unwrap(), $body->unwrap()];
        }

        $subjectNode = $subject->unwrap();

        if (!$wildcard && !self::covers($subjectNode->returns->shape(), $literals)) {
            return Err(new TypeMismatch(sprintf(
                'This match over %s may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.',
                TypeDescriber::describe($subjectNode->returns),
            )));
        }

        // Exhaustiveness implies at least one arm: a wildcard is an arm, and
        // zero literals cover no shape.
        return Ok(new CompiledNode(UnionType::join(...$armTypes), static function (Runtime $runtime) use ($subjectNode, $arms) {
            $result = $subjectNode->evaluate($runtime)->andThen(function (Option $subjectOption) use ($runtime, $arms) {
                $subjectValue = $subjectOption->unwrapOr(null);

                $runtime->annotate('subject', $subjectValue);

                foreach ($arms as $index => [$matches, $body]) {
                    $matched = $matches($subjectValue, $runtime);

                    if ($matched->isErr()) {
                        return $matched;
                    }

                    if (!$matched->unwrap()) {
                        continue;
                    }

                    $runtime->annotate('matched_arm', $index);

                    return $body->evaluate($runtime)
                        ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));
                }

                return Err(new RuntimeException('No match arm matched the subject; add a wildcard arm to handle unmatched values.'));
            });

            $runtime->annotate('label', 'match');

            return $result;
        }));
    }

    /**
     * A pattern compiles to a predicate over the subject value. Matching and
     * coverage analysis consume the same value-equality definition.
     *
     * @return Result<Closure(mixed, Runtime): Result<bool, Throwable>, TypeMismatch>
     */
    private static function compilePattern(MatchPattern $pattern, SourceCompilation $compilation): Result
    {
        if ($pattern instanceof WildcardPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(true));
        }

        if ($pattern instanceof LiteralPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(ValueEquality::equals($pattern->value, $subject)));
        }

        if ($pattern instanceof ExpressionPattern) {
            return $compilation->compile($pattern->source)->map(
                fn(CompiledNode $node) => static fn(mixed $subject, Runtime $runtime) => $node->evaluate($runtime)
                    ->map(fn(Option $option) => $option->unwrapOr(null) === $subject),
            );
        }

        return Err(new TypeMismatch(sprintf('No pattern rule exists for [%s].', get_class($pattern))));
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
