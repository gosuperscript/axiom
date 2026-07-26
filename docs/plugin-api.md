# Plugin API Reference

This reference covers the supported API for packages and applications that extend Axiom. For a guided introduction, see [Extending Axiom](extending-axiom.md). For the design behind the API, see [RFC 0001: Typesafe Axiom](rfc/0001-typesafe-axiom.md).

- [Extension entry points](#extension-entry-points)
- [Dialect composition](#dialect-composition)
- [Sources and source compilers](#sources-and-source-compilers)
    - [`Source`](#source)
    - [Compiler registration](#compiler-registration)
    - [`SourceCompilation`](#sourcecompilation)
    - [`CompiledSource`](#compiledsource)
    - [`CompiledSources`](#compiledsources)
    - [`BoundOperation`](#boundoperation)
    - [`SourceEvaluation`](#sourceevaluation)
    - [Callback results and failures](#callback-results-and-failures)
- [Types and shapes](#types-and-shapes)
    - [`Type`](#type)
    - [Built-in types](#built-in-types)
    - [Shapes](#shapes)
    - [Type relations and helpers](#type-relations-and-helpers)
- [Literal registration](#literal-registration)
- [Operator rules](#operator-rules)
    - [Fixed rules](#fixed-rules)
    - [Computed rules](#computed-rules)
    - [Rules implemented by hand](#rules-implemented-by-hand)
    - [Resolution variants](#resolution-variants)
- [Diagnostics](#diagnostics)
- [Compilation analysis](#compilation-analysis)
- [Execution observation](#execution-observation)
- [Supported boundary](#supported-boundary)

## Extension entry points

Every plugin contributes through one subclass of `Superscript\Axiom\Extension`. All hooks default to an empty collection, so override only what the package owns.

```php
abstract class Extension
{
    public function identifier(): string;

    /** @return list<BinaryOperatorRule> */
    public function operators(): array;

    /** @return list<UnaryOperatorRule> */
    public function unaryOperators(): array;

    /** @return array<class-string, callable(object): Type> */
    public function literals(): array;

    /**
     * @return array<
     *     class-string<Source>,
     *     callable(Source, SourceCompilation): CompiledSource
     * >
     */
    public function sourceCompilers(): array;
}
```

| Hook | Contribution | Ownership rule |
| --- | --- | --- |
| `operators()` | Binary operator rules | A rule owns exactly one symbol. Multiple successful rules are ambiguous. |
| `unaryOperators()` | Unary operator rules | Same semantics; absence propagates before a unary rule is invoked. |
| `literals()` | Object class to `Type` factory | Duplicate class keys are refused. Avoid overlapping parent/subclass registrations because lookup uses `instanceof`. |
| `sourceCompilers()` | Exact `Source` class to compiler callable | Ownership is exact-class. Duplicate keys and attempts to claim a core source are refused. |

`identifier()` defaults to the concrete extension class. Override it with a stable package-level identity when class names may change; compilation analysis attributes every contributed source compiler and operator selection to it. Empty identities are refused.

The extension object is the dependency-injection boundary. Put live services in its constructor and capture them in compiler or operator closures. Keep `Source` objects as persistable data.

## Dialect composition

`Superscript\Axiom\Dialect` is the compile-time collection of core and plugin contributions.

```php
$dialect = Dialect::core()->with(
    new MoneyExtension($currencies),
    new LookupExtension($repository),
);

$expression = new Expression($source, dialect: $dialect);
```

| Method | Result |
| --- | --- |
| `Dialect::core(): Dialect` | Core operators, literal inference, and source compilers. Start here for normal programs. |
| `$dialect->with(Extension ...$extensions): Dialect` | A new dialect containing the existing and contributed rules. The original is unchanged. |
| `$dialect->operators(): BinaryOperatorResolver` | Compiler-facing binary resolver. Usually accessed through `SourceCompilation::infix()`. |
| `$dialect->unaryOperators(): UnaryOperatorResolver` | Compiler-facing unary resolver. Usually accessed through `SourceCompilation::prefix()`. |
| `$dialect->literals(): LiteralTypeRegistry` | The composed object-literal registry. |
| `$dialect->sourceCompilers(): array` | The composed exact-class compiler map. |

A dialect indexes its rules once. `operators()`, `unaryOperators()`, and `literals()` build on first call and hand out the same instance afterwards; a dialect derived with `with()` indexes its own. Ask a dialect what it supports as often as you like.

### Enumerating a dialect's operators

Both resolvers answer two questions. `resolve()` answers "may these operand types use this symbol?"; `symbols()` answers "which symbols exist at all?".

```php
$dialect = Dialect::core();

$dialect->operators()->symbols();
// ['!=', '!==', '&&', '*', '+', '-', '/', '<', '<=', '=', '==', '===', '>', '>=', 'has', 'in', 'intersects', 'xor', '||']

$dialect->unaryOperators()->symbols();
// ['!', '-', 'not']

$dialect->operators()->extensions()['=='];
// ['axiom.core'] — and also your identifier once your extension contributes a '==' row
```

A caller that offers a choice of operators should read `symbols()` rather than propose a list of its own and filter it through `resolve()`: a proposed list can only ever shrink, so operators the dialect has and the caller forgot stay invisible, and every operator an extension adds needs the caller changed before anyone can pick it.

Both methods are sorted, not in registration order — composition carries no precedence, so an order derived from it would be an accident to depend on. `extensions()` returns the same keys in the same order, mapping each symbol to the distinct extension identifiers that claim it (several when extensions contribute rules for one symbol over different operand types). A rule registered without provenance — a resolver constructed directly rather than through a dialect — reports `unattributed`.

Composition has no precedence. Fixed rows that can admit a common operand type are refused when the dialect is constructed. Any remaining case in which multiple computed rules resolve is refused during compilation. Extension order never chooses an evaluation.

## Sources and source compilers

### `Source`

`Superscript\Axiom\Source` is the marker interface for a node in a stored program description.

```php
final readonly class ProductSource implements Source
{
    public function __construct(
        public Source $left,
        public Source $right,
    ) {}
}
```

A source should contain only the data needed to describe the operation. In particular:

- store nested sources in public properties, directly or in arrays, so parameter discovery and definition-cycle analysis can walk them;
- store a `SymbolSource` as a public child when the compiler will resolve that symbol;
- do not store repositories, HTTP clients, filesystems, containers, or other live services;
- persist the source tree, reconstruct the extension with its services, and then compile;
- optionally implement `Describable::describe(): string` to provide a human-readable representation of the source.

### Compiler registration

`Extension::sourceCompilers()` maps an exact source class to a callable:

```php
public function sourceCompilers(): array
{
    return [
        ProductSource::class => $this->compileProduct(...),
    ];
}

private function compileProduct(
    ProductSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    // ...
}
```

The map key is the ownership declaration. A compiler registered for a parent class does not compile subclasses. The callable is invoked once per source node during compilation, not once per program invocation or filter.

Nested compilation failures leave the callable through Axiom's private control channel and return from `Expression::compile()` as the original `TypeMismatch`. Plugin compilers therefore use straight-line PHP and return `CompiledSource`, not `Result`.

### `SourceCompilation`

The compiler capability passed to every source compiler.

| Method | Meaning |
| --- | --- |
| `child(Source $source, ?string $role = null): CompiledSource` | Compile one persisted child in the current dialect, definitions, and type environment. Give structural children a stable role for analysis output. |
| `children(array $sources): CompiledSources` | Compile named or positional children in array order. Use when no per-child work is needed before composition. |
| `combine(array $sources): CompiledSources` | Combine `CompiledSource` values already compiled or certified individually. |
| `infix(Type $left, string $operator, Type $right): BoundOperation` | Resolve one binary operation from the composed dialect at compile time. |
| `prefix(string $operator, Type $operand): BoundOperation` | Resolve one unary operation from the composed dialect at compile time. |
| `symbol(SymbolSource $symbol): CompiledSource` | Compile a persisted symbol child with normal declaration, definition, and memoization semantics. |
| `typeOfValue(mixed $value): Type` | Infer an embedded value literal-first. Object values use the dialect's literal registry. |
| `constant(Type $returns, mixed $value): CompiledSource` | Build a total constant evaluation. `null` represents absence. |
| `produces(Type $returns, callable $evaluate): CompiledSource` | Build a source without compiled children, commonly around an injected service. |
| `custom(Type $returns, callable $evaluate): CompiledSource` | Advanced lazy/control-flow evaluation. The callable may accept `SourceEvaluation`. |
| `within(string $message, callable $compile): mixed` | Add a source-specific parent message around a nested compilation refusal. |
| `reject(TypeMismatch|string $mismatch): never` | Refuse this source during compilation. The mismatch returns through `Expression::compile()`. |

`child()`, `symbol()`, `typeOfValue()`, `infix()`, and `prefix()` automatically abort the current source compiler when their underlying judgment fails. Do not catch the internal exception.

### `CompiledSource`

A compiled source couples a certified return type to its evaluation.

```php
$compiled->returns; // Type
```

| Method | Absence behavior | Callback input |
| --- | --- | --- |
| `expectPresent(Type $expected): CompiledSource` | Checks the present member of an optional type; absence remains allowed and propagates later. | None; this is a compile-time certification. |
| `mapPresent(Type $returns, callable $evaluate): CompiledSource` | An absent input stays absent and the callback is not invoked. If this source is optional, the result type is automatically `Option<$returns>`. | The present value. |
| `mapIncludingAbsent(Type $returns, callable $evaluate): CompiledSource` | The callback is always invoked. | Present value or `null`. |
| `apply(BoundOperation $operation, ?Type $returns = null): CompiledSource` | Same as `mapPresent()`. | The present value is passed to the unary operation. |
| `CompiledSource::constant(Type $returns, mixed $value): CompiledSource` | `null` is absence. | No callback; low-level equivalent of `SourceCompilation::constant()`. |
| `CompiledSource::custom(Type $returns, callable $evaluate): CompiledSource` | The callback decides. | Receives `SourceEvaluation`; low-level equivalent of `SourceCompilation::custom()`. |

For `mapPresent()`, `$returns` describes the callback's successful present value. Optionality is derived from the input and never needs to be reconstructed by the plugin. For the other methods, `$returns` describes the whole result. In every case it is a type claim, not a runtime conversion or assertion. Use an honest type and test the callback over representative values.

Prefer construction through `SourceCompilation::constant()`, `produces()`, or `custom()` so compiler code consistently uses its supplied capability. `CompiledSource`'s constructor and `node()` are internal and are not plugin APIs.

### `CompiledSources`

An ordered collection of named or positional compiled children.

| Method | Behavior |
| --- | --- |
| `mapPresent(Type $returns, callable $evaluate): CompiledSource` | Evaluates left-to-right. The first absence short-circuits later children. Invokes the callback only when every child is present; if any child is optional, the result type is automatically `Option<$returns>`. |
| `mapIncludingAbsent(Type $returns, callable $evaluate): CompiledSource` | Evaluates every child left-to-right and passes each absence as `null`. |
| `applyIncludingAbsent(BoundOperation $operation): CompiledSource` | Evaluates every operand, including absence, and invokes the operation bound against those operand types. |

String array keys become named callback arguments, so keep keys aligned with parameter names:

```php
return $compilation->combine([
    'left' => $left,
    'right' => $right,
])->mapPresent(
    new NumberType(),
    fn (int|float $left, int|float $right) => $left * $right,
);
```

Use numeric keys for ordinary positional arguments.

### `BoundOperation`

The result of `SourceCompilation::infix()` or `prefix()`:

```php
$operation->returns;       // Type selected during compilation
$operation($left, $right); // mixed; ordinary callable syntax
```

The operation is resolved once against the supplied operand types. Invoking it performs no routing or value-directed overload selection. A plain successful value is returned directly. An expected `Err` from the rule becomes the enclosing source evaluation's `Err` automatically; an uncaught exception remains a defect and propagates.

Operands are always forwarded positionally. Names used to organize compiled children belong to the source compiler and are discarded before the dialect-owned evaluation closure is invoked.

The runtime values passed to the operation must inhabit the operand types used to bind it. A source compiler that supplies values itself owns that admission guarantee.

### `SourceEvaluation`

Available only inside `SourceCompilation::custom()`:

| Method | Meaning |
| --- | --- |
| `value(CompiledSource $source): mixed` | Evaluate an already-compiled child in the current invocation. Returns its value or `null` for absence; propagates its expected failure. |
| `annotate(string $key, mixed $value): void` | Attach domain-specific metadata to the current source's observation node. No-op when the invocation has no observer. |

Use `custom()` only when ordinary mapping cannot express the source, such as lazy fallback, conditional child evaluation, or source-specific annotations. It is not a way to recover `Runtime` or perform dynamic compilation.

Do not broadly catch `RuntimeException` around `value()`. Expected child failures use a private exception channel to leave the callback and become the enclosing program's `Err`; swallowing that channel changes program semantics. Catch only the concrete domain exceptions your callback owns.

### Callback results and failures

All source evaluation callbacks follow one convention:

| Callback outcome | Program outcome |
| --- | --- |
| Plain non-null value | `Ok(Some(value))` |
| `null` | `Ok(None())` — structural absence |
| `Ok(value)` | `Ok(Some(value))`; `Ok(null)` is absence |
| `Err(Throwable)` | Expected value-dependent evaluation failure |
| Thrown exception | Plugin defect; it propagates and is observable as `Threw` |

Expected runtime failures include a missing remote record or a value-dependent domain rejection that static types cannot rule out. Misconfigured services, impossible callback inputs, and return values that violate the declared type are defects, not normal `Err` results.

## Types and shapes

### `Type`

A custom type implements `Superscript\Axiom\Types\Type<T>`:

```php
interface Type extends Shaped
{
    /** @return Result<Option<T>, Throwable> */
    public function assert(mixed $value): Result;

    /** @return Result<Option<T>, Throwable> */
    public function coerce(mixed $value): Result;

    public function format(mixed $value): string;

    public function shape(): Shape;
}
```

- `assert()` is strict membership and must not convert.
- `coerce()` is the lenient boundary conversion.
- `format()` renders an admitted value for humans.
- `shape()` projects the type into Axiom's sealed structural vocabulary.

Two laws are mandatory:

1. every present value produced by `coerce()` must pass `assert()`;
2. every value admitted by the type must have the runtime structure claimed by `shape()`.

### Built-in types

Plugin code can use these as declarations, return types, fields, operands, and shape parameters.

| Type | Constructor / factory | Domain |
| --- | --- | --- |
| `BooleanType` | `new BooleanType()` | `bool` |
| `NumberType` | `new NumberType()` | `int|float` |
| `StringType` | `new StringType()` | `string` |
| `LiteralType` | `new LiteralType(bool|int|float|string $value)` | One scalar value |
| `OptionType` | `new OptionType(Type $inner)` | `null` or an inner value |
| `UnionType` | `new UnionType(Type ...$members)` | Any member |
| `UnionType::join()` | `UnionType::join(Type ...$types)` | Canonical least union; no inputs yields `NeverType` |
| `ListType` | `new ListType(Type $type, ?int $min = null, ?int $max = null)` | Length-bounded list |
| `DictType` | `new DictType(Type $type)` | String-keyed homogeneous map |
| `RecordType` | `new RecordType(array<string, Type> $fields)` | Exact named record |
| `UnknownType` | `new UnknownType()` | Genuinely untyped value; inert at operators and member access |
| `NeverType` | `new NeverType()` | Empty value set; normally derived rather than declared |

`OpaqueType` is an internal reified stand-in and cannot verify host values. A plugin declaring an opaque domain must implement its own `Type` with real `assert()`/`coerce()` methods and return an `OpaqueShape`.

### Shapes

Shapes are value-set descriptions, not runtime validators. Custom types project into one of these constructors:

| Shape | Construction | Meaning |
| --- | --- | --- |
| `BooleanShape` | `new BooleanShape()` | Boolean values |
| `NumberShape` | `new NumberShape()` | Numeric values |
| `StringShape` | `new StringShape()` | String values |
| `LiteralShape` | `new LiteralShape($value)` | One scalar value |
| `OptionShape` | `new OptionShape($inner)` | `null` plus the inner domain; nesting collapses |
| `UnionShape` | `UnionShape::of(...$members)` | Canonical alternatives; construct through `of()` |
| `ListShape` | `new ListShape($element, $min = 0, $max = null)` | Length-bounded list |
| `DictShape` | `new DictShape($value)` | String-keyed homogeneous map |
| `RecordShape` | `new RecordShape($fields)` | Exact named fields |
| `OpaqueShape` | `new OpaqueShape($identity, $parameters = [])` | Nominal identity with covariant structural parameters |
| `UnknownShape` | `new UnknownShape()` | No static knowledge |
| `NeverShape` | `new NeverShape()` | No possible value |

Every shape implements `equals(Shape $other): bool`. Do not infer assignability or compatibility from `equals()`; use `TypeRelations`.

`ShapeDomain::all(Shape $shape, callable $leaf): bool` answers whether a rule's leaf predicate supports every reachable value domain in a composite shape. It treats `Unknown` as unsupported and `Never` as vacuously supported. This is primarily useful for advanced structural operator rules.

### Type relations and helpers

All relation methods return `Ok(true)` or `Err(TypeMismatch)`, never a boolean false.

| Method | Question |
| --- | --- |
| `TypeRelations::isTypeAssignableTo($source, $target)` | Can every source value flow into the target slot? |
| `TypeRelations::areEquivalent($a, $b)` | Are both types mutually assignable? |
| `TypeRelations::overlaps($a, $b)` | Could one value inhabit both types? This does not establish operator support. |
| `TypeRelations::admits($operand, $slot)` | Can this operand type reach this operator slot? Same basis as assignability, but `Unknown` is explicitly inert. |
| `TypeRelations::jointlyAdmissible($a, $b)` | Could one operand type reach both slots? Used for fixed-row ambiguity. |
| `TypeRelations::assignable($sourceShape, $targetShape)` | Shape-level assignability. |
| `TypeRelations::shapesEquivalent($a, $b)` | Shape-level equivalence. |
| `TypeRelations::shapesOverlap($a, $b)` | Shape-level overlap. |
| `TypeRelations::shapesJointlyAdmissible($a, $b)` | Shape-level joint admissibility. |

Helpers:

- `TypeDescriber::describe(Type $type): string`, `describeShape(Shape $shape): string`, and `describeClass(class-string<Type> $type): string` are the single diagnostic rendering authority.
- `TypeReifier::reify(Shape $shape): Type` constructs the canonical core type for a shape. Reifying `OpaqueShape` produces an internal non-admitting stand-in; retain your domain type when runtime membership matters.

## Literal registration

Register an object value class when that object may appear directly in a `StaticSource` or be passed to `SourceCompilation::typeOfValue()`:

```php
public function literals(): array
{
    return [
        Money::class => fn (Money $value): Type => new MoneyType($value->getCurrency()->getCurrencyCode()),
    ];
}
```

The factory is a static typing function, not a conversion. Its returned type must assert the value it receives. Scalars and arrays are typed structurally and never reach this registry. An unregistered object literal is a compile error rather than `Unknown`.

Lookup uses `instanceof`. Prefer concrete non-overlapping registrations; a parent mapping and child mapping can both match the same value.

## Operator rules

Register binary rules through `Extension::operators()` and unary rules through `Extension::unaryOperators()`. Each rule advertises one operator symbol.

### Fixed rules

Use a fixed row when operand and return types are known at extension composition time:

```php
Operator::infix('+')
    ->identifiedBy('money.eur.add')
    ->takes(new MoneyType('EUR'), new MoneyType('EUR'))
    ->returns(new MoneyType('EUR'))
    ->evaluatesWith(fn (Money $left, Money $right) => $left->plus($right));

Operator::prefix('negate')
    ->identifiedBy('money.eur.negate')
    ->takes(new MoneyType('EUR'))
    ->returns(new MoneyType('EUR'))
    ->evaluatesWith(fn (Money $money) => $money->negated());
```

The staged chains are:

```text
Operator::infix(symbol)  → identifiedBy(id) → takes(left, right) → returns(type) → evaluatesWith(callable)
Operator::prefix(symbol) → identifiedBy(id) → takes(operand)     → returns(type) → evaluatesWith(callable)
```

`identifiedBy()` is optional but recommended for public rules: it gives the selected rule a stable semantic identity in compilation analysis. Without it, fixed and computed builders derive a deterministic fallback. The last call returns `BinaryOperatorRule` or `UnaryOperatorRule`. A prefix row cannot take an `OptionType`: unary absence propagates structurally before rule evaluation.

### Computed rules

Use a typed computed rule when the concrete `Type` classes identify ownership but type data determines the verdict or return type:

```php
Operator::infix('+')
    ->identifiedBy('money.add')
    ->matching(MoneyType::class, MoneyType::class)
    ->resolvesWith(function (MoneyType $left, MoneyType $right): OperatorResolution {
        if ($left->currency !== $right->currency) {
            return Operation::unsupported('Money currencies must match.');
        }

        return Operation::returns($left)
            ->evaluatesWith(fn (Money $a, Money $b) => $a->plus($b));
    });
```

Unary form:

```text
Operator::prefix(symbol) → matching(TypeClass::class) → resolvesWith(callable)
```

Non-matching type classes are refused automatically and never invoke the callback. Matching uses `instanceof`, so subclasses of the named `Type` class are included; use a final type class or an explicit early refusal when a hierarchy needs narrower ownership.

`Operation` provides concise constructors:

| Method | Result |
| --- | --- |
| `Operation::returns(Type $type)->evaluatesWith(callable $evaluation)` | `ResolvedOperation` |
| `Operation::unsupported(string $message, array $causes = [])` | `UnsupportedOperation` |
| `Operation::dead(string $message, array $causes = [])` | `DeadOperation` |

### Rules implemented by hand

Use the interfaces directly for fully structural judgments, absence-aware binary semantics, or logic that cannot be guarded by concrete `Type` classes:

```php
interface BinaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $left, Type $right): OperatorResolution;
}

interface UnaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $operand): OperatorResolution;
}
```

A hand-written rule may also implement `IdentifiedOperatorRule::identifier()`. Otherwise its concrete implementation class is its analysis identity.

`resolve()` judges types only. It must not inspect runtime values or route aliases. Register aliases as separate rule instances, each returning its own symbol from `operator()`.

### Resolution variants

`OperatorResolution` has three supported variants:

| Variant | Meaning |
| --- | --- |
| `ResolvedOperation(Type $returns, Closure $evaluation)` | This rule certifies the operands. The closure is bound into the program. |
| `UnsupportedOperation(string $message, array $causes = [])` | The rule owns the symbol but rejects these operand types. |
| `DeadOperation(string $message, array $causes = [])` | The operation is valid in principle but statically constant or meaningless. |

A `ResolvedOperation` evaluation may return a plain value or a `Result`. A plain value is wrapped in `Ok`; a returned `Result` passes through; a thrown exception propagates. The closure must be total over every runtime value admitted by the operand types, and every successful result must inhabit `$returns`.

`ResolvedOperation::evaluate(mixed ...$operands): Result` is the direct evaluation method used by compiler infrastructure and totality tests. Source compilers normally receive its `BoundOperation` wrapper from `SourceCompilation`, which removes the `Result` ceremony and propagates expected failures for them.

## Diagnostics

`TypeMismatch` is the public negative type judgment:

```php
new TypeMismatch(
    message: 'Money currencies must match.',
    causes: [$cause],
    dead: false,
);
```

| Member | Meaning |
| --- | --- |
| `$message` | Human-readable local verdict. |
| `$causes` | Nested `TypeMismatch` causes, preserving context. |
| `$dead` | The program is well-formed in principle but statically meaningless. Normally set by the compiler when converting `DeadOperation`. |
| `$path` | Where the refusal was made, in the same language compilation analysis uses — or `null` when the verdict is not about a node. See below. |
| `describe(): string` | Render the complete indented cause tree. Paths are not rendered; read them from `$path`. |

Use `SourceCompilation::reject()` for a compiler-owned refusal and `within()` to add context. Use `UnsupportedOperation` or `DeadOperation` at an operator-rule boundary. Do not throw `TypeMismatch`; it is a value, not an exception. A compiler-owned refusal needs no path: the compiler stamps the failing node's own path on the way out.

### Which node refused

`(name + 1) * 2` with `name` declared `String` fails at the inner `+`, and says so:

```php
$failure = $expression->compile()->unwrapErr();

$failure->message;           // '[+] expects Number and Number; got String and 1.'
$failure->path;              // '$.children[0].node'
$failure->causes[0]->path;   // null
```

`$path` is the [compilation-analysis](#compilation-analysis) path of that node — the same string `$analysis->toArray()` gives it when the tree compiles — so one addressing scheme serves both channels, and the ancestor chain falls out of the prefixes. The path names the **deepest** node that refused, since that is the one to point at.

`null` means the verdict is not about a node, and reads as "not a node's fault" rather than "location unknown". Two kinds keep it:

- **Whole-program properties.** A definition cycle is a property of the definition graph, refused before any node is walked.
- **Claims about types rather than nodes.** The cause above, `String is not assignable to Number.`, comes from a relation given two types; nothing at that level knows which node produced either, because `infix()` receives types, not the children they came from.

Refusals that a compiler wraps with `within()` carry one path per level, outermost first — a match arm body that will not type gives the match node's path on the wrapper and the arm's path on the cause.

## Compilation analysis

Successful compilation exposes its data-only explanation as `Program::$analysis`; `Expression::analyze()` returns the same kind of artifact without requiring the caller to keep the program.

| API | Result |
| --- | --- |
| `$analysis->root` | The typed `CompilationNode` graph, including source classes, source-compiler extension identities, named children, and local operator selections. |
| `$analysis->operators()` | A flat list of `LocatedOperatorSelection` values with deterministic source and selection paths. |
| `$analysis->toArray(bool $revealLiterals = false)` | Versioned serializable representation. Literal values inside inferred types are redacted by default. |
| `json_encode($analysis)` | The default redacted representation through `JsonSerializable`. |

An operator selection retains its typed operands and return type plus `OperatorRuleProvenance`: stable identifier, implementation class, and owning extension. The artifact never contains evaluation closures or collaborators captured by source compilers. Treat it as an explanation and audit format, not as an alternative serialization of the authoring `Source` tree.

## Execution observation

Hosts and tracing plugins implement `Superscript\Axiom\Execution\Observer`:

```php
interface Observer
{
    public function observe(Event $event): void;
}

$program($bindings, observer: $observer);
```

Every event exposes a `Node` with:

- `$sourceType`: the concrete source class;
- `$returns`: the source's certified return type.

Event variants:

| Event | Additional data |
| --- | --- |
| `Entered` | The node is about to evaluate. |
| `Exited` | `$result`, the node's `Result<Option<mixed>, Throwable>`. |
| `Annotated` | `$key` and `$value`, emitted by core or `SourceEvaluation::annotate()`. |
| `Threw` | `$exception`, for a defect that escaped evaluation. |

Events are ordered and nested. Observation does not change evaluation results. Plugin source compilers can add annotations only from a `custom()` evaluation; ordinary composed sources receive their node lifecycle automatically.

## Supported boundary

Plugin code is expected to depend on the APIs documented here. The following classes may be public for compiler implementation or testing but are not extension-facing APIs:

- `CompiledNode` and `Runtime`;
- `CompilationAborted` and `EvaluationAborted`;
- `CoreSourceCompilers` and classes under `SourceCompilers`;
- direct construction of `SourceCompilation`, `CompiledSource`, `CompiledSources`, `BoundOperation`, or `SourceEvaluation`;
- `BinaryOperatorResolver` and `UnaryOperatorResolver` as runtime services;
- `TypeInference` and `TypeEnvironment` for implementing source compilers.

Use `Extension`, `Dialect`, and the capabilities passed to your compiler instead. That keeps persisted sources serializable, plugin code independent of Axiom's execution representation, and compiled programs free of runtime dispatch.
