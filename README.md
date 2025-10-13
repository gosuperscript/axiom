# Schema Library

A powerful PHP library for data transformation, type validation, and expression evaluation. This library provides a flexible framework for defining data schemas, transforming values, and evaluating complex expressions with type safety.

## Features

- **Type System**: Robust type validation and transformation for numbers, strings, booleans, lists, and dictionaries
- **Expression Evaluation**: Support for infix expressions with custom operators
- **Resolver Pattern**: Pluggable resolver system for different data sources
- **Symbol Registry**: Named value resolution and reuse
- **Operator Overloading**: Extensible operator system for custom evaluation logic
- **Monadic Error Handling**: Built on functional programming principles using Result and Option types

## Requirements

- PHP 8.4 or higher
- ext-intl extension

## Installation

```bash
composer require gosuperscript/schema
```

## Quick Start

### Basic Type Transformation

```php
<?php

use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\ValueResolver;

// Create a resolver with basic capabilities
$resolver = new DelegatingResolver([
    StaticResolver::class,
    ValueResolver::class,
]);

// Transform a string to a number
$source = new ValueDefinition(
    type: new NumberType(),
    source: new StaticSource('42')
);

$result = $resolver->resolve($source);
$value = $result->unwrap()->unwrap(); // 42 (as integer)
```

### Expression Evaluation

```php
<?php

use Superscript\Schema\Sources\InfixExpression;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\InfixResolver;
use Superscript\Schema\Resolvers\SymbolResolver;

// Set up resolver with symbol support
$resolver = new DelegatingResolver([
    StaticResolver::class,
    InfixResolver::class,
    SymbolResolver::class,
]);

// Register symbols
$resolver->instance(SymbolRegistry::class, new SymbolRegistry([
    'PI' => new StaticSource(3.14159),
    'radius' => new StaticSource(5),
]));

// Calculate: PI * radius * radius (area of circle)
$expression = new InfixExpression(
    left: new SymbolSource('PI'),
    operator: '*',
    right: new InfixExpression(
        left: new SymbolSource('radius'),
        operator: '*',
        right: new SymbolSource('radius')
    )
);

$result = $resolver->resolve($expression);
$area = $result->unwrap()->unwrap(); // ~78.54
```

## Core Concepts

### Types

The library provides several built-in types for data transformation:

#### NumberType
Transforms values to numeric types (int/float):
- Numeric strings: `"42"` → `42`
- Percentage strings: `"50%"` → `0.5`
- Numbers: `42.5` → `42.5`

#### StringType
Transforms values to strings:
- Numbers: `42` → `"42"`
- Stringable objects: converted to string representation
- Special handling for null and empty values

#### BooleanType
Transforms values to boolean:
- Truthy/falsy evaluation
- String representations: `"true"`, `"false"`

#### ListType and DictType
For collections and associative arrays with nested type validation.

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references to other sources
- **ValueDefinition**: Combines a type with a source for transformation
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions

### Resolvers

Resolvers handle the evaluation of sources:

- **StaticResolver**: Resolves static values
- **ValueResolver**: Applies type transformations
- **InfixResolver**: Evaluates binary expressions
- **SymbolResolver**: Looks up named symbols
- **DelegatingResolver**: Chains multiple resolvers together

### Operators

The library supports various operators through the overloader system:

- **Binary**: `+`, `-`, `*`, `/`, `%`, `**`
- **Comparison**: `==`, `!=`, `<`, `<=`, `>`, `>=`
- **Logical**: `&&`, `||`
- **Special**: `has`, `in`, `intersects`

## Advanced Usage

### Custom Types

Implement the `Type` interface to create custom data transformations:

```php
<?php

use Superscript\Schema\Types\Type;
use Superscript\Monads\Result\Result;

class EmailType implements Type
{
    public function transform(mixed $value): Result
    {
        // Custom transformation logic
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($value));
        }
        
        return new Err(new TransformValueException(type: 'email', value: $value));
    }
    
    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }
    
    public function format(mixed $value): string
    {
        return (string) $value;
    }
}
```

### Custom Resolvers

Create specialized resolvers for specific data sources:

```php
<?php

use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Monads\Result\Result;

class DatabaseResolver implements Resolver
{
    public function resolve(Source $source): Result
    {
        // Custom resolution logic
        // Connect to database, fetch data, etc.
    }
    
    public static function supports(Source $source): bool
    {
        return $source instanceof DatabaseSource;
    }
}
```

## Development

### Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`

### Testing

The library uses PHPUnit for testing with 100% code coverage requirements:

```bash
# Run all tests
composer test

# Individual test suites
composer test:unit      # Unit tests
composer test:types     # Static analysis (PHPStan)
composer test:infection # Mutation testing
```

### Code Quality

- **PHPStan**: Level max static analysis
- **Infection**: Mutation testing for test quality
- **Laravel Pint**: Code formatting
- **100% Code Coverage**: Required for all new code

## Architecture

The library follows several design patterns:

- **Strategy Pattern**: Different resolvers for different source types
- **Chain of Responsibility**: DelegatingResolver chains multiple resolvers
- **Factory Pattern**: Type system for creating appropriate transformations
- **Functional Programming**: Extensive use of Result and Option monads

## Error Handling

All operations return `Result<Option<T>, Throwable>` types:

- `Result::Ok(Some(value))`: Successful transformation with value
- `Result::Ok(None())`: Successful transformation with no value (null/empty)
- `Result::Err(exception)`: Transformation failed with error

This approach ensures:
- No exceptions for normal control flow
- Explicit handling of success/failure cases
- Type-safe null handling

## License

Proprietary - See license terms in your agreement.

## Contributing

This is a private library. Please follow the established patterns and ensure all tests pass before submitting changes.