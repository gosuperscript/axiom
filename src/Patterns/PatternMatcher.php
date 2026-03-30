<?php

declare(strict_types=1);

namespace Superscript\Axiom\Patterns;

use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Monads\Result\Result;

/**
 * Evaluates whether a subject value matches a given pattern.
 *
 * Core axiom ships matchers for WildcardPattern, LiteralPattern, and ExpressionPattern.
 * Extension packages (e.g. axiom-interval) register additional matchers for their
 * own pattern types (e.g. IntervalPattern).
 */
interface PatternMatcher
{
    /**
     * Does this matcher handle the given pattern type?
     */
    public function supports(MatchPattern $pattern): bool;

    /**
     * Evaluate whether the subject matches the pattern.
     *
     * @return Result<bool, \Throwable>
     */
    public function matches(MatchPattern $pattern, mixed $subjectValue): Result;
}
