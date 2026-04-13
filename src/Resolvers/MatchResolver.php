<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use RuntimeException;
use Superscript\Axiom\Context;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<MatchExpression>
 */
final readonly class MatchResolver implements Resolver
{
    /**
     * @param list<PatternMatcher> $matchers
     */
    public function __construct(
        public Resolver $resolver,
        private array $matchers,
    ) {}

    public function resolve(Source $source, Context $context): Result
    {
        $result = $this->resolver->resolve($source->subject, $context)
            ->andThen(fn(Option $subjectOption) => $this->evaluateArms(
                $subjectOption->unwrapOr(null),
                $source->arms,
                $context,
            ));

        $context->inspector?->annotate('label', 'match');

        return $result;
    }

    /**
     * @param list<MatchArm> $arms
     * @return Result<Option<mixed>, \Throwable>
     */
    private function evaluateArms(mixed $subjectValue, array $arms, Context $context): Result
    {
        $context->inspector?->annotate('subject', $subjectValue);

        foreach ($arms as $index => $arm) {
            $matchResult = $this->matchPattern($arm->pattern, $subjectValue, $context);

            if ($matchResult->isErr()) {
                return $matchResult;
            }

            if (! $matchResult->unwrap()) {
                continue;
            }

            $context->inspector?->annotate('matched_arm', $index);

            return $this->resolver->resolve($arm->expression, $context)
                ->inspect(fn(Option $option) => $option->inspect(
                    fn(mixed $value) => $context->inspector?->annotate('result', $value),
                ));
        }

        return Ok(None());
    }

    /**
     * @return Result<bool, \Throwable>
     */
    private function matchPattern(MatchPattern $pattern, mixed $subjectValue, Context $context): Result
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->supports($pattern)) {
                return $matcher->matches($pattern, $subjectValue, $context);
            }
        }

        return Err(new RuntimeException('No matcher found for pattern type: ' . get_class($pattern)));
    }
}
