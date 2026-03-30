<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

final readonly class ExpressionMatcher implements PatternMatcher
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof ExpressionPattern;
    }

    /**
     * @param ExpressionPattern $pattern
     */
    public function matches(MatchPattern $pattern, mixed $subjectValue): Result
    {
        return $this->resolver->resolve($pattern->source)
            ->map(fn (Option $option) => $option->unwrapOr(null) === $subjectValue);
    }
}
