<?php

declare(strict_types=1);

namespace Superscript\Axiom\Predicates;

use Closure;
use InvalidArgumentException;
use Superscript\Axiom\Source;

/** A disjunction, canonicalized by flattening and deduplicating its members. */
final readonly class AnyOf extends Predicate
{
    /**
     * @param non-empty-list<Predicate> $members
     */
    private function __construct(public array $members) {}

    public static function of(Predicate ...$members): Predicate
    {
        if ($members === []) {
            throw new InvalidArgumentException('A disjunction requires at least one predicate.');
        }

        $canonical = [];

        foreach ($members as $member) {
            foreach ($member instanceof self ? $member->members : [$member] as $candidate) {
                if (!self::contains($canonical, $candidate)) {
                    $canonical[] = $candidate;
                }
            }
        }

        /** @var non-empty-list<Predicate> $canonical */

        return count($canonical) === 1 ? $canonical[0] : new self($canonical);
    }

    public function equals(Predicate $other): bool
    {
        if (!$other instanceof self || count($this->members) !== count($other->members)) {
            return false;
        }

        return array_all(
            $this->members,
            static fn(Predicate $member): bool => self::contains($other->members, $member),
        );
    }

    public function partiallyEvaluate(Closure $evaluateAtom): bool|Predicate
    {
        $remaining = [];

        foreach ($this->members as $member) {
            $evaluation = $member->partiallyEvaluate($evaluateAtom);

            if ($evaluation === true) {
                return true;
            }

            if ($evaluation !== false) {
                $remaining[] = $evaluation;
            }
        }

        return $remaining === [] ? false : self::of(...$remaining);
    }

    public function toSource(): Source
    {
        return self::sourceChain($this->members, '||');
    }

    /**
     * @param list<Predicate> $predicates
     */
    private static function contains(array $predicates, Predicate $candidate): bool
    {
        return array_any($predicates, static fn(Predicate $predicate): bool => $predicate->equals($candidate));
    }
}
