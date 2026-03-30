<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<MatchExpression>
 */
final readonly class MatchResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        private ?ResolutionInspector $inspector = null,
    ) {}

    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', 'match');

        return $this->resolver->resolve($source->subject)
            ->andThen(function (Option $subjectOption) use ($source) {
                $subjectValue = $subjectOption->unwrapOr(null);
                $this->inspector?->annotate('subject', $subjectValue);

                foreach ($source->arms as $index => $arm) {
                    $matched = $this->matches($arm->pattern, $subjectValue);

                    if ($matched instanceof Result) {
                        /** @var Result<bool, \Throwable> $matched */
                        $matchResult = $matched;
                        $didMatch = false;
                        $error = null;

                        $matchResult
                            ->inspect(function (bool $v) use (&$didMatch) {
                                $didMatch = $v;
                            })
                            ->inspectErr(function (\Throwable $e) use (&$error) {
                                $error = $e;
                            });

                        if ($error !== null) {
                            return \Superscript\Monads\Result\Err($error);
                        }

                        if (! $didMatch) {
                            continue;
                        }
                    } elseif (! $matched) {
                        continue;
                    }

                    $this->inspector?->annotate('matched_arm', $index);

                    return $this->resolver->resolve($arm->expression)
                        ->inspect(fn (Option $option) => $option->inspect(
                            fn (mixed $value) => $this->inspector?->annotate('result', $value),
                        ));
                }

                return Ok(None());
            });
    }

    /**
     * @return bool|Result<bool, \Throwable>
     */
    private function matches(mixed $pattern, mixed $subjectValue): bool|Result
    {
        return match (true) {
            $pattern instanceof WildcardPattern => true,
            $pattern instanceof LiteralPattern => $pattern->value === $subjectValue,
            $pattern instanceof ExpressionPattern => $this->matchesExpression($pattern, $subjectValue),
            default => false,
        };
    }

    /**
     * @return Result<bool, \Throwable>
     */
    private function matchesExpression(ExpressionPattern $pattern, mixed $subjectValue): Result
    {
        return $this->resolver->resolve($pattern->source)
            ->map(fn (Option $option) => $option->unwrapOr(null) === $subjectValue);
    }
}
