<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\UnionType;

#[CoversClass(RecordType::class)]
#[UsesClass(TransformValueException::class)]
#[CoversClass(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(RecordShape::class)]
#[CoversClass(\Superscript\Axiom\Types\RecordProperty::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordPropertyShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(Optional::class)]
#[UsesClass(ReferencePath::class)]
final class RecordTypeTest extends TestCase
{
    private static function subject(): RecordType
    {
        return new RecordType([
            'name' => new StringType(),
            'age' => new NumberType(),
            'note' => new Optional(new OptionType(new StringType())),
        ]);
    }

    #[Test]
    public function property_names_cannot_encode_access_paths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('property must have a non-empty name without dots');

        new RecordType(['customer.turnover' => new NumberType()]);
    }

    #[Test]
    public function property_names_must_be_non_empty_strings(): void
    {
        foreach ([[new NumberType()], ['' => new NumberType()]] as $properties) {
            try {
                new RecordType($properties);
                $this->fail('The invalid property name should have been rejected.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('property must have a non-empty name', $error->getMessage());
            }
        }
    }

    #[Test]
    public function it_asserts_a_complete_record(): void
    {
        $record = self::subject()->assert(['name' => 'Ada', 'age' => 36, 'note' => 'x'])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36, 'note' => 'x'], $record);
    }

    #[Test]
    public function a_missing_optional_key_remains_missing(): void
    {
        $record = self::subject()->assert(['name' => 'Ada', 'age' => 36])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36], $record);
    }

    #[Test]
    public function a_missing_required_key_is_an_error(): void
    {
        $result = self::subject()->assert(['name' => 'Ada']);
        $failure = $result->unwrapErr();

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['age'], $failure->path);
        $this->assertTrue($failure->missing);
        $this->assertSame('is missing', $failure->detail);
        $this->assertStringContainsString('Required property [age]', $failure->getMessage());
    }

    #[Test]
    public function a_none_coercion_reads_as_required_but_missing(): void
    {
        $result = self::subject()->coerce(['name' => 'Ada', 'age' => '']);
        $failure = $result->unwrapErr();

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['age'], $failure->path);
        $this->assertFalse($failure->missing);
        $this->assertSame('reads as absent, but Number is required.', $failure->detail);
        $this->assertStringContainsString('Property [age] reads as absent', $failure->getMessage());
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

        $this->assertSame(['name' => 'Ada', 'age' => 36], $record);
    }

    #[Test]
    public function a_field_error_names_the_field(): void
    {
        $result = self::subject()->assert(['name' => 'Ada', 'age' => 'not a number']);
        $failure = $result->unwrapErr();

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['age'], $failure->path);
        $this->assertFalse($failure->missing);
        $this->assertStringContainsString('Property [age]:', $failure->getMessage());
    }

    #[Test]
    public function a_nested_field_error_retains_its_structural_path(): void
    {
        $record = new RecordType([
            'details' => new RecordType([
                'metrics' => new RecordType(['score' => new NumberType()]),
            ]),
        ]);

        $failure = $record->assert(['details' => ['metrics' => ['score' => 'not a number']]])->unwrapErr();

        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['details', 'metrics', 'score'], $failure->path);
        $this->assertFalse($failure->missing);
        $this->assertStringContainsString('Property [details]: Property [metrics]: Property [score]:', $failure->getMessage());
    }

    #[Test]
    public function an_invalid_sibling_wins_over_a_missing_nested_property(): void
    {
        $type = new RecordType([
            'details' => new RecordType(['score' => new NumberType()]),
            'total' => new NumberType(),
        ]);

        $failure = $type->coerce([
            'details' => [],
            'total' => 'not a number',
        ])->unwrapErr();

        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['total'], $failure->path);
        $this->assertFalse($failure->missing);
    }

    #[Test]
    public function an_invalid_supplied_property_wins_over_an_earlier_missing_property(): void
    {
        $type = new RecordType([
            'missing' => new NumberType(),
            'invalid' => new NumberType(),
        ]);

        $failure = $type->coerce(['invalid' => 'not a number'])->unwrapErr();

        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['invalid'], $failure->path);
        $this->assertFalse($failure->missing);
    }

    #[Test]
    public function the_first_missing_path_is_retained_after_all_properties_are_inspected(): void
    {
        $nested = new RecordType(['score' => new NumberType()]);
        $type = new RecordType([
            'first' => $nested,
            'second' => $nested,
        ]);

        $failure = $type->coerce(['first' => [], 'second' => []])->unwrapErr();

        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['first', 'score'], $failure->path);
        $this->assertTrue($failure->missing);
    }

    #[Test]
    public function the_first_of_multiple_missing_properties_is_reported(): void
    {
        $type = new RecordType([
            'first' => new NumberType(),
            'second' => new NumberType(),
        ]);

        $failure = $type->coerce([])->unwrapErr();

        $this->assertInstanceOf(\Superscript\Axiom\Exceptions\RecordPropertyViolation::class, $failure);
        $this->assertSame(['first'], $failure->path);
        $this->assertTrue($failure->missing);
    }

    #[Test]
    public function assert_is_strict_membership_so_an_extra_key_is_a_rejection(): void
    {
        $result = self::subject()->assert(['name' => 'Ada', 'age' => 36, 'extra' => 1]);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Property [extra] is not part of the record', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function coerce_takes_the_declared_slice_of_wide_input(): void
    {
        // Dropping undeclared keys is a conversion, like '5' → 5: hosts may
        // pass a whole context row and only the declared fields enter.
        $record = self::subject()->coerce(['name' => 'Ada', 'age' => '36', 'created_at' => '2026-01-01'])->unwrap()->unwrap();

        $this->assertSame(['name' => 'Ada', 'age' => 36], $record);
    }

    #[Test]
    public function a_non_array_is_an_error(): void
    {
        $result = self::subject()->assert('nope');

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(TransformValueException::class, $result->unwrapErr());
    }

    #[Test]
    public function a_non_array_does_not_coerce_either(): void
    {
        $result = self::subject()->coerce(5);

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(TransformValueException::class, $result->unwrapErr());
    }


    #[Test]
    public function it_formats_declared_fields_through_their_types(): void
    {
        $type = new RecordType(['a' => new NumberType(), 'b' => new Optional(new StringType())]);

        $this->assertSame('a: 1, b: y', $type->format(['a' => 1, 'b' => 'y']));
        $this->assertSame('a: 1', $type->format(['a' => 1]));
    }

    #[Test]
    public function it_projects_to_a_record_shape(): void
    {
        $shape = self::subject()->shape();

        $this->assertInstanceOf(RecordShape::class, $shape);
        $this->assertInstanceOf(StringShape::class, $shape->properties['name']->value);
        $this->assertInstanceOf(NumberShape::class, $shape->properties['age']->value);
        $this->assertInstanceOf(OptionShape::class, $shape->properties['note']->value);
        $this->assertTrue($shape->properties['note']->optional);
    }

    #[Test]
    public function properties_are_required_by_default_and_optional_only_when_wrapped(): void
    {
        $record = new RecordType([
            'required' => new NumberType(),
            'omittable' => new Optional(new NumberType()),
            'nullable' => new OptionType(new NumberType()),
            'omittable_nullable' => new Optional(new OptionType(new NumberType())),
        ]);

        $this->assertTrue($record->has('required'));
        $this->assertFalse($record->has('missing'));
        $this->assertSame(['required', 'omittable', 'nullable', 'omittable_nullable'], $record->names());
        $this->assertFalse($record->property('required')->optional);
        $this->assertTrue($record->property('omittable')->optional);
        $this->assertSame($record->property('required')->type, $record->property('required')->accessedType());
        $this->assertInstanceOf(OptionType::class, $record->property('omittable')->accessedType());
        $this->assertSame($record->property('nullable')->type, $record->property('nullable')->accessedType());
        $this->assertSame($record->property('omittable_nullable')->type, $record->property('omittable_nullable')->accessedType());
        $this->assertNull($record->property('missing'));
    }

    #[Test]
    public function omitting_an_early_optional_property_does_not_skip_later_properties(): void
    {
        $type = new RecordType([
            'note' => new Optional(new StringType()),
            'age' => new NumberType(),
        ]);

        $this->assertSame(['age' => 36], $type->assert(['age' => 36])->unwrap()->unwrap());
        $this->assertSame('age: 36', $type->format(['age' => 36]));
    }

    #[Test]
    public function an_explicit_absent_value_is_accepted_only_by_an_option_valued_property(): void
    {
        $type = new RecordType(['answer' => new OptionType(new NumberType())]);

        $this->assertSame(['answer' => null], $type->coerce(['answer' => null])->unwrap()->unwrap());
        $this->assertSame(['answer' => null], $type->coerce(['answer' => ''])->unwrap()->unwrap());

        $union = new RecordType([
            'answer' => new UnionType(new StringType(), new OptionType(new NumberType())),
        ]);
        $this->assertSame(['answer' => null], $union->coerce(['answer' => ''])->unwrap()->unwrap());
    }

    #[Test]
    public function projection_keeps_only_the_structural_paths_a_program_reads(): void
    {
        $record = new RecordType([
            'customer' => new Optional(new RecordType([
                'turnover' => new NumberType(),
                'employees' => new NumberType(),
            ])),
            'choice' => new OptionType(new RecordType([
                'selected' => new StringType(),
                'label' => new StringType(),
            ])),
            'scalar' => new NumberType(),
            'unused' => new StringType(),
        ]);

        $projected = $record->project([
            new ReferencePath('customer', 'turnover'),
            new ReferencePath('choice', 'selected'),
            new ReferencePath('scalar', 'imaginary'),
            new ReferencePath('unknown'),
        ]);

        $this->assertSame(['customer', 'choice', 'scalar'], $projected->names());
        $this->assertTrue($projected->property('customer')->optional);
        $this->assertSame(['turnover'], $projected->property('customer')->type->names());

        $choice = $projected->property('choice')->type;
        $this->assertInstanceOf(OptionType::class, $choice);
        $this->assertInstanceOf(RecordType::class, $choice->inner);
        $this->assertSame(['selected'], $choice->inner->names());
        $this->assertInstanceOf(NumberType::class, $projected->property('scalar')->type);

        $whole = $record->project([new ReferencePath('customer')]);
        $this->assertSame(['turnover', 'employees'], $whole->property('customer')->type->names());
        $this->assertSame([], $record->project([])->names());
    }
}
