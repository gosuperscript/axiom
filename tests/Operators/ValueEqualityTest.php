<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\ValueEquality;

#[CoversClass(ValueEquality::class)]
final class ValueEqualityTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function it_compares_by_value_never_by_juggling(mixed $left, mixed $right, bool $expected): void
    {
        $this->assertSame($expected, ValueEquality::equals($left, $right));
        $this->assertSame($expected, ValueEquality::equals($right, $left));
    }

    public static function cases(): \Generator
    {
        yield 'numbers compare numerically across int/float' => [1, 1.0, true];
        yield 'distinct numbers differ' => [1, 2, false];
        yield 'strings compare strictly' => ['a', 'a', true];
        yield 'numeric strings are not numbers' => [1, '1', false];
        yield 'numeric strings compare strictly to each other' => ['1e3', '1000', false];
        yield 'booleans are not numbers' => [true, 1, false];
        yield 'booleans compare strictly' => [true, true, true];
        yield 'null equals only null' => [null, null, true];
        yield 'null equals nothing else' => [null, 0, false];
        yield 'lists compare element-wise' => [['a', 1], ['a', 1.0], true];
        yield 'lists of different length differ' => [['a'], ['a', 'b'], false];
        yield 'nested lists recurse' => [['a', ['b']], ['a', ['c']], false];
        yield 'list keys matter' => [[0 => 'a'], [1 => 'a'], false];
        yield 'a list is not a scalar' => [['a'], 'a', false];
        yield 'element juggling is dead: true is not 1 inside lists' => [[true], [1], false];
    }

    #[Test]
    public function membership_is_value_equality(): void
    {
        $this->assertTrue(ValueEquality::contains([1, 2, 3], 2.0));
        $this->assertFalse(ValueEquality::contains([1, 2, 3], '2'));
        $this->assertFalse(ValueEquality::contains([1, 2], true));
        $this->assertFalse(ValueEquality::contains([], 'anything'));
        $this->assertTrue(ValueEquality::contains(['a', 'b'], 'a'));
    }
}
