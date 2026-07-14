<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\NullOverloader;

#[CoversClass(DefaultOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[CoversClass(BinaryOverloader::class)]
#[CoversClass(ComparisonOverloader::class)]
#[CoversClass(HasOverloader::class)]
#[CoversClass(InOverloader::class)]
#[CoversClass(LogicalOverloader::class)]
#[CoversClass(IntersectsOverloader::class)]
#[CoversClass(NullOverloader::class)]
class DefaultOverloaderTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function it_evaluates(mixed $left, string $operator, mixed $right, mixed $expected): void
    {
        $overloader = new DefaultOverloader();
        $this->assertTrue($overloader->supportsOverloading(left: $left, right: $right, operator: $operator));
        $this->assertEquals($expected, $overloader->evaluate(left: $left, right: $right, operator: $operator)->unwrap());
    }

    public static function cases(): Generator
    {
        yield [1, '+', 2, 3];
        yield [3, '-', 2, 1];
        yield [2, '*', 3, 6];

        yield [6, '/', 3, 2];

        yield [1, '>', 2, false];
        yield [2, '>', 2, false];
        yield [3, '>', 2, true];

        yield [1, '>=', 2, false];
        yield [2, '>=', 2, true];
        yield [3, '>=', 2, true];

        yield [1, '<', 2, true];
        yield [2, '<', 2, false];
        yield [3, '<', 2, false];

        yield [1, '<=', 2, true];
        yield [2, '<=', 2, true];
        yield [3, '<=', 2, false];

        yield [1, '<', 2.5, true];
        yield [2.5, '>', 1, true];

        yield [1, '=', 1, true];
        yield [1, '=', 2, false];
        yield [1, '!=', 2, true];
        yield [1, '!=', 1, false];

        // Equality is value equality, never PHP juggling: numeric within
        // Number (1 == 1.0), false across bases (1 is not '1').
        yield [1, '==', '1', false];
        yield [1, '!=', '1', true];
        yield [1, '==', 1.0, true];
        yield [1, '===', 1.0, true];
        yield [true, '==', 1, false];

        yield [1, '===', 1, true];
        yield [1, '===', '1', false];
        yield [1, '!==', 2, true];
        yield [1, '!==', '1', true];

        yield [null, '==', null, true];
        yield [null, '==', 'a', false];
        yield ['a', '!=', null, true];
        yield ['a', '==', 'a', true];
        yield [['a', 'b'], '==', ['a', 'b'], true];
        yield [['a', ['b']], '==', ['a', ['c']], false];

        yield [['a', 'b'], 'has', 'a', true];
        yield [['a', 'b'], 'has', 'c', false];
        yield [['a', 'b'], 'has', ['a', 'b'], true];
        yield [['a', 'b'], 'has', ['a', 'c'], false];
        yield [['a', 'b', 'c'], 'has', ['a', 'c'], true];
        yield [['a', 'b'], 'has', null, false];
        yield [['a', 'b'], 'has', [null], false];
        yield [[null, 'a'], 'has', 'a', true];
        yield [[null], 'has', 'a', false];
        yield [[null], 'has', '', false];
        yield [[''], 'has', null, false];

        yield ['a', 'in', ['a', 'b'], true];
        yield ['c', 'in', ['a', 'b'], false];
        yield [['a', 'b'], 'in', ['a', 'b'], true];
        yield [['a', 'b'], 'in', ['a', 'c'], false];
        yield [['a', 'c'], 'in', ['a', 'b', 'c'], true];
        yield [['a', 'b', 'c'], 'in', ['a', 'c'], false];
        yield [['a', 'b', 'd'], 'in', ['a', 'b', 'c'], false];
        yield [null, 'in', ['a', 'b'], false];
        yield [[null], 'in', ['a', 'b'], false];
        yield [[null, 'a'], 'in', ['a', 'b'], true];
        yield ['', 'in', [null, 'a'], false];

        yield [true, '&&', true, true];
        yield [true, '&&', false, false];
        yield [false, '&&', true, false];
        yield [false, '&&', false, false];
        yield [true, '||', true, true];
        yield [true, '||', false, true];
        yield [false, '||', true, true];
        yield [false, '||', false, false];
        yield [true, 'xor', true, false];
        yield [true, 'xor', false, true];

        yield ['a', 'intersects', ['a', 'b'], true];
        yield ['c', 'intersects', ['a', 'b'], false];
        yield [['a', 'b'], 'intersects', ['a'], true];
        yield [['a', 'b'], 'intersects', ['c'], false];
        yield [['a', 'b'], 'intersects', ['a', 'c'], true];
        yield [['a', 'b'], 'intersects', ['c', 'd'], false];
        yield ['a', 'intersects', 'a', true];
        yield ['a', 'intersects', 'b', false];
        yield [null, 'intersects', ['a', 'b'], false];
        yield [['a', 'b'], 'intersects', null, false];
        yield [null, 'intersects', null, false];
        yield [[null, 'a'], 'intersects', ['a', 'b'], true];
        yield [[null], 'intersects', ['a'], false];
        yield [[null], 'intersects', [''], false];
        yield [[''], 'intersects', [null], false];

        yield [null, '+', null, null];
        yield [null, '-', null, null];
        yield [null, '*', null, null];
        yield [null, '/', null, null];
    }

    #[Test]
    public function it_does_not_support_objects(): void
    {
        $overloader = new DefaultOverloader();
        $this->assertFalse($overloader->supportsOverloading(new stdClass(), new stdClass(), '+'));
        $this->assertFalse($overloader->supportsOverloading(1, new stdClass(), '+'));
        $this->assertFalse($overloader->supportsOverloading(new stdClass(), 1, '+'));
        $this->assertFalse($overloader->supportsOverloading(new stdClass(), null, '+'));
    }

    /**
     * Rules must claim only values they own: no rule may shadow a dialect's
     * domain rules by claiming values it would evaluate as PHP garbage.
     */
    #[Test]
    #[DataProvider('dishonestClaims')]
    public function it_refuses_values_no_rule_owns(mixed $left, string $operator, mixed $right): void
    {
        $overloader = new DefaultOverloader();
        $this->assertFalse($overloader->supportsOverloading(left: $left, right: $right, operator: $operator));
    }

    public static function dishonestClaims(): Generator
    {
        // Equality on objects belongs to the overloader that owns the type.
        yield [new stdClass(), '==', new stdClass()];
        yield [new stdClass(), '===', new stdClass()];
        yield [1, '!=', new stdClass()];
        yield [[new stdClass()], '==', []];

        // Ordering is defined for numbers only.
        yield ['a', '<', 'b'];
        yield ['2024-01-01', '<', '2024-06-01'];
        yield [true, '<', false];
        yield ['5', '<', 6];
        yield [1, '<', '2'];
        yield [null, '<', 1];
        yield [1, '<', null];
        yield [new stdClass(), '>', new stdClass()];

        // Arithmetic requires real numbers, not numeric strings.
        yield ['5', '+', '3'];
        yield ['5', '+', 3];
        yield [5, '*', '3'];

        // Set operators require scalar/null/list operands.
        yield [['a'], 'has', new stdClass()];
        yield [new stdClass(), 'has', 'a'];
        yield [new stdClass(), 'in', ['a']];
        yield [['a'], 'in', new stdClass()];
        yield [new stdClass(), 'intersects', ['a']];
        yield [['a'], 'intersects', new stdClass()];
    }

    #[Test]
    public function it_composes_the_typed_dialect(): void
    {
        $overloader = new DefaultOverloader();

        $this->assertTrue($overloader->handles('+'));
        $this->assertTrue($overloader->handles('has'));
        $this->assertFalse($overloader->handles('coalesce'));

        $number = new \Superscript\Axiom\Types\NumberType();
        $verdict = $overloader->typeOf('+', $number, $number);
        $this->assertInstanceOf(\Superscript\Axiom\Types\NumberType::class, $verdict->unwrap());

        $refused = $overloader->typeOf('&&', $number, $number);
        $this->assertTrue($refused->isErr());
    }

    #[Test]
    public function it_returns_error_for_unsupported_operators(): void
    {
        $result = (new DefaultOverloader())->evaluate(1, 1, 'foo');
        $this->assertTrue($result->isErr());
        $this->assertEquals('Operator [foo] is not supported.', $result->unwrapErr()->getMessage());
    }
}
