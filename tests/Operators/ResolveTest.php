<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\Has;
use Superscript\Axiom\Operators\In;
use Superscript\Axiom\Operators\Intersects;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\SetOperands;
use Superscript\Axiom\Operators\UnsupportedOperation;
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
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The one question every rule answers: resolve(operator, operand types) —
 * certification carries the return type, refusal carries the diagnosis.
 * Rows (arithmetic, ordering, logic) are exercised through the composed
 * core dialect; the type functions (equality, membership, intersection)
 * also directly.
 */
#[CoversClass(Equality::class)]
#[CoversClass(Has::class)]
#[CoversClass(In::class)]
#[CoversClass(Intersects::class)]
#[CoversClass(SetOperands::class)]
#[CoversClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\DeadOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignature::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
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
    private static function core(): BinaryOperatorResolver
    {
        return Dialect::core()->operators();
    }

    private static function equality(string $operator): Equality
    {
        return new Equality($operator, in_array($operator, ['!=', '!=='], strict: true));
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
    public function it_certifies(BinaryOperatorRule|BinaryOperatorResolver $rule, string $operator, Type $left, Type $right, string $expected): void
    {
        $resolution = $rule instanceof BinaryOperatorResolver
            ? $rule->resolve($operator, $left, $right)->unwrap()
            : $rule->resolve($left, $right);

        $this->assertInstanceOf(ResolvedOperation::class, $resolution);
        $this->assertInstanceOf($expected, $resolution->returns);
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

        yield 'equality of overlapping types' => [self::equality('=='), '==', new NumberType(), new NumberType(), BooleanType::class];
        yield 'equality of a literal against its enum' => [
            self::equality('='), '=', new LiteralType('shop'), new UnionType(new LiteralType('shop'), new LiteralType('office')), BooleanType::class,
        ];
        yield 'the emptiness test: option against the null literal' => [
            self::equality('=='), '==', new OptionType(new NumberType()), new OptionType(new NeverType()), BooleanType::class,
        ];

        $has = new Has();
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

        $in = new In();
        yield 'element in list' => [$in, 'in', new LiteralType(5), new ListType(new NumberType()), BooleanType::class];
        yield 'subset in list' => [$in, 'in', new ListType(new NumberType()), new ListType(new NumberType()), BooleanType::class];
        yield 'a fully-claimed union needle is judged member-wise' => [
            $in, 'in', new UnionType(new LiteralType(1), new NumberType()), new ListType(new NumberType()), BooleanType::class,
        ];

        $intersects = new Intersects();
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
    public function it_refuses(BinaryOperatorRule|BinaryOperatorResolver $rule, string $operator, Type $left, Type $right, string $fragment, bool $dead = false): void
    {
        if ($rule instanceof BinaryOperatorResolver) {
            $mismatch = $rule->resolve($operator, $left, $right)->unwrapErr();
        } else {
            $resolution = $rule->resolve($left, $right);
            $this->assertTrue($resolution instanceof UnsupportedOperation || $resolution instanceof DeadOperation);
            $mismatch = new TypeMismatch($resolution->message, $resolution->causes, $resolution instanceof DeadOperation);
        }

        $this->assertStringContainsString($fragment, $mismatch->describe());
        $this->assertSame($dead, $mismatch->dead);
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

        // Totality: Ok certifies EVERY value of the operand types, and value
        // equality makes no claim about objects — so opaque-typed operands
        // are unsupported (not dead: no evaluation exists for them here).
        yield 'equality refuses an opaque left operand' => [
            self::equality('=='), '==', self::opaque(), new NumberType(), 'object equality belongs to the rule that owns the type',
        ];
        yield 'equality refuses an opaque right operand' => [
            self::equality('!='), '!=', new NumberType(), self::opaque(), 'does not claim the right operand',
        ];
        yield 'equality refuses an opaque buried in a union' => [
            self::equality('=='), '==', new UnionType(new NumberType(), self::opaque()), new NumberType(), 'object equality belongs to the rule that owns the type',
        ];
        yield 'equality refuses an inert Unknown' => [
            self::equality('=='), '==', new UnknownType(), new NumberType(), 'does not claim the left operand',
        ];
        yield 'a dead comparison is refused as dead' => [
            self::equality('=='), '==', new NumberType(), new StringType(), 'can never hold', true,
        ];
        yield 'dead negated equality is constant-true, and says so' => [
            self::equality('!='), '!=', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'dead strict negated equality says so too' => [
            self::equality('!=='), '!==', new NumberType(), new StringType(), 'always holds', true,
        ];
        yield 'a dead comparison carries the overlap cause' => [
            self::equality('=='), '==', new NumberType(), new StringType(), 'Number and String share no values.', true,
        ];
        yield 'a dead enum comparison names the union' => [
            self::equality('='), '=', new UnionType(new LiteralType('shop'), new LiteralType('office')), new LiteralType('warehouse'), 'can never hold', true,
        ];

        $has = new Has();
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

        $in = new In();
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

        $intersects = new Intersects();
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
    }
}
