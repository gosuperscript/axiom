<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

use Closure;

/**
 * An insert-only set of shapes under a caller-chosen sameness judgment,
 * kept linear for enum-sized memberships: literal members are found through
 * a {@see LiteralShape::valueKey()} index — the key only nominates a bucket,
 * the judgment still decides — while every other member is scanned pairwise,
 * which stays cheap because only literals arrive in bulk. The two groups are
 * judged apart because they can never be the same shape: every Shape::equals
 * is class-strict, and a literal's only two-way assignability is an equal
 * literal, so both judgments in use agree the groups are disjoint.
 *
 * @internal
 */
final class DistinctShapes
{
    /** @var list<Shape> */
    private array $shapes = [];

    /** @var array<string, non-empty-list<int>> */
    private array $literals = [];

    /** @var list<int> */
    private array $others = [];

    /**
     * @param Closure(Shape, Shape): bool $same The sameness judgment. It must
     *        agree with {@see Shape::equals()} on pairs of literals: a literal's
     *        candidates come from its valueKey bucket, and buckets group by
     *        value equality, so a judgment that splits value-equal literals
     *        would hide candidates from itself. Both judgments in use —
     *        structural equality and two-way assignability — coincide with
     *        value equality on literal pairs.
     */
    public function __construct(
        private readonly Closure $same,
    ) {}

    /** Enters the shape unless one the judgment calls the same is already in; answers whether it entered. */
    public function add(Shape $shape): bool
    {
        $key = $shape instanceof LiteralShape ? $shape->valueKey() : null;
        $candidates = $key === null ? $this->others : $this->literals[$key] ?? [];

        if (array_any($candidates, fn(int $index) => ($this->same)($this->shapes[$index], $shape))) {
            return false;
        }

        if ($key === null) {
            $this->others[] = count($this->shapes);
        } else {
            $this->literals[$key][] = count($this->shapes);
        }

        $this->shapes[] = $shape;

        return true;
    }

    /**
     * The distinct shapes, in insertion order.
     *
     * @return list<Shape>
     */
    public function all(): array
    {
        return $this->shapes;
    }
}
