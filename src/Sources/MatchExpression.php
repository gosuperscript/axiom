<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;

final readonly class MatchExpression implements Source
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Source $subject,
        public array $arms,
    ) {}

    public function type(Context $context): Type
    {
        if ($this->arms === []) {
            return new UnresolvedType('match expression has no arms');
        }

        $subject = $this->subject->type($context);
        if ($subject instanceof UnresolvedType) {
            return $subject;
        }

        $result = null;
        foreach ($this->arms as $index => $arm) {
            // Patterns that carry a Source must type-check against the subject.
            if ($arm->pattern instanceof ExpressionPattern) {
                $patternType = $arm->pattern->source->type($context);
                if ($patternType instanceof UnresolvedType) {
                    return $patternType;
                }
            }

            $armType = $arm->expression->type($context);
            if ($armType instanceof UnresolvedType) {
                return $armType;
            }

            if ($result === null) {
                $result = $armType;
                continue;
            }

            if (! $result->accepts($armType) && ! $armType->accepts($result)) {
                return new UnresolvedType(
                    "match arm #{$index} has type " . $armType->name()
                    . " which is not compatible with " . $result->name(),
                );
            }
        }

        return $result ?? new UnresolvedType('match expression has no arms');
    }
}
