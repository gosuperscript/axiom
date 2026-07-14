<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\StringType;

#[CoversClass(RecordType::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(RecordShape::class)]
#[UsesClass(StringShape::class)]
final class RecordTypeTest extends TestCase
{
    private static function subject(): RecordType
    {
        return new RecordType([
            'name' => new StringType(),
            'age' => new NumberType(),
            'note' => new OptionType(new StringType()),
        ]);
    }

    #[Test]
    public function it_asserts_a_complete_record(): void
    {
        $record = self::subject()->assert(['name' => 'Ada', 'age' => 36, 'note' => 'x'])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36, 'note' => 'x'], $record);
    }

    #[Test]
    public function a_missing_optional_key_canonicalizes_to_present_null(): void
    {
        $record = self::subject()->assert(['name' => 'Ada', 'age' => 36])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36, 'note' => null], $record);
    }

    #[Test]
    public function a_missing_required_key_is_an_error(): void
    {
        $result = self::subject()->assert(['name' => 'Ada']);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Field [age]', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_none_coercion_reads_as_required_but_missing(): void
    {
        $result = self::subject()->coerce(['name' => 'Ada', 'age' => '']);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Required field [age] is missing', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_optional_field_survives_an_absence_reading(): void
    {
        $record = self::subject()->coerce(['name' => 'Ada', 'age' => '36', 'note' => ''])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36, 'note' => null], $record);
    }

    #[Test]
    public function it_coerces_from_json(): void
    {
        $record = self::subject()->coerce('{"name": "Ada", "age": "36"}')->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36, 'note' => null], $record);
    }

    #[Test]
    public function a_field_error_names_the_field(): void
    {
        $result = self::subject()->assert(['name' => 'Ada', 'age' => 'not a number']);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Field [age]:', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_closed_record_polices_its_vocabulary(): void
    {
        $result = self::subject()->assert(['name' => 'Ada', 'age' => 36, 'extra' => 1]);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Field [extra] is not permitted', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function an_open_record_passes_extra_fields_through(): void
    {
        $type = new RecordType(['a' => new NumberType()], open: true);

        $record = $type->assert(['a' => 1, 'extra' => 'kept'])->unwrap()->unwrap();

        $this->assertSame(['a' => 1, 'extra' => 'kept'], $record);
    }

    #[Test]
    public function a_non_array_is_an_error(): void
    {
        $result = self::subject()->assert('nope');

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(TransformValueException::class, $result->unwrapErr());
    }

    #[Test]
    public function it_compares_fieldwise(): void
    {
        $type = new RecordType(['a' => new NumberType()], open: true);

        $this->assertTrue($type->compare(['a' => 1], ['a' => 1]));
        $this->assertFalse($type->compare(['a' => 1], ['a' => 2]));
        $this->assertFalse($type->compare(['a' => 1], ['b' => 1]));
        $this->assertTrue($type->compare(['a' => 1, 'x' => 'y'], ['a' => 1, 'x' => 'y']));
        $this->assertFalse($type->compare(['a' => 1, 'x' => 'y'], ['a' => 1, 'x' => 'z']));
    }

    #[Test]
    public function it_formats_declared_fields_through_their_types(): void
    {
        $type = new RecordType(['a' => new NumberType()], open: true);

        $this->assertSame("a: 1, x: 'y'", $type->format(['a' => 1, 'x' => 'y']));
    }

    #[Test]
    public function it_projects_to_a_record_shape(): void
    {
        $shape = self::subject()->shape();

        $this->assertInstanceOf(RecordShape::class, $shape);
        $this->assertFalse($shape->open);
        $this->assertInstanceOf(StringShape::class, $shape->fields['name']);
        $this->assertInstanceOf(NumberShape::class, $shape->fields['age']);
        $this->assertInstanceOf(OptionShape::class, $shape->fields['note']);

        $this->assertTrue((new RecordType([], open: true))->shape()->open);
    }
}
