<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fields;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Fields\Field;
use Superscript\Axiom\Fields\FieldBuilder;
use Superscript\Axiom\Fields\NamedFieldBuilder;
use Superscript\Axiom\Fields\OpaqueField;
use Superscript\Axiom\Fields\OpaqueFieldRegistry;
use Superscript\Axiom\Fields\TypedFieldBuilder;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeDescriber;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(Field::class)]
#[CoversClass(FieldBuilder::class)]
#[CoversClass(NamedFieldBuilder::class)]
#[CoversClass(TypedFieldBuilder::class)]
#[CoversClass(OpaqueField::class)]
#[CoversClass(OpaqueFieldRegistry::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
final class OpaqueFieldTest extends TestCase
{
    #[Test]
    public function it_builds_a_field_carrying_its_identity_name_and_return_type(): void
    {
        $field = Field::on('address')
            ->named('postcode')
            ->returns(new StringType())
            ->extractedWith(fn(object $value) => 'SW1A 1AA');

        $this->assertSame('address', $field->identity);
        $this->assertSame('postcode', $field->name);
        $this->assertInstanceOf(StringType::class, $field->returns);
    }

    #[Test]
    public function it_rejects_an_empty_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty opaque identity');

        Field::on('');
    }

    #[Test]
    public function it_rejects_an_empty_field_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field name cannot be empty');

        Field::on('address')->named('');
    }

    #[Test]
    public function extract_wraps_a_plain_return_value_in_ok_some(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => $value->postcode);

        $result = $field->extract((object) ['postcode' => 'EC1A 1BB']);

        $this->assertTrue($result->isOk());
        $this->assertSame('EC1A 1BB', $result->unwrap()->unwrap());
    }

    #[Test]
    public function extract_passes_a_returned_ok_result_through(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => Ok('WC2N 5DU'));

        $this->assertSame('WC2N 5DU', $field->extract((object) [])->unwrap()->unwrap());
    }

    #[Test]
    public function extract_refuses_null_on_a_field_that_certifies_a_value(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => null);

        $result = $field->extract((object) []);

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(LogicException::class, $result->unwrapErr());
        $this->assertSame(
            'Field [address.postcode] is declared String but its extractor returned null; declare an Option return type when the field can be absent.',
            $result->unwrapErr()->getMessage(),
        );
    }

    #[Test]
    public function extract_refuses_null_inside_a_returned_ok_the_same_way(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => Ok(null));

        $this->assertTrue($field->extract((object) [])->isErr());
    }

    #[Test]
    public function extract_reads_null_as_absence_on_an_option_typed_field(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new OptionType(new StringType()))
            ->extractedWith(fn(object $value) => null);

        $result = $field->extract((object) []);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function extract_wraps_a_present_value_of_an_option_typed_field_in_some(): void
    {
        $field = Field::on('address')->named('postcode')->returns(new OptionType(new StringType()))
            ->extractedWith(fn(object $value) => 'SW1A 1AA');

        $this->assertSame('SW1A 1AA', $field->extract((object) [])->unwrap()->unwrap());
    }

    #[Test]
    public function extract_passes_a_returned_err_result_through(): void
    {
        $failure = new RuntimeException('no postcode');
        $field = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => Err($failure));

        $result = $field->extract((object) []);

        $this->assertTrue($result->isErr());
        $this->assertSame($failure, $result->unwrapErr());
    }

    #[Test]
    public function the_registry_resolves_a_declared_field(): void
    {
        $postcode = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => 'SW1A 1AA');
        $registry = new OpaqueFieldRegistry(['address' => ['postcode' => $postcode]]);

        $this->assertSame($postcode, $registry->resolve('address', 'postcode'));
    }

    #[Test]
    public function the_registry_returns_null_for_an_unknown_identity_or_name(): void
    {
        $postcode = Field::on('address')->named('postcode')->returns(new StringType())
            ->extractedWith(fn(object $value) => 'SW1A 1AA');
        $registry = new OpaqueFieldRegistry(['address' => ['postcode' => $postcode]]);

        $this->assertNull($registry->resolve('claim', 'postcode'));
        $this->assertNull($registry->resolve('address', 'city'));
    }

    #[Test]
    public function an_empty_registry_resolves_every_lookup_to_null(): void
    {
        $this->assertNull((new OpaqueFieldRegistry())->resolve('address', 'postcode'));
    }
}
