<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Psl\Vec\map;

/**
 * A set of alternatives; an enum is a union of literals. A value inhabits
 * the union if it inhabits any member; members are tried in order.
 *
 * @implements Type<mixed>
 */
final readonly class UnionType implements Type
{
    /** @var non-empty-list<Type> */
    public array $members;

    public function __construct(Type ...$members)
    {
        Assert::minCount($members, 1);

        /** @var non-empty-list<Type> $members */
        $this->members = $members;
    }

    public function assert(mixed $value): Result
    {
        return $this->firstMember(fn(Type $member) => $member->assert($value), $value);
    }

    public function coerce(mixed $value): Result
    {
        return $this->firstMember(fn(Type $member) => $member->coerce($value), $value);
    }

    /**
     * @param callable(Type): Result<\Superscript\Monads\Option\Option<mixed>, \Throwable> $transform
     * @return Result<\Superscript\Monads\Option\Option<mixed>, \Throwable>
     */
    private function firstMember(callable $transform, mixed $value): Result
    {
        foreach ($this->members as $member) {
            $result = $transform($member);

            if ($result->isOk()) {
                return $result;
            }
        }

        return new Err(new TransformValueException(type: TypeDescriber::describe($this), value: $value));
    }

    public function format(mixed $value): string
    {
        foreach ($this->members as $member) {
            if ($member->assert($value)->isOk()) {
                return $member->format($value);
            }
        }

        return (new Exporter())->export($value);
    }

    public function shape(): Shape
    {
        return UnionShape::of(...map($this->members, fn(Type $member) => $member->shape()));
    }
}
