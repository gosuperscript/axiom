<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Sources\SymbolSource;

#[CoversClass(Bindings::class)]
#[UsesClass(SymbolSource::class)]
final class BindingsTest extends TestCase
{
    #[Test]
    public function it_returns_none_for_absent_keys(): void
    {
        $bindings = new Bindings();

        $this->assertTrue($bindings->get('anything')->isNone());
        $this->assertFalse($bindings->has('anything'));
    }

    #[Test]
    public function it_returns_some_for_scalar_values(): void
    {
        $bindings = new Bindings(['radius' => 5, 'name' => 'John']);

        $this->assertSame(5, $bindings->get('radius')->unwrap());
        $this->assertSame('John', $bindings->get('name')->unwrap());
        $this->assertTrue($bindings->has('radius'));
    }

    #[Test]
    public function null_values_are_real_bindings(): void
    {
        $bindings = new Bindings(['A' => null]);

        $this->assertTrue($bindings->has('A'));

        $value = $bindings->get('A');
        $this->assertTrue($value->isSome());
        $this->assertNull($value->unwrap());
    }

    #[Test]
    public function nested_records_are_kept_under_their_root_key(): void
    {
        $bindings = new Bindings([
            'quote' => ['claims' => 3, 'turnover' => 500000],
            'tier' => 'small',
        ]);

        $this->assertSame(['claims' => 3, 'turnover' => 500000], $bindings->get('quote')->unwrap());
        $this->assertSame('small', $bindings->get('tier')->unwrap());
    }

    #[Test]
    public function non_associative_arrays_are_kept_as_values(): void
    {
        $bindings = new Bindings(['numbers' => [1, 2, 3]]);

        $this->assertSame([1, 2, 3], $bindings->get('numbers')->unwrap());
    }

    #[Test]
    public function empty_array_is_kept_as_a_value(): void
    {
        $bindings = new Bindings(['empty' => []]);

        $this->assertSame([], $bindings->get('empty')->unwrap());
    }

    #[Test]
    public function lookup_returns_none_if_missing(): void
    {
        $bindings = new Bindings(['quote' => ['claims' => 3]]);

        $this->assertTrue($bindings->get('policy')->isNone());
        $this->assertFalse($bindings->has('policy'));
    }

    #[Test]
    public function an_array_binding_answers_only_its_root_symbol(): void
    {
        // Exact keys only: a record value is data, not a namespace. Digging
        // into it is member access — an explicit Source node — never symbol
        // lookup. This is what makes caller data unable to answer for (and
        // so shadow) a definition.
        $bindings = new Bindings(['customer' => ['name' => 'Ada', 'turnover' => 600000]]);

        $this->assertSame(['name' => 'Ada', 'turnover' => 600000], $bindings->get('customer')->unwrap());
        $this->assertTrue($bindings->has('customer'));
        $this->assertFalse($bindings->has('turnover'));
        $this->assertTrue($bindings->get('turnover')->isNone());
    }
}
