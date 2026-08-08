<?php

declare(strict_types=1);

namespace Superscript\Axiom\Predicates;

/**
 * Conservative relations over propositional predicate structure.
 *
 * `false` means that this deliberately small proof calculus could not prove
 * the implication; it never means the implication has been disproved. The
 * calculus knows identity and the introduction/elimination laws of conjunction
 * and disjunction. It does not evaluate atoms or attempt general theorem proving.
 */
final readonly class PredicateRelations
{
    public static function implies(Predicate $antecedent, Predicate $consequent): bool
    {
        if ($antecedent->equals($consequent)) {
            return true;
        }

        if ($consequent instanceof AllOf) {
            return array_all(
                $consequent->members,
                static fn(Predicate $member): bool => self::implies($antecedent, $member),
            );
        }

        if ($antecedent instanceof AnyOf) {
            return array_all(
                $antecedent->members,
                static fn(Predicate $member): bool => self::implies($member, $consequent),
            );
        }

        if ($antecedent instanceof AllOf) {
            return array_any(
                $antecedent->members,
                static fn(Predicate $member): bool => self::implies($member, $consequent),
            );
        }

        if ($consequent instanceof AnyOf) {
            return array_any(
                $consequent->members,
                static fn(Predicate $member): bool => self::implies($antecedent, $member),
            );
        }

        return false;
    }
}
