<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\EqualityOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\SetOperands;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The one question every rule answers: resolve(operator, operand types) —
 * certification carries the return type, refusal carries the diagnosis.
 * Rows (arithmetic, ordering, logic) are exercised through the composed
 * core dialect; the type functions (equality, membership, intersection)
 * also directly.
 */
#[CoversClass(EqualityOverloader::class)]
#[CoversClass(HasOverloader::class)]
#[CoversClass(InOverloader::class)]
#[CoversClass(IntersectsOverloader::class)]
#[CoversClass(SetOperands::class)]
#[CoversClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloadResolution::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(DictType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\DictShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ListShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class ResolveTest extends TestCase
{
    private static function core(): OperatorOverloader
    {
        return Dialect::core()->operators();
    }

    private static function opaque(): Type
    {
        return new class implements Type {
            public function assert(mixed $value): \Superscript\Monads\Result\Result
            {
                return $value instanceof \stdClass
                    ? \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some($value))
                    : \Superscript\Monads\Result\Err(new \InvalidArgumentException('Not a thing.'));
            }

            public function coerce(mixed $value): \Superscript\Monads\Result\Result
            {
                return $this->assert($value);
            }

            public function compare(mixed $a, mixed $b): bool
            {
                return $a === $b;
            }

            public function format(mixed $value): string
            {
                return 'thing';
            }

            public function shape(): \Superscript\Axiom\Types\Shapes\Shape
            {
                return new \Superscript\Axiom\Types\Shapes\OpaqueShape('thing');
            }
        };
    }

    #[Test]
    #[DataProvider('certifiedCases')]
    public function it_certifies(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $expected): void
    {
        $result = $rule->resolve($operator, $left, $right);

        $this->assertTrue($result->isOk(), $result->isErr() ? $result->unwrapErr()->describe() : '');
        $this->assertInstanceOf($expected, $result->unwrap()->returns);
    }

    public static function certifiedCases(): \Generator
    {
        $core = self::core();
        yield 'numbers add to Number' => [$core, '+', new NumberType(), new NumberType(), NumberType::class];
        yield 'number literals divide to Number' => [$core, '/', new LiteralType(5), new LiteralType(2), NumberType::class];
        yield 'a numeric enum multiplies' => [
            $core, '*', new UnionType(new LiteralType(1), new LiteralType(2)), new NumberType(), NumberType::class,
        ];
        yield 'ordering of numbers' => [$core, '<', new NumberType(), new NumberType(), BooleanType::class];
        yield 'ordering of number literals' => [$core, '>=', new LiteralType(1), new LiteralType(2), BooleanType::class];
        yield 'booleans conjoin' => [$core, '&&', new BooleanType(), new BooleanType(), BooleanType::class];

        $equality = new EqualityOverloader();
        yield 'equality of overlapping types' => [$equality, '==', new NumberType(), new NumberType(), BooleanType::class];
        yield 'equality of a literal against its enum' => [
            $equality, '=', new LiteralType('shop'), new UnionType(new LiteralType('shop'), new LiteralType('office')), BooleanType::class,
        ];
        yield 'the emptiness test: option against the null literal' => [
            $equality, '==', new OptionType(new NumberType()), new OptionType(new NeverType()), BooleanType::class,
        ];

        $has = new HasOverloader();
        yield 'list has element' => [$has, 'has', new ListType(new StringType()), new StringType(), BooleanType::class];
        yield 'list has enum member' => [
            $has, 'has', new ListType(new StringType()), new UnionType(new LiteralType('a'), new LiteralType('b')), BooleanType::class,
        ];
        yield 'list has subset' => [$has, 'has', new ListType(new StringType()), new ListType(new StringType()), BooleanType::class];
        yield 'has tolerates an absent needle' => [
            $has, 'has', new ListType(new StringType()), new OptionType(new StringType()), BooleanType::class,
        ];
        yield 'has against the empty list is vacuously legal' => [
            $has, 'has', new ListType(new NeverType(), 0, 0), new StringType(), BooleanType::class,
        ];
        yield 'has with a null needle is vacuously legal' => [
            $has, 'has', new ListType(new StringType()), new OptionType(new NeverType()), BooleanType::class,
        ];

        $in = new InOverloader();
        yield 'element in list' => [$in, 'in', new LiteralType(5), new ListType(new NumberType()), BooleanType::class];
        yield 'subset in list' => [$in, 'in', new ListType(new NumberType()), new ListType(new NumberType()), BooleanType::class];
        yield 'a fully-claimed union needle is judged member-wise' => [
            $in, 'in', new UnionType(new LiteralType(1), new NumberType()), new ListType(new NumberType()), BooleanType::class,
        ];

        $intersects = new IntersectsOverloader();
        yield 'lists intersect' => [$intersects, 'intersects', new ListType(new StringType()), new ListType(new StringType()), BooleanType::class];
        yield 'scalar intersects list' => [$intersects, 'intersects', new StringType(), new ListType(new StringType()), BooleanType::class];
        yield 'intersects tolerates absence' => [
            $intersects, 'intersects', new OptionType(new ListType(new StringType())), new ListType(new StringType()), BooleanType::class,
        ];
        yield 'intersects with an empty list is vacuously legal' => [
            $intersects, 'intersects', new ListType(new NeverType(), 0, 0), new ListType(new StringType()), BooleanType::class,
        ];
        yield 'intersects with an empty list on the right is vacuously legal' => [
            $intersects, 'intersects', new ListType(new StringType()), new ListType(new NeverType(), 0, 0), BooleanType::class,
        ];
    }

    #[Test]
    #[DataProvider('refusedCases')]
    public function it_refuses(OperatorOverloader $rule, string $operator, Type $left, Type $right, string $fragment, bool $dead = false): void
    {
        $result = $rule->resolve($operator, $left, $right);

        $this->assertTrue($result->isErr(), 'expected a refusal');
        $this->assertStringContainsString($fragment, $result->unwrapErr()->describe());
        $this->assertSame($dead, $result->unwrapErr()->dead);
    }

    public static function refusedCases(): \Generator
    {
        $core = self::core();
        yield 'arithmetic refuses strings' => [$core, '+', new StringType(), new NumberType(), '[+] expects Number and Number; got String and Number.'];
        yield 'arithmetic refuses options' => [
            $core, '-', new OptionType(new NumberType()), new NumberType(), 'the value may be absent',
        ];
        yield 'arithmetic refuses a cross-base union' => [
            $core, '+', new UnionType(new NumberType(), new StringType()), new NumberType(), 'every union member must be assignable',
        ];
        // Unknown is inert: no rule resolves it, and the refusal names the fix.
        yield 'arithmetic refuses an inert Unknown' => [
            $core, '*', new UnknownType(), new NumberType(), 'An Unknown operand is inert',
        ];
        yield 'ordering refuses an inert Unknown' => [
            $core, '>', new UnknownType(), new NumberType(), 'An Unknown operand is inert',
        ];
        yield 'logic refuses an inert Unknown' => [
            $core, '||', new UnknownType(), new BooleanType(), 'An Unknown operand is inert',
        ];
        yield 'ordering refuses strings' => [$core, '<', new StringType(), new StringType(), '[<] expects Number and Number; got String and String.'];
        yield 'ordering refuses options' => [
            $core, '<=', new OptionType(new NumberType()), new NumberType(), 'the value may be absent',
        ];
        yield 'logic refuses numbers' => [$core, '&&', new NumberType(), new BooleanType(), '[&&] expects Boolean and Boolean; got Number and Boolean.'];
        yield 'logic refusals carry the assignability cause' => [
            $core, '&&', new NumberType(), new BooleanType(), 'Number is not assignable to Boolean',
        ];
        yield 'logic refuses optional booleans' => [
            $core, '||', new BooleanType(), new OptionType(new BooleanType()), '[||] expects Boolean and Boolean',
        ];
        yield 'an unsupported operator is refused by the whole dialect' => [
            $core, 'coalesce', new NumberType(), new NumberType(), 'Operator [coalesce] is not supported.',
        ];
        // NullOverloader is deleted: absence-tolerant arithmetic certifies
        // nothing in core, so the null literal pair is an ordinary refusal.
        yield 'null arithmetic resolves nothing in core' => [
            $core, '+', new OptionType(new NeverType()), new OptionType(new NeverType()), '[+] expects Number and Number',
        ];

        $equality = new EqualityOverloader();
        // Totality: Ok certifies EVERY value of the operand types, and value
        // equality makes no claim about objects — so opaque-typed operands
        // are unsupported (not dead: no evaluation exists for them here).
        yield 'equality refuses an opaque left operand' => [
            $equality, '==', self::opaque(), new NumberType(), 'object equality belongs to the rule that owns the type',
        ];
        yield 'equality refuses an opaque right operand' => [
            $equality, '!=', new NumberType(), self::opaque(), 'does not claim the right operand',
        ];
        yield 'equality refuses an opaque buried in a union' => [
            $equality, '==', new UnionType(new NumberType(), self::opaque()), new NumberType(), 'object equality belongs to the rule that owns the type',
        ];
        yield 'equality refuses an inert Unknown' => [
            $equality, '==', new UnknownType(), new NumberType(), 'does not claim the left operand',
        ];
        yield 'a dead comparison is refused as dead' => [
            $equality, '==', new NumberType(), new StringType(), 'can never hold', true,
        ];
        yield 'dead negated equality is constant-true, and says so' => [
            $equality, '!=', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'dead strict negated equality says so too' => [
            $equality, '!==', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'a dead comparison carries the overlap cause' => [
            $equality, '==', new NumberType(), new StringType(), 'Number and String share no values.', true,
        ];
        yield 'a dead enum comparison names the union' => [
            $equality, '=', new UnionType(new LiteralType('shop'), new LiteralType('office')), new LiteralType('warehouse'), 'can never hold', true,
        ];
        yield 'equality does not resolve foreign operators' => [
            $equality, '+', new NumberType(), new NumberType(), 'Equality does not resolve [+].',
        ];

        $has = new HasOverloader();
        yield 'has refuses a non-list left side' => [$has, 'has', new StringType(), new StringType(), 'must be a present list'];
        yield 'has refuses an absent list side' => [
            $has, 'has', new OptionType(new ListType(new StringType())), new StringType(), 'must be a present list',
        ];
        yield 'has refuses an inert Unknown list side' => [
            $has, 'has', new UnknownType(), new StringType(), 'must be a present list',
        ];
        yield 'has refuses an inert Unknown needle' => [
            $has, 'has', new ListType(new StringType()), new UnknownType(), 'must be a scalar or a list',
        ];
        yield 'has refuses a dict left side' => [$has, 'has', new DictType(new StringType()), new StringType(), 'must be a present list'];
        yield 'has refuses a record needle' => [
            $has, 'has', new ListType(new StringType()), new RecordType(['a' => new NumberType()]), 'must be a scalar or a list',
        ];
        yield 'dead membership is refused as dead' => [
            $has, 'has', new ListType(new NumberType()), new StringType(), 'can never hold', true,
        ];
        yield 'dead membership carries the element cause' => [
            $has, 'has', new ListType(new NumberType()), new StringType(), 'Number and String share no values.', true,
        ];
        yield 'has does not resolve foreign operators' => [$has, 'in', new ListType(new StringType()), new StringType(), 'Membership does not resolve [in].'];

        $in = new InOverloader();
        // Universal over union members: one supported branch certifies
        // nothing, and opaque needles are objects value equality never claims.
        yield 'a union needle with an unclaimed branch is refused' => [
            $in, 'in', new UnionType(new NumberType(), new DictType(new NumberType())), new ListType(new NumberType()), 'must be a scalar or a list',
        ];
        yield 'an opaque needle is refused' => [
            $in, 'in', self::opaque(), new ListType(new NumberType()), 'must be a scalar or a list',
        ];
        yield 'in refuses a non-list right side' => [$in, 'in', new StringType(), new StringType(), 'must be a present list'];
        yield 'dead in-membership is refused as dead' => [
            $in, 'in', new StringType(), new ListType(new NumberType()), 'can never hold', true,
        ];
        yield 'in does not resolve foreign operators' => [$in, 'has', new StringType(), new ListType(new StringType()), 'Membership does not resolve [has].'];

        $intersects = new IntersectsOverloader();
        yield 'dead intersection is refused as dead' => [
            $intersects, 'intersects', new ListType(new NumberType()), new ListType(new StringType()), 'can never hold', true,
        ];
        yield 'dead intersection carries the element cause' => [
            $intersects, 'intersects', new ListType(new NumberType()), new ListType(new StringType()), 'Number and String share no values.', true,
        ];
        yield 'intersects refuses dicts, naming the left offender' => [
            $intersects, 'intersects', new DictType(new StringType()), new ListType(new StringType()), 'got Dict<String>',
        ];
        yield 'intersects refuses a record on the right, naming it' => [
            $intersects, 'intersects', new ListType(new StringType()), new RecordType(['a' => new NumberType()]), 'got {a: Number}',
        ];
        yield 'intersects refuses an inert Unknown' => [
            $intersects, 'intersects', new UnknownType(), new ListType(new StringType()), 'requires lists or scalars',
        ];
        yield 'intersects does not resolve foreign operators' => [
            $intersects, 'has', new ListType(new StringType()), new ListType(new StringType()), 'Intersection does not resolve [has].',
        ];
    }

    #[Test]
    public function a_foreign_operator_refusal_is_marked_unhandled(): void
    {
        $this->assertTrue((new EqualityOverloader())->resolve('+', new NumberType(), new NumberType())->unwrapErr()->unhandled);
        $this->assertTrue((new HasOverloader())->resolve('in', new ListType(new StringType()), new StringType())->unwrapErr()->unhandled);
        $this->assertTrue((new InOverloader())->resolve('has', new StringType(), new ListType(new StringType()))->unwrapErr()->unhandled);
        $this->assertTrue((new IntersectsOverloader())->resolve('has', new StringType(), new StringType())->unwrapErr()->unhandled);
    }

    #[Test]
    public function an_engaged_refusal_is_not_marked_unhandled(): void
    {
        $this->assertFalse((new EqualityOverloader())->resolve('==', new NumberType(), new StringType())->unwrapErr()->unhandled);
        $this->assertFalse((new HasOverloader())->resolve('has', new StringType(), new StringType())->unwrapErr()->unhandled);
    }
}
