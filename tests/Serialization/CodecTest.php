<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Serialization;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Serialization\Codec;
use Superscript\Axiom\Serialization\CodecException;
use Superscript\Axiom\Serialization\TypedValue;
use Superscript\Axiom\Serialization\TypeRegistry;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;

#[CoversClass(Codec::class)]
#[CoversClass(CodecException::class)]
#[CoversClass(TypedValue::class)]
#[CoversClass(TypeRegistry::class)]
#[UsesClass(TransformValueException::class)]
#[CoversClass(BooleanType::class)]
#[CoversClass(DictType::class)]
#[CoversClass(ListType::class)]
#[CoversClass(NumberType::class)]
#[CoversClass(StringType::class)]
class CodecTest extends TestCase
{
    #[Test]
    #[DataProvider('roundTripProvider')]
    public function it_round_trips_a_typed_value(Type $type, mixed $value, string $expectedPayload): void
    {
        $codec = new Codec();

        $payload = $codec->serialize(new TypedValue($type, Some($value)));
        $this->assertSame($expectedPayload, $payload);

        $result = $codec->deserialize($payload);
        $this->assertTrue($result->isOk(), 'Deserializing should return Ok: ' . $payload);

        $typedValue = $result->unwrap();
        $this->assertInstanceOf($type::class, $typedValue->type);
        $this->assertSame($value, $typedValue->value->unwrap());
    }

    public static function roundTripProvider(): array
    {
        return [
            'number int' => [new NumberType(), 42, '{"v":1,"type":"number","value":"42"}'],
            'number float' => [new NumberType(), 1.5, '{"v":1,"type":"number","value":"1.5"}'],
            'string' => [new StringType(), 'hello', '{"v":1,"type":"string","value":"hello"}'],
            'boolean' => [new BooleanType(), true, '{"v":1,"type":"boolean","value":true}'],
            'list of numbers' => [new ListType(new NumberType()), [1, 2, 3], '{"v":1,"type":"list<number>","value":["1","2","3"]}'],
            'nested list' => [new ListType(new ListType(new BooleanType())), [[true], [false]], '{"v":1,"type":"list<list<boolean>>","value":[[true],[false]]}'],
            'dict of strings' => [new DictType(new StringType()), ['a' => 'x'], '{"v":1,"type":"dict<string>","value":{"a":"x"}}'],
        ];
    }

    #[Test]
    public function it_round_trips_a_none_value(): void
    {
        $codec = new Codec();

        $payload = $codec->serialize(new TypedValue(new NumberType(), None()));
        $this->assertSame('{"v":1,"type":"number","value":null}', $payload);

        $typedValue = $codec->deserialize($payload)->unwrap();
        $this->assertInstanceOf(NumberType::class, $typedValue->type);
        $this->assertTrue($typedValue->value->isNone());
    }

    #[Test]
    public function it_serializes_scalar_type_args(): void
    {
        $type = new class implements Type {
            use FakeTypeMethods;

            public static function tag(): string
            {
                return 'fake';
            }

            public function toArgs(): array
            {
                return [0, 1.5, true, false];
            }
        };

        $payload = (new Codec())->serialize(new TypedValue($type, None()));
        $this->assertSame('{"v":1,"type":"fake<0,1.5,true,false>","value":null}', $payload);
    }

    #[Test]
    public function it_parses_scalar_type_args(): void
    {
        $registry = TypeRegistry::default();
        $registry->register('fake', function (array $args) {
            TestCase::assertSame([-3, 1.5, true, false], $args);

            return new StringType();
        });

        $result = (new Codec($registry))->deserialize('{"v":1,"type":"fake<-3,1.5,true,false>","value":"x"}');
        $this->assertTrue($result->isOk());
        $this->assertInstanceOf(StringType::class, $result->unwrap()->type);
    }

    #[Test]
    public function it_treats_a_missing_version_as_version_one(): void
    {
        $result = (new Codec())->deserialize('{"type":"number","value":"1"}');
        $this->assertSame(1, $result->unwrap()->value->unwrap());
    }

    #[Test]
    #[DataProvider('invalidPayloadProvider')]
    public function it_returns_err_for_invalid_payloads(string $payload, string $expectedMessage): void
    {
        $result = (new Codec())->deserialize($payload);

        $this->assertTrue($result->isErr(), "Deserializing should return Err: $payload");
        $this->assertStringContainsString($expectedMessage, $result->unwrapErr()->getMessage());
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'invalid json' => ['not json', 'not valid JSON'],
            'not an object' => ['"hello"', 'must be an object'],
            'missing type' => ['{"v":1,"value":null}', 'must be an object with a string [type] field'],
            'non-string type' => ['{"v":1,"type":42,"value":null}', 'must be an object with a string [type] field'],
            'non-integer version' => ['{"v":"1","type":"number","value":null}', 'must be an integer'],
            'newer version' => ['{"v":2,"type":"number","value":null}', 'newer than supported version'],
            'unknown tag' => ['{"v":1,"type":"money","value":null}', 'Unknown type tag [money]'],
            'uppercase tag' => ['{"v":1,"type":"Number","value":null}', 'Expected a type tag'],
            'empty type' => ['{"v":1,"type":"","value":null}', 'Expected a type tag'],
            'unterminated generic' => ['{"v":1,"type":"list<number","value":null}', 'Expected [>]'],
            'trailing characters' => ['{"v":1,"type":"number>","value":null}', 'Unexpected trailing characters'],
            'missing arg' => ['{"v":1,"type":"list<>","value":null}', 'Expected a type tag'],
            'wrong arity zero-arg type' => ['{"v":1,"type":"number<string>","value":null}', 'does not accept type arguments'],
            'wrong arity list no args' => ['{"v":1,"type":"list","value":null}', 'expects exactly one type argument'],
            'wrong arity list two args' => ['{"v":1,"type":"list<number,string>","value":null}', 'expects exactly one type argument'],
            'scalar arg for list' => ['{"v":1,"type":"list<42>","value":null}', 'expects exactly one type argument'],
            'depth bomb' => ['{"v":1,"type":"' . str_repeat('list<', 50) . 'number' . str_repeat('>', 50) . '","value":null}', 'maximum nesting depth'],
            'value fails decode' => ['{"v":1,"type":"number","value":"abc"}', 'numeric'],
            'value wrong wire shape' => ['{"v":1,"type":"number","value":42}', 'numeric'],
            'non-string in string' => ['{"v":1,"type":"string","value":42}', 'string'],
            'non-bool in boolean' => ['{"v":1,"type":"boolean","value":"true"}', 'boolean'],
            'non-list in list' => ['{"v":1,"type":"list<number>","value":{"a":"1"}}', 'list'],
            'bad item in list' => ['{"v":1,"type":"list<number>","value":["1","x"]}', 'numeric'],
            'non-dict in dict' => ['{"v":1,"type":"dict<string>","value":"x"}', 'dict'],
            'bad item in dict' => ['{"v":1,"type":"dict<string>","value":{"a":1}}', 'string'],
        ];
    }

    #[Test]
    public function it_asserts_the_decoded_value_against_the_type(): void
    {
        $registry = new TypeRegistry();
        $registry->register('strictbool', fn(array $args) => new class implements Type {
            use FakeTypeMethods;

            public static function tag(): string
            {
                return 'strictbool';
            }

            public function toArgs(): array
            {
                return [];
            }

            public function assert(mixed $value): \Superscript\Monads\Result\Result
            {
                return new \Superscript\Monads\Result\Err(new InvalidArgumentException('assert failed'));
            }
        });

        $result = (new Codec($registry))->deserialize('{"v":1,"type":"strictbool","value":true}');
        $this->assertTrue($result->isErr());
        $this->assertSame('assert failed', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function registering_a_duplicate_tag_throws(): void
    {
        $registry = TypeRegistry::default();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered for tag [number]');

        $registry->register('number', fn(array $args) => new NumberType());
    }
}

trait FakeTypeMethods
{
    public function assert(mixed $value): \Superscript\Monads\Result\Result
    {
        return \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some($value));
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
        return '';
    }

    public function encode(mixed $value): mixed
    {
        return $value;
    }

    public function decode(mixed $value): \Superscript\Monads\Result\Result
    {
        return \Superscript\Monads\Result\Ok($value);
    }
}
