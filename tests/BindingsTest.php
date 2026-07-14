<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;

#[CoversClass(Bindings::class)]
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
    public function it_flattens_associative_arrays_into_namespaces(): void
    {
        $bindings = new Bindings([
            'quote' => ['claims' => 3, 'turnover' => 500000],
            'tier' => 'small',
        ]);

        $this->assertSame(3, $bindings->get('claims', 'quote')->unwrap());
        $this->assertSame(500000, $bindings->get('turnover', 'quote')->unwrap());
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
    public function namespaced_lookup_returns_none_if_missing(): void
    {
        $bindings = new Bindings(['quote' => ['claims' => 3]]);

        $this->assertTrue($bindings->get('claims', 'policy')->isNone());
        $this->assertFalse($bindings->has('claims', 'policy'));
    }

    #[Test]
    public function an_array_binding_is_both_a_record_and_a_namespace(): void
    {
        // Descent, not flattening: one value, both readings.
        $bindings = new Bindings(['customer' => ['name' => 'Ada', 'turnover' => 600000]]);

        $this->assertSame(['name' => 'Ada', 'turnover' => 600000], $bindings->get('customer')->unwrap());
        $this->assertSame(600000, $bindings->get('turnover', 'customer')->unwrap());
        $this->assertTrue($bindings->has('customer'));
        $this->assertTrue($bindings->has('turnover', 'customer'));
    }

    #[Test]
    public function an_explicit_dotted_key_wins_over_descent(): void
    {
        $bindings = new Bindings([
            'customer' => ['turnover' => 1],
            'customer.turnover' => 999,
        ]);

        $this->assertSame(999, $bindings->get('turnover', 'customer')->unwrap());
    }

    #[Test]
    public function keys_returns_the_bindings_as_given(): void
    {
        $bindings = new Bindings(['a' => 1, 'customer' => ['b' => 2]]);

        $this->assertSame(['a', 'customer'], $bindings->keys());
    }
}
