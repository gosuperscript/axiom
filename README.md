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

#### Basic Type Transformation Flow

```mermaid
sequenceDiagram
    participant User as User Code
    participant DR as DelegatingResolver
    participant VR as ValueResolver
    participant SR as StaticResolver
    participant NT as NumberType
    
    User->>DR: new DelegatingResolver([<br/>StaticResolver, ValueResolver])
    User->>DR: resolve(ValueDefinition)
    Note over User,NT: ValueDefinition contains:<br/>- type: NumberType<br/>- source: StaticSource('42')
    
    DR->>VR: resolve(ValueDefinition)
    Note over VR: Extract inner source<br/>and type from ValueDefinition
    
    VR->>DR: resolve(StaticSource('42'))
    DR->>SR: resolve(StaticSource('42'))
    SR-->>DR: Ok(Some('42'))
    DR-->>VR: Ok(Some('42'))
    
    VR->>NT: coerce('42')
    Note over NT: Convert string '42'<br/>to integer 42
    NT-->>VR: Ok(Some(42))
    
    VR-->>DR: Ok(Some(42))
    DR-->>User: Result<Option<42>>
    User->>User: unwrap().unwrap()
    User->>User: value = 42
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

#### Expression Evaluation Flow

```mermaid
sequenceDiagram
    participant User as User Code
    participant DR as DelegatingResolver
    participant IR as InfixResolver
    participant SymR as SymbolResolver
    participant Reg as SymbolRegistry
    participant OM as OverloaderManager
    
    Note over User,OM: Expression: PI * (radius * radius)
    
    User->>DR: resolve(InfixExpression<br/>PI * (radius * radius))
    DR->>IR: resolve(outer InfixExpression)
    
    Note over IR: Process left operand: PI
    IR->>DR: resolve(SymbolSource('PI'))
    DR->>SymR: resolve(SymbolSource('PI'))
    SymR->>Reg: get('PI')
    Reg-->>SymR: Some(StaticSource(3.14159))
    SymR->>DR: resolve(StaticSource(3.14159))
    DR-->>SymR: Ok(Some(3.14159))
    SymR-->>DR: Ok(Some(3.14159))
    DR-->>IR: Ok(Some(3.14159))
    
    Note over IR: Process right operand: radius * radius
    IR->>DR: resolve(InfixExpression<br/>radius * radius)
    DR->>IR: resolve(inner InfixExpression)
    
    Note over IR: Process inner left: radius
    IR->>DR: resolve(SymbolSource('radius'))
    DR->>SymR: resolve(SymbolSource('radius'))
    SymR->>Reg: get('radius')
    Reg-->>SymR: Some(StaticSource(5))
    SymR->>DR: resolve(StaticSource(5))
    DR-->>SymR: Ok(Some(5))
    SymR-->>DR: Ok(Some(5))
    DR-->>IR: Ok(Some(5))
    
    Note over IR: Process inner right: radius
    IR->>DR: resolve(SymbolSource('radius'))
    DR->>SymR: resolve(SymbolSource('radius'))
    SymR->>Reg: get('radius')
    Reg-->>SymR: Some(StaticSource(5))
    SymR->>DR: resolve(StaticSource(5))
    DR-->>SymR: Ok(Some(5))
    SymR-->>DR: Ok(Some(5))
    DR-->>IR: Ok(Some(5))
    
    Note over IR: Apply operator: 5 * 5
    IR->>OM: evaluate(5, '*', 5)
    OM-->>IR: Ok(Some(25))
    IR-->>DR: Ok(Some(25))
    DR-->>IR: Ok(Some(25))
    
    Note over IR: Apply operator: 3.14159 * 25
    IR->>OM: evaluate(3.14159, '*', 25)
    OM-->>IR: Ok(Some(78.53975))
    IR-->>DR: Ok(Some(78.53975))
    
    DR-->>User: Result<Option<78.53975>>
```

## Core Concepts

### System Integration Example

```mermaid
graph TB
    subgraph "Your Application"
        WebApp[Web Application]
        API[API Controller]
        Config[Configuration]
        DB[(Database)]
    end
    
    subgraph "Schema Library Usage"
        Setup[Setup Phase]
        Transform[Data Transformation]
        Validate[Validation]
        Compute[Expression Computation]
    end
    
    subgraph "Schema Library Components"
        DR[DelegatingResolver]
        Types[Type System]
        Sources[Source Definitions]
        Registry[SymbolRegistry]
    end
    
    WebApp --> API
    API --> Setup
    Config --> Setup
    
    Setup --> DR
    Setup --> Registry
    Setup --> Types
    
    API --> Transform
    API --> Validate
    API --> Compute
    
    Transform --> DR
    Validate --> DR
    Compute --> DR
    
    DR --> Sources
    DR --> Types
    DR --> Registry
    
    DB -.provides data.-> API
    API -.validated data.-> DB
    
    style WebApp fill:#b3d9ff
    style Setup fill:#e1f5ff
    style Transform fill:#e8f5e9
    style Validate fill:#fff4e1
    style Compute fill:#ffd9b3
```

### Types

The library provides several built-in types for data validation and coercion:

#### NumberType
Validates and coerces values to numeric types (int/float):
- Numeric strings: `"42"` → `42`
- Percentage strings: `"50%"` → `0.5`
- Numbers: `42.5` → `42.5`

#### StringType
Validates and coerces values to strings:
- Numbers: `42` → `"42"`
- Stringable objects: converted to string representation
- Special handling for null and empty values

#### BooleanType
Validates and coerces values to boolean:
- Truthy/falsy evaluation
- String representations: `"true"`, `"false"`

#### ListType and DictType
For collections and associative arrays with nested type validation.

### Type API: Assert vs Coerce

The `Type` interface provides two methods for value processing, following the [@azjezz/psl](https://github.com/azjezz/psl) pattern:

- **`assert(T $value): Result<Option<T>>`** - Validates that a value is already of the correct type
- **`coerce(mixed $value): Result<Option<T>>`** - Attempts to convert a value from any type to the target type

**When to use:**
- Use `assert()` when you expect a value to already be the correct type and want strict validation
- Use `coerce()` when you want to transform values from various input types (permissive conversion)

**Example:**
```php
$numberType = new NumberType();

// Assert - only accepts int/float
$result = $numberType->assert(42);      // Ok(Some(42))
$result = $numberType->assert('42');    // Err(TransformValueException)

// Coerce - converts compatible types
$result = $numberType->coerce(42);      // Ok(Some(42))
$result = $numberType->coerce('42');    // Ok(Some(42))
$result = $numberType->coerce('45%');   // Ok(Some(0.45))
```

Both methods return `Result<Option<T>, Throwable>` where:
- `Ok(Some(value))` - successful validation/coercion with a value
- `Ok(None())` - successful but no value (e.g., empty strings)
- `Err(exception)` - failed validation/coercion

**Note:** The `coerce()` method provides the same functionality as the previous `transform()` method.

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references to other sources
- **ValueDefinition**: Combines a type with a source for validation and coercion
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions

### Resolvers

Resolvers handle the evaluation of sources:

- **StaticResolver**: Resolves static values
- **ValueResolver**: Applies type coercion using the `coerce()` method
- **InfixResolver**: Evaluates binary expressions
- **SymbolResolver**: Looks up named symbols
- **DelegatingResolver**: Chains multiple resolvers together

#### Resolver Chain Flow

```mermaid
flowchart TD
    Start([Source to Resolve]) --> DR[DelegatingResolver]
    
    DR --> CheckMap{Source Type<br/>in Resolver Map?}
    
    CheckMap -->|No| Error[Throw RuntimeException:<br/>No resolver found]
    CheckMap -->|Yes| GetResolver[Get Resolver from<br/>IoC Container]
    
    GetResolver --> CallResolve[Call Resolver.resolve]
    
    CallResolve --> ResolverType{Resolver Type}
    
    ResolverType -->|StaticResolver| SR[Return static value<br/>directly]
    ResolverType -->|ValueResolver| VR[Resolve inner source,<br/>then apply Type coercion]
    ResolverType -->|InfixResolver| IR[Resolve left & right,<br/>apply operator]
    ResolverType -->|SymbolResolver| SymR[Lookup symbol,<br/>resolve referenced source]
    ResolverType -->|UnaryResolver| UR[Resolve operand,<br/>apply unary operator]
    ResolverType -->|CustomResolver| CR[Custom resolution logic]
    
    SR --> Result
    VR --> Result
    IR --> Result
    SymR --> Result
    UR --> Result
    CR --> Result
    
    Result([Result&lt;Option&lt;value&gt;&gt;])
    
    style Start fill:#e1f5ff
    style DR fill:#fff4e1
    style Result fill:#e8f5e9
    style Error fill:#ffcdd2
```

#### DelegatingResolver Configuration

```mermaid
graph LR
    subgraph "Resolver Map Configuration"
        Map[resolverMap Array]
        Map --> M1["StaticSource::class => StaticResolver::class"]
        Map --> M2["ValueDefinition::class => ValueResolver::class"]
        Map --> M3["InfixExpression::class => InfixResolver::class"]
        Map --> M4["SymbolSource::class => SymbolResolver::class"]
        Map --> M5["UnaryExpression::class => UnaryResolver::class"]
        Map --> M6["CustomSource::class => CustomResolver::class"]
    end
    
    subgraph "IoC Container"
        Container[Laravel Container]
        Container --> I1[StaticResolver Instance]
        Container --> I2[ValueResolver Instance]
        Container --> I3[InfixResolver Instance]
        Container --> I4[SymbolResolver Instance]
        Container --> I5[UnaryResolver Instance]
        Container --> I6[CustomResolver Instance]
    end
    
    subgraph "Shared Dependencies"
        SR[SymbolRegistry]
        OM[OverloaderManager]
        Self[Resolver Self-Reference]
    end
    
    Map --> Container
    Container --> SR
    Container --> OM
    Container --> Self
    
    I4 -.uses.-> SR
    I3 -.uses.-> OM
    I5 -.uses.-> OM
    I2 -.uses.-> Self
    I3 -.uses.-> Self
    I4 -.uses.-> Self
    I5 -.uses.-> Self
    I6 -.uses.-> Self
    
    style Map fill:#e1f5ff
    style Container fill:#fff4e1
    style SR fill:#e8f5e9
    style OM fill:#e8f5e9
```

### Operators

The library supports various operators through the overloader system:

- **Binary**: `+`, `-`, `*`, `/`, `%`, `**`
- **Comparison**: `==`, `!=`, `<`, `<=`, `>`, `>=`
- **Logical**: `&&`, `||`
- **Special**: `has`, `in`, `intersects`

## Advanced Usage

### Composing Custom Sources, Types, and Resolvers

The library is designed to be highly extensible. You can add your own sources, types, and resolvers to the system.

```mermaid
graph TB
    subgraph "Your Application"
        CustomSource[Custom Source Implementation]
        CustomType[Custom Type Implementation]
        CustomResolver[Custom Resolver Implementation]
        AppCode[Application Code]
    end
    
    subgraph "Library Core"
        Source[Source Interface]
        Type[Type Interface]
        Resolver[Resolver Interface]
        DelegatingResolver[DelegatingResolver]
    end
    
    subgraph "Built-in Implementations"
        BuiltInSources[StaticSource, SymbolSource, etc.]
        BuiltInTypes[NumberType, StringType, etc.]
        BuiltInResolvers[StaticResolver, ValueResolver, etc.]
    end
    
    CustomSource -.implements.-> Source
    CustomType -.implements.-> Type
    CustomResolver -.implements.-> Resolver
    
    Source --> BuiltInSources
    Type --> BuiltInTypes
    Resolver --> BuiltInResolvers
    
    AppCode --> CustomSource
    AppCode --> CustomType
    AppCode --> CustomResolver
    AppCode --> DelegatingResolver
    
    DelegatingResolver --> CustomResolver
    DelegatingResolver --> BuiltInResolvers
    CustomResolver --> CustomSource
    
    style CustomSource fill:#ffd9b3
    style CustomType fill:#ffd9b3
    style CustomResolver fill:#ffd9b3
    style AppCode fill:#b3d9ff
```

### How Custom Components Integrate

```mermaid
sequenceDiagram
    participant App as Your Application
    participant DR as DelegatingResolver
    participant CR as Custom Resolver
    participant CS as Custom Source
    participant CT as Custom Type
    
    Note over App,CT: Setup Phase
    App->>DR: new DelegatingResolver([<br/>  CustomResolver::class,<br/>  StaticResolver::class,<br/>  ...<br/>])
    App->>DR: instance(CustomConfig::class, $config)
    
    Note over App,CT: Resolution Phase
    App->>DR: resolve(new CustomSource(...))
    DR->>DR: Find matching resolver
    DR->>CR: supports(CustomSource)?
    CR-->>DR: true
    DR->>CR: resolve(CustomSource)
    CR->>CS: Extract data from CustomSource
    CS-->>CR: Raw data
    
    alt With Type Validation
        CR->>CT: coerce(raw data)
        CT-->>CR: Result<Option<typed>>
        CR-->>DR: Result<Option<typed>>
    else Direct Resolution
        CR-->>DR: Result<Option<value>>
    end
    
    DR-->>App: Result<Option<value>>
```

### Custom Types

Implement the `Type` interface to create custom data validations and coercions:

```php
<?php

use Superscript\Schema\Types\Type;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
use Superscript\Schema\Exceptions\TransformValueException;
use function Superscript\Monads\Result\Ok;
use function Superscript\Monads\Option\Some;

class EmailType implements Type
{
    public function assert(mixed $value): Result
    {
        // Strict validation - only accepts valid email strings
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($value));
        }
        
        return new Err(new TransformValueException(type: 'email', value: $value));
    }
    
    public function coerce(mixed $value): Result
    {
        // Permissive conversion - attempts to convert to email format
        $stringValue = is_string($value) ? $value : strval($value);
        $trimmed = trim($stringValue);
        
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($trimmed));
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

### Package Architecture Overview

```mermaid
graph TB
    subgraph "Core Interfaces"
        Source[Source Interface]
        Type[Type Interface]
        Resolver[Resolver Interface]
    end
    
    subgraph "Source Types"
        StaticSource[StaticSource]
        SymbolSource[SymbolSource]
        ValueDefinition[ValueDefinition]
        InfixExpression[InfixExpression]
        UnaryExpression[UnaryExpression]
    end
    
    subgraph "Type System"
        NumberType[NumberType]
        StringType[StringType]
        BooleanType[BooleanType]
        ListType[ListType]
        DictType[DictType]
    end
    
    subgraph "Resolvers"
        DelegatingResolver[DelegatingResolver]
        StaticResolver[StaticResolver]
        ValueResolver[ValueResolver]
        InfixResolver[InfixResolver]
        UnaryResolver[UnaryResolver]
        SymbolResolver[SymbolResolver]
    end
    
    subgraph "Operator System"
        OverloaderManager[OverloaderManager]
        BinaryOverloader[BinaryOverloader]
        ComparisonOverloader[ComparisonOverloader]
        LogicalOverloader[LogicalOverloader]
    end
    
    subgraph "Support"
        SymbolRegistry[SymbolRegistry]
        Container[IoC Container]
    end
    
    Source --> StaticSource
    Source --> SymbolSource
    Source --> ValueDefinition
    Source --> InfixExpression
    Source --> UnaryExpression
    
    Type --> NumberType
    Type --> StringType
    Type --> BooleanType
    Type --> ListType
    Type --> DictType
    
    Resolver --> DelegatingResolver
    Resolver --> StaticResolver
    Resolver --> ValueResolver
    Resolver --> InfixResolver
    Resolver --> UnaryResolver
    Resolver --> SymbolResolver
    
    DelegatingResolver --> Container
    DelegatingResolver --> StaticResolver
    DelegatingResolver --> ValueResolver
    DelegatingResolver --> InfixResolver
    DelegatingResolver --> UnaryResolver
    DelegatingResolver --> SymbolResolver
    
    ValueDefinition --> Type
    InfixResolver --> OverloaderManager
    UnaryResolver --> OverloaderManager
    SymbolResolver --> SymbolRegistry
    
    OverloaderManager --> BinaryOverloader
    OverloaderManager --> ComparisonOverloader
    OverloaderManager --> LogicalOverloader
    
    style Source fill:#e1f5ff
    style Type fill:#fff4e1
    style Resolver fill:#e8f5e9
```

### Data Flow Through The System

```mermaid
sequenceDiagram
    participant Client
    participant DelegatingResolver
    participant SpecificResolver
    participant Type
    participant OperatorSystem
    participant SymbolRegistry
    
    Client->>DelegatingResolver: resolve(Source)
    
    alt StaticSource
        DelegatingResolver->>StaticResolver: resolve(StaticSource)
        StaticResolver-->>DelegatingResolver: Result<Option<value>>
    else ValueDefinition
        DelegatingResolver->>ValueResolver: resolve(ValueDefinition)
        ValueResolver->>SpecificResolver: resolve(inner source)
        SpecificResolver-->>ValueResolver: Result<Option<raw>>
        ValueResolver->>Type: coerce(raw)
        Type-->>ValueResolver: Result<Option<typed>>
        ValueResolver-->>DelegatingResolver: Result<Option<typed>>
    else InfixExpression
        DelegatingResolver->>InfixResolver: resolve(InfixExpression)
        InfixResolver->>DelegatingResolver: resolve(left source)
        DelegatingResolver-->>InfixResolver: Result<Option<left>>
        InfixResolver->>DelegatingResolver: resolve(right source)
        DelegatingResolver-->>InfixResolver: Result<Option<right>>
        InfixResolver->>OperatorSystem: evaluate(left, op, right)
        OperatorSystem-->>InfixResolver: Result<Option<result>>
        InfixResolver-->>DelegatingResolver: Result<Option<result>>
    else SymbolSource
        DelegatingResolver->>SymbolResolver: resolve(SymbolSource)
        SymbolResolver->>SymbolRegistry: get(symbol name)
        SymbolRegistry-->>SymbolResolver: Option<Source>
        SymbolResolver->>DelegatingResolver: resolve(referenced source)
        DelegatingResolver-->>SymbolResolver: Result<Option<value>>
        SymbolResolver-->>DelegatingResolver: Result<Option<value>>
    end
    
    DelegatingResolver-->>Client: Result<Option<value>>
```

### Type Transformation Flow

```mermaid
flowchart TD
    Start([Input Value]) --> TypeMethod{Method Called?}
    
    TypeMethod -->|assert| AssertCheck{Value Already Correct Type?}
    TypeMethod -->|coerce| CoerceAttempt[Attempt Type Conversion]
    
    AssertCheck -->|Yes| AssertSuccess[Ok Some value]
    AssertCheck -->|No| AssertFail[Err TransformValueException]
    
    CoerceAttempt --> CoerceCheck{Can Convert?}
    CoerceCheck -->|Yes, with value| CoerceSuccess[Ok Some converted]
    CoerceCheck -->|Yes, but empty| CoerceNone[Ok None]
    CoerceCheck -->|No| CoerceFail[Err TransformValueException]
    
    AssertSuccess --> Return([Result&lt;Option&lt;T&gt;&gt;])
    AssertFail --> Return
    CoerceSuccess --> Return
    CoerceNone --> Return
    CoerceFail --> Return
    
    style Start fill:#e1f5ff
    style Return fill:#e8f5e9
    style AssertSuccess fill:#c8e6c9
    style CoerceSuccess fill:#c8e6c9
    style CoerceNone fill:#fff9c4
    style AssertFail fill:#ffcdd2
    style CoerceFail fill:#ffcdd2
```

## Error Handling

All type validation and coercion operations return `Result<Option<T>, Throwable>` types:

- `Result::Ok(Some(value))`: Successful validation/coercion with value
- `Result::Ok(None())`: Successful validation/coercion with no value (null/empty)
- `Result::Err(exception)`: Validation/coercion failed with error

This approach ensures:
- No exceptions for normal control flow
- Explicit handling of success/failure cases
- Type-safe null handling

## License

Proprietary - See license terms in your agreement.

## Contributing

This is a private library. Please follow the established patterns and ensure all tests pass before submitting changes.