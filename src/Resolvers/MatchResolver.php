<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use RuntimeException;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\ResolutionInspector;
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
        private ?ResolutionInspector $inspector = null,
    ) {}

    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', 'match');

        return $this->resolver->resolve($source->subject)
            ->andThen(fn (Option $subjectOption) => $this->evaluateArms(
                $subjectOption->unwrapOr(null),
                $source->arms,
            ));
    }

    /**
     * @param list<MatchArm> $arms
     * @return Result<Option<mixed>, \Throwable>
     */
    private function evaluateArms(mixed $subjectValue, array $arms): Result
    {
        $this->inspector?->annotate('subject', $subjectValue);

        foreach ($arms as $index => $arm) {
            $matchResult = $this->matchPattern($arm->pattern, $subjectValue);

            if ($matchResult->isErr()) {
                return $matchResult;
            }

            if (! $matchResult->unwrap()) {
                continue;
            }

            $this->inspector?->annotate('matched_arm', $index);

            return $this->resolver->resolve($arm->expression)
                ->inspect(fn (Option $option) => $option->inspect(
                    fn (mixed $value) => $this->inspector?->annotate('result', $value),
                ));
        }

        return Ok(None());
    }

    /**
     * @return Result<bool, \Throwable>
     */
    private function matchPattern(MatchPattern $pattern, mixed $subjectValue): Result
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->supports($pattern)) {
                return $matcher->matches($pattern, $subjectValue);
            }
        }

        return Err(new RuntimeException('No matcher found for pattern type: ' . get_class($pattern)));
    }
}
