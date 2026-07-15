<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * A set of alternatives, canonicalized on construction:
 *
 * - nested unions flatten;
 * - Option members hoist (Union(Option<A>, B) becomes Option(Union(A, B)) —
 *   canonical unions never contain Option members);
 * - an Unknown member absorbs the union (nothing can be certified about it);
 * - Never members are eliminated (the union identity);
 * - members are deduplicated; order is insignificant.
 *
 * An empty union is Never; a single-member union is the member. Construct
 * exclusively through {@see UnionShape::of()}.
 */
final class UnionShape extends Shape
{
    /**
     * @param non-empty-list<Shape> $members
     */
    private function __construct(
        public readonly array $members,
    ) {}

    public static function of(Shape ...$members): Shape
    {
        $flat = [];
        $optional = false;
        $unknown = false;

        foreach ($members as $member) {
            if ($member instanceof OptionShape) {
                $optional = true;
                $member = $member->inner;
            }

            foreach ($member instanceof self ? $member->members : [$member] as $candidate) {
                if ($candidate instanceof UnknownShape) {
                    $unknown = true;
                } elseif (!$candidate instanceof NeverShape && !self::contains($flat, $candidate)) {
                    $flat[] = $candidate;
                }
            }
        }

        $base = match (true) {
            $unknown => new UnknownShape(),
            $flat === [] => new NeverShape(),
            count($flat) === 1 => $flat[0],
            default => new self($flat),
        };

        return $optional ? new OptionShape($base) : $base;
    }

    /**
     * @param list<Shape> $shapes
     */
    private static function contains(array $shapes, Shape $candidate): bool
    {
        return array_any($shapes, fn(Shape $shape) => $shape->equals($candidate));
    }

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || count($this->members) !== count($other->members)) {
            return false;
        }

        return array_all(
            $this->members,
            fn(Shape $member) => array_any($other->members, fn(Shape $candidate) => $member->equals($candidate)),
        );
    }
}
