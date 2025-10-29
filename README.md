# Schema Library

A PHP library for type validation and coercion following the [@azjezz/psl](https://github.com/azjezz/psl) pattern.

## Installation

```bash
composer require gosuperscript/schema
```

## Requirements

- PHP ^8.4
- ext-intl

## Overview

This library provides a type system for validating and coercing values with a clear distinction between assertion and coercion operations, inspired by the PSL library's type system.

## Core Concepts

### Type Interface

The central `Type` interface provides two main methods for value processing:

- **`assert(T $value): Result<Option<T>>`** - Validates that a value is already of the correct type
- **`coerce(mixed $value): Result<Option<T>>`** - Attempts to convert a value from any type to the target type

### Key Differences

- **`assert`**: Strict validation - only accepts values that are already of the expected type
- **`coerce`**: Permissive conversion - attempts to transform values from other types

Both methods return a `Result<Option<T>, Throwable>` where:
- `Ok(Some(value))` - successful validation/coercion with a value
- `Ok(None())` - successful validation/coercion but no value (e.g., empty strings)
- `Err(exception)` - failed validation/coercion with error details

## Available Types

### StringType

Handles string validation and coercion.

```php
use Superscript\Schema\Types\StringType;

$stringType = new StringType();

// Assert - only accepts strings
$result = $stringType->assert('hello');        // Ok(Some('hello'))
$result = $stringType->assert(123);            // Err(TransformValueException)

// Coerce - converts compatible types
$result = $stringType->coerce('hello');        // Ok(Some('hello'))
$result = $stringType->coerce(123);            // Ok(Some('123'))
$result = $stringType->coerce(1.5);            // Ok(Some('1.5'))
$result = $stringType->coerce('');             // Ok(None())
$result = $stringType->coerce('null');         // Ok(None())
```

### NumberType

Handles numeric validation and coercion.

```php
use Superscript\Schema\Types\NumberType;

$numberType = new NumberType();

// Assert - only accepts int/float
$result = $numberType->assert(42);             // Ok(Some(42))
$result = $numberType->assert(3.14);           // Ok(Some(3.14))
$result = $numberType->assert('42');           // Err(TransformValueException)

// Coerce - converts compatible types
$result = $numberType->coerce(42);             // Ok(Some(42))
$result = $numberType->coerce('42');           // Ok(Some(42))
$result = $numberType->coerce('3.14');         // Ok(Some(3.14))
$result = $numberType->coerce('45%');          // Ok(Some(0.45))
$result = $numberType->coerce('');             // Ok(None())
$result = $numberType->coerce('null');         // Ok(None())
```

### BooleanType

Handles boolean validation and coercion.

```php
use Superscript\Schema\Types\BooleanType;

$boolType = new BooleanType();

// Assert - only accepts bool
$result = $boolType->assert(true);             // Ok(Some(true))
$result = $boolType->assert(false);            // Ok(Some(false))
$result = $boolType->assert('true');           // Err(TransformValueException)

// Coerce - converts compatible types
$result = $boolType->coerce(true);             // Ok(Some(true))
$result = $boolType->coerce('yes');            // Ok(Some(true))
$result = $boolType->coerce('on');             // Ok(Some(true))
$result = $boolType->coerce('1');              // Ok(Some(true))
$result = $boolType->coerce(1);                // Ok(Some(true))
$result = $boolType->coerce('no');             // Ok(Some(false))
$result = $boolType->coerce('off');            // Ok(Some(false))
$result = $boolType->coerce('0');              // Ok(Some(false))
$result = $boolType->coerce(0);                // Ok(Some(false))
$result = $boolType->coerce(null);             // Ok(Some(false))
```

### Composite Types

#### ListType

Validates and coerces arrays where all elements are of the same type.

```php
use Superscript\Schema\Types\{ListType, NumberType};

$listType = new ListType(new NumberType());

// Coerce - converts array elements
$result = $listType->coerce(['1', '2', '3']);  // Ok(Some([1, 2, 3]))
$result = $listType->coerce('[1, 2, 3]');      // Ok(Some([1, 2, 3])) - JSON string
```

#### DictType

Validates and coerces associative arrays where all values are of the same type.

```php
use Superscript\Schema\Types\{DictType, NumberType};

$dictType = new DictType(new NumberType());

// Coerce - converts dictionary values
$result = $dictType->coerce(['a' => '1', 'b' => '2']);  // Ok(Some(['a' => 1, 'b' => 2]))
$result = $dictType->coerce('{"a": 1, "b": 2}');       // Ok(Some(['a' => 1, 'b' => 2])) - JSON string
```

## Working with Results

The library uses the [gosuperscript/monads](https://github.com/gosuperscript/monads) library for Result and Option types.

```php
use Superscript\Schema\Types\StringType;
use function Superscript\Monads\Option\None;

$stringType = new StringType();
$result = $stringType->coerce(123);

if ($result->isOk()) {
    $option = $result->unwrap();
    $value = $option->unwrapOr('default');
    echo "Success: $value"; // Success: 123
} else {
    $error = $result->unwrapErr();
    echo "Error: " . $error->getMessage();
}

// Or use the chaining approach
$value = $result->unwrapOr(None())->unwrapOr('default');
```

## Design Philosophy

This library follows the PSL pattern where:

1. **`assert`** is used when you expect a value to already be of the correct type and want to validate this assumption
2. **`coerce`** is used when you want to be permissive and attempt conversion from various input types
3. Both operations are explicit about their intent and return comprehensive error information
4. The type system distinguishes between "no value" (`None`) and "error" (`Err`) states

### Validation Boundaries vs Operations

The Type interface separates concerns into two categories:

**Validation Boundaries** (`assert` and `coerce`):
- These are your **input boundaries** where untrusted data enters your system
- They return `Result<Option<T>>` to force explicit error handling
- Use these when receiving data from external sources (user input, APIs, databases, etc.)

**Operations on Validated Data** (`compare` and `format`):
- These assume you're working with **already validated data** of type T
- They return simple types (`bool` for `compare`, `string` for `format`)
- Use these after you've validated data with `assert` or `coerce`

This design follows the principle: **validate once at boundaries, operate safely thereafter**.

### Usage Pattern

```php
use Superscript\Schema\Types\NumberType;
use function Superscript\Monads\Option\None;

$numberType = new NumberType();

// Step 1: Validate at the boundary
$result = $numberType->coerce($userInput);

if ($result->isOk()) {
    $value = $result->unwrapOr(None())->unwrapOr(0);
    
    // Step 2: Operate on validated data (no Result handling needed)
    $formatted = $numberType->format($value);        // Returns string directly
    $isEqual = $numberType->compare($value, 42);     // Returns bool directly
    
    echo "Value: $formatted, Equal to 42: " . ($isEqual ? 'yes' : 'no');
}
```

**Why not return `Result` from `compare` and `format`?**

1. **Cleaner API**: After validation, you shouldn't need to handle `Result` twice
2. **Ergonomics**: Most operations happen after validation, making the common case simpler
3. **Type Safety**: PHPDoc `@param T` combined with PHPStan at max level catches misuse at development time
4. **PSL Consistency**: This matches the pattern established by the PSL library

If you need runtime type checking for `compare` or `format`, you can wrap calls in try-catch blocks, but the expectation is that you've already validated your data before using these operations.

## Migration from Previous API

If you were using the previous `transform` method, here's how to migrate:

```php
// Old API
$result = $type->transform($value);

// New API - choose based on intent
$result = $type->assert($value);  // For validation of already-typed values
$result = $type->coerce($value);  // For conversion from mixed types
```

The `coerce` method provides the same functionality as the previous `transform` method, while `assert` provides additional type safety for scenarios where you expect the value to already be of the correct type.

## Contributing

This library maintains high code quality standards:

- 100% unit test coverage required
- Static analysis with PHPStan at max level
- Mutation testing with Infection
- Code style enforcement with Laravel Pint

Run the test suite:

```bash
composer test
```

## License

This library is proprietary software.
