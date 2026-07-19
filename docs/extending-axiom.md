# Extending Axiom

- [Introduction](#introduction)
- [Extensions and Dialects](#extensions-and-dialects)
- [Custom Types](#custom-types)
    - [The Runtime Contract](#the-runtime-contract)
    - [Choosing a Shape](#choosing-a-shape)
    - [Record Projections](#record-projections)
    - [Opaque Projections](#opaque-projections)
    - [Scalar Refinements](#scalar-refinements)
    - [Optional Values](#optional-values)
- [Literal Registration](#literal-registration)
- [Custom Operators](#custom-operators)
    - [Declaring Rules](#declaring-rules)
    - [The Evaluation Closure](#the-evaluation-closure)
    - [Ambiguity Is Refused](#ambiguity-is-refused)
    - [Parameterized Families](#parameterized-families)
    - [Computed Rules](#computed-rules)
    - [Writing a Rule by Hand](#writing-a-rule-by-hand)
- [Host Sources](#host-sources)
    - [The Source Compiler Contract](#the-source-compiler-contract)
    - [Step 1: Return a Stored Value](#step-1-return-a-stored-value)
    - [Step 2: Compile and Check One Child](#step-2-compile-and-check-one-child)
    - [Step 3: Combine Two Children](#step-3-combine-two-children)
    - [Step 4: Use the Dialect's Operation](#step-4-use-the-dialects-operation)
    - [Step 5: Inject a Live Service](#step-5-inject-a-live-service)
    - [Step 6: Report Failures at the Right Boundary](#step-6-report-failures-at-the-right-boundary)
    - [Step 7: Choose Absence Semantics](#step-7-choose-absence-semantics)
    - [Step 8: Add Lazy Control Flow](#step-8-add-lazy-control-flow)
    - [Symbols Must Stay Visible](#symbols-must-stay-visible)
    - [Resolving Operations Over Host Values](#resolving-operations-over-host-values)
    - [Claiming a Type Honestly](#claiming-a-type-honestly)
- [Testing Your Extension](#testing-your-extension)
    - [The Shape Census](#the-shape-census)
    - [The Admission-Honesty Law](#the-admission-honesty-law)
    - [The Totality Harness](#the-totality-harness)
- [What Stays Yours](#what-stays-yours)

## Introduction

Axiom's core is small on purpose. Everything domain-specific — money, dates, lookup tables, geocoding — lives in extensions. An extension can contribute types, operators, literals, and data sources, and each one becomes a full citizen of the compiler without touching core.

One principle drives every seam in this guide: **a rule's typing and its evaluation are one statement.** When you declare an operator, the same declaration carries the return type and the closure that computes it. When you compile a source, the same result carries the type it claims and the evaluation that delivers it. The compiler binds these resolutions directly into the `Program`, and a compiled program performs no runtime dispatch. There are never two faces to keep in agreement, so the static and runtime semantics cannot drift.

If you want the full background on this design, read [RFC 0001: Typesafe Axiom](rfc/0001-typesafe-axiom.md). For exact signatures and behavior after working through the examples, use the [Plugin API Reference](plugin-api.md).

## Extensions and Dialects

Everything your package contributes travels in one class: an `Extension`. It is an abstract class with empty defaults, so you override only the methods for what you actually ship — and future hooks (matchers) can be added without breaking you:

```php
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;

final class TimeExtension extends Extension
{
    public function identifier(): string
    {
        return 'time';
    }

    public function operators(): array
    {
        return [
            Operator::infix('-')
                ->identifiedBy('time.date.minus-period')
                ->takes(new DateType(), new PeriodType())
                ->returns(new DateType())
                ->evaluatesWith(fn (Date $d, Period $p) => $d->minus($p)),
            Operator::infix('-')
                ->identifiedBy('time.date.difference')
                ->takes(new DateType(), new DateType())
                ->returns(new PeriodType())
                ->evaluatesWith(fn (Date $a, Date $b) => $a->until($b)),
        ];
    }

    public function literals(): array
    {
        // Types a Date object embedded in an expression — see "Literal Registration" below.
        return [Date::class => fn(Date $d) => new DateType()];
    }
}
```

The host composes a `Dialect` once and hands it to the `Expression`:

```php
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;

$dialect = Dialect::core()->with(new TimeExtension(), new MoneyExtension());

$expression = new Expression($source, dialect: $dialect, declarations: [...]);

$expression->check(new BooleanType());              // the compiler resolves through the dialect...
$program = $expression->compile()->unwrap();
$program(['effective' => $date]);                   // ...and the program runs what it resolved.
```

Composition is unambiguous by construction. Your `-` row resolves `(Date, Period) → Date` while core's arithmetic refuses it, so the compiler takes your lone resolution. If two rules both resolved the same operand types, compilation fails naming both — there is no silent winner, and list order decides nothing. The same goes for every kind of collision: overlapping operator rows are a construction error the moment the dialect is built, and duplicate literal registrations across extensions are equally loud, never a precedence question.

There is no other wiring path. A compiled program embeds the resolutions of the dialect it was compiled with and carries no dialect at runtime, so running with different rules than you compiled with is not representable.

The rest of this guide walks each contribution in the order you typically need them: the types your domain values inhabit, the literals that give your embedded objects those types, the operators that act on them, the sources that produce them, and the tests that keep it all honest.

## Custom Types

A type implements the `Type` interface, which has two halves: a **runtime contract** (`assert`, `coerce`, `format`) that handles real values at the boundary, and a **static contract** (`shape()`) that tells the compiler what your values look like.

### The Runtime Contract

Three methods handle values at runtime:

- `assert(mixed): Result<Option<T>>` — strict membership. Is this value *already* of the type? Never lenient.
- `coerce(mixed): Result<Option<T>>` — the lenient input boundary. Convert anything that can reasonably be read as the type. If an empty CSV cell should mean "no value", this is the place — and the only place — to say so.
- `format(T): string` — render a value for humans.

### Choosing a Shape

Type relations — assignability, overlap, and friends — are not defined on your class. They are defined by structural recursion over a **sealed vocabulary of shapes** (`Superscript\Axiom\Types\Shapes`). Your type *projects* into that vocabulary via `shape()`. You can never add a shape constructor or edit a relation, so adding a type means picking the shape that describes it. An unmodelled type is unrepresentable, not silently incompatible.

Before picking, understand the one law everything else depends on:

> [!IMPORTANT]
> **The shape-truth law.** The shape you project is a claim about what your values look like at runtime, and the compiler certifies programs against that claim without ever re-checking it. Project a `NumberShape` and your values had better be numbers. Project a `RecordShape(['lat' => …])` and member access had better be able to read `lat` from every value. A projection that promises more than the values deliver produces programs that are certified — and still crash. This isn't the honor system: the census tests ([Testing Your Extension](#testing-your-extension)) verify your projection against real specimens.

Pick your projection from this table, then read its section below:

| Your type is… | Project as |
| --- | --- |
| Genuinely a record — an array whose fields member access can reach (an address, a JSON-shaped position) | `RecordShape([...fields])` |
| Nominal — identity matters, structure shouldn't leak (a claim ID) | `OpaqueShape('ClaimId')` |
| An object-valued domain type that needs parameterized subtyping (a `Money` class with a currency) | `OpaqueShape` with structural parameters |
| A refinement of a scalar (an email is a string) | The base shape (`StringShape`) |

### Record Projections

Project `RecordShape` only when your values *are* array-shaped records that the member-access mechanism can read. In return, member access on every declared field is certified, and field types flow into inference.

A few things to keep in mind:

- **Records are exact.** Your fields fully describe the value set — a value with undeclared extra keys is a non-member under `assert` (though `coerce` will take the declared slice of wider input). "These fields plus who-knows-what" is not a record; it's a `Dict`.
- **An optional field is a field whose shape is `OptionShape`.** There is no separate presence flag, and record coercion canonicalizes a missing optional key to a present `null`.
- **Never project fictional fields.** It can be tempting to encode a brand as a record with literal discriminant fields — `{kind: 'money', currency: 'GBP', amount: Number}` — the way TypeScript's branded types work. That trick is safe in TypeScript only because its types are erased and never meet a runtime. Here, the projection leaks through assignability into record slots, and the certified member accesses then crash on the real object. Project a record only when the values genuinely are such arrays — the census tests will hold you to it.

### Opaque Projections

Project `OpaqueShape` for object-valued domain types and nominal identities. Your type will relate only to opaques of the same identity, so no core rule can ever accidentally claim your values.

- **No structural claims means no member access.** `money.amount` is a compile error unless your host exposes it another way. That is the point — nothing is certified that your object can't deliver.
- **Parameters give you subtyping without structure.** Parameters relate by the ordinary rules under the same identity: `Money<GBP>` is assignable to a `Money<GBP|USD>` slot (because `'GBP' ⊆ 'GBP'|'USD'`), while `Money<GBP>` and `Money<USD>` share no values — and no relation code anywhere has to mention money:

```php
use Superscript\Axiom\Types\Shapes;

final readonly class MoneyType implements Type
{
    public function __construct(private string $currency) {}

    // ... assert / coerce / format ...

    public function shape(): Shapes\Shape
    {
        return new Shapes\OpaqueShape('money', [
            'currency' => new Shapes\LiteralShape($this->currency),
        ]);
    }
}
```

- **Every operator must be yours.** Core refuses opaques everywhere — including `==`, because object equality belongs to you. Every operation on your type is a rule you ship ([Custom Operators](#custom-operators)).

### Scalar Refinements

Project the base shape (`StringShape` for an email) when your type is a validated subset of a scalar.

The refinement lives only in `assert` and `coerce`. It is enforced whenever a value crosses a boundary — typed bindings, `Coerce`, `Ascription` — and nowhere else. Statically, your type *is* the base: an email fills any `String` slot, `email == string` compares as strings, and core's string rules apply unchanged.

For refinements, that is usually exactly what you want. If it isn't — if treating your values as plain scalars ever produces wrong behavior — you wanted an opaque, not a refinement.

Here is a complete refinement type:

```php
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

final class EmailType implements Type
{
    public function assert(mixed $value): Result
    {
        // Strict membership: only an actual, valid email string passes.
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($value));
        }

        return new Err(new TransformValueException(type: 'email', value: $value));
    }

    public function coerce(mixed $value): Result
    {
        if (!is_string($value) && !$value instanceof \Stringable) {
            return new Err(new TransformValueException(type: 'email', value: $value));
        }

        // The conversions: Stringable objects become strings, surrounding
        // whitespace is dropped (' a@b.co ' reads as an email) — then the
        // result must pass strict membership like any other value.
        return $this->assert(trim((string) $value));
    }

    public function format(mixed $value): string
    {
        return (string) $value;
    }

    public function shape(): Shapes\Shape
    {
        return new Shapes\StringShape();
    }
}
```

### Optional Values

Optionality is a type, not a flag: `OptionType<T>` denotes `{null} ∪ T`. Your extension inherits the consequences for free:

- A present `T` fills an `Option<T>` slot, and `Option<Option<T>>` collapses.
- `OptionType::coerce(null)` yields a **present** `Some(null)` — absence is a legal value of the option, not a failed coercion. An optional record field is simply a field whose type is `OptionType`, and `RecordType` coercion canonicalizes a missing optional key to a present `null`.
- Don't hand-roll "nullable" variants of your types — wrap them.

## Literal Registration

Your types exist; now your values need to receive them. When inference meets a `StaticSource` holding one of your objects, it consults the literal registry — the mapping from PHP value classes to types, contributed via your `Extension::literals()`:

```php
public function literals(): array
{
    return [Money::class => fn(Money $value) => new MoneyType($value->currency())];
}
```

The factory receives the value, so the type can be as precise as the value determines: `Money('GBP', 100)` types as `Money<GBP>`, not just "money".

This is a purely static lookup. The object is already part of the program, so nothing is converted or validated here; values arriving at *runtime* are admitted by `assert`/`coerce` at the boundary and the bridge nodes, which never consult this registry. The factory's one obligation is honesty: the type it returns must `assert` the very value it was derived from, and the census ([Testing Your Extension](#testing-your-extension)) checks exactly that.

An unregistered object literal is an inference *error*, with a message pointing at the registry — never a silent `Unknown`. For domain literals whose class determines nothing, wrap the source in a `Coerce` or `Ascription` node: the author's explicit type.

## Custom Operators

Operators are the centerpiece seam. The front door is one declaration per fixed rule: a row in a dispatch table.

### Declaring Rules

Here is date arithmetic, complete:

```php
use Superscript\Axiom\Operators\Operator;

Operator::infix('-')
    ->identifiedBy('time.date.minus-period')
    ->takes(new DateType(), new PeriodType())
    ->returns(new DateType())
    ->evaluatesWith(fn (Date $d, Period $p) => $d->minus($p)),

Operator::infix('-')
    ->identifiedBy('time.date.difference')
    ->takes(new DateType(), new DateType())
    ->returns(new PeriodType())
    ->evaluatesWith(fn (Date $a, Date $b) => $a->until($b)),

Operator::prefix('abs')
    ->identifiedBy('number.absolute')
    ->takes(new NumberType())
    ->returns(new NumberType())
    ->evaluatesWith(fn (int|float $n) => abs($n)),
```

When the compiler asks about your operator over some operand types, the row checks admissibility against its declared types — producing mismatch messages that read uniformly with core's, like `[-] expects Date and Period; got Date and String.` — and on success returns its declared return type together with your closure. The compiler binds that closure into the program. **No dispatch happens at runtime**, and your closure only ever sees values of the operand types you declared, because the compiler proved them and the boundary admitted them.

The chain is staged — optional `identifiedBy` → `takes` → `returns` → `evaluatesWith` — and the final `evaluatesWith(...)` call completes and returns the rule. Use a stable semantic identity for rules you want hosts to audit over time; a deterministic fallback is provided when it is omitted. There is no `build()` to forget, and a half-declared rule is unrepresentable.

> [!NOTE]
> One asymmetry: `Operator::prefix` **loudly rejects an `Option` operand type**. Absence never reaches a unary rule — the compiled node short-circuits absent operands and optionality propagates structurally — so a prefix rule taking `Option` would declare a claim that can never fire. Declare the present type instead.

### The Evaluation Closure

Your closure's contract:

- **Take parameters natively typed.** `fn (Carbon $l, Period $r) => …` is fine — the values are proven before the closure runs.
- **Return a plain value.** It is wrapped in `Ok` for you.
- **Return an `Err` for value-dependent errors.** Division by zero, an overflowing add, a date outside your calendar's range — the type check cannot rule these out (the types were fine; the values weren't), so they are legitimate evaluation results the program reports to its caller. Core's division does exactly this:

  ```php
  Operator::infix('/')->takes($number, $number)->returns($number)
      ->evaluatesWith(fn (int|float $l, int|float $r) => attempt(fn () => $l / $r)),
      // attempt() catches DivisionByZeroError and returns it as Err
  ```

- **Let exceptions propagate.** The compiler guarantees the closure only sees values of its declared types, so an uncaught throw is a defect in your extension, not a property of the input. It should crash in your stack trace, not masquerade as an evaluation error.
- **Be total over the declared operand types.** Every value of those types must evaluate without escaping. This is the one obligation you carry, and the totality harness checks it generatively ([Testing Your Extension](#testing-your-extension)).

### Ambiguity Is Refused

Ambiguity is refused, never ranked. Two rows for the same operator whose slots are **jointly admissible** — meaning some operand type would resolve both — are a `Dialect` construction error, refused at the earliest moment it exists. Which evaluation runs must never depend on registration order.

The relation is about operand *types*, not values. A `List` row beside a `Dict` row is a legal pair: the empty array inhabits both types, but no compilable operand type reaches both rows. A `Literal(5)` row beside a `Number` row is refused: a `5`-typed operand resolves both, and there is no precedence to pick a winner — specialization included. Declare disjoint rows (see the money pattern below), or hand-write a type function that refuses what another rule owns.

### Parameterized Families

A fixed rule's return type is fixed. Some rules are *parameterized* — money, where `Money<'GBP'> + Money<'GBP'> → Money<'GBP'>` but `Money<'GBP'> + Money<'USD'>` must be refused. Declare these by **enumerating over the parameter space**, which is host-finite at composition time:

```php
final class MoneyExtension extends Extension
{
    /** @param non-empty-list<string> $currencies the host's configured set */
    public function __construct(private readonly array $currencies) {}

    public function operators(): array
    {
        return array_map(
            fn (string $c) => Operator::infix('+')
                ->takes(new MoneyType($c), new MoneyType($c))
                ->returns(new MoneyType($c))
                ->evaluatesWith(fn (Money $a, Money $b) => $a->plus($b)),
            $this->currencies,
        );
    }
}
```

This works because currency is part of the type's *value set*, so the rows are disjoint. Same-currency pairs resolve to their row. A cross-currency pair matches *no* row, so the compiler reports the composed dialect's honest aggregate — `No overload of [+] accepts Money<'GBP'> and Money<'USD'>.` — and no program containing that expression can be compiled, let alone run.

What enumeration cannot express is a return type that is a *function* of operand types over an unbounded space, like `List<T> ++ List<U> → List<T|U>`. That is escape-hatch territory ([Writing a Rule by Hand](#writing-a-rule-by-hand)).

Your package owns value equality for its opaque values in exactly the same way. For a Brick Money-backed type, a same-currency row can bind Brick's domain comparison rather than PHP object identity:

```php
Operator::infix('==')
    ->takes(new MoneyType($currency), new MoneyType($currency))
    ->returns(new BooleanType())
    ->evaluatesWith(fn (Money $left, Money $right) => $left->isSameValueAs($right));
```

Core's equality rule refuses these opaque operands, so your row is the lone successful resolution. Register the negated aliases as separate rows with the negation captured in their evaluation, just as core does for its own aliases.

### Computed Rules

When the operand `Type` classes are known but the verdict depends on their data, use `matching()->resolvesWith()`. Axiom supplies the class guard and the ordinary unsupported verdict, so the callback receives the concrete types it owns:

```php
Operator::infix('+')
    ->matching(MoneyType::class, MoneyType::class)
    ->resolvesWith(function (MoneyType $left, MoneyType $right) {
        if ($left->currency !== $right->currency) {
            return Operation::unsupported('Money currencies must match.');
        }

        return Operation::returns($left)
            ->evaluatesWith(fn (Money $a, Money $b) => $a->plus($b));
    });
```

Use `Operation::dead()` for a statically meaningless pair. `Operator::prefix(...)->matching(...)->resolvesWith(...)` is the unary equivalent.

### Writing a Rule by Hand

A fixed rule is a row; some rules are not rows. Implement `BinaryOperatorRule` (or `UnaryOperatorRule`) directly when you need:

- **Verdicts that are relations, not slots.** Core's equality first establishes that its value evaluator supports both operand domains, then asks whether they overlap. No fixed operand type expresses that pair of judgments.
- **Dead findings.** Return `DeadOperation` for an operation that is *statically constant*, so hosts can render it as a probable author bug rather than an unsupported operation.
- **Return types computed from operand types** over an unbounded space (`List<T> ++ List<U> → List<T|U>`).
- **Absence-tolerant rules.** A rule that wants absence-as-zero arithmetic resolves operand types where a side is `Option`-shaped — and *refuses present-present pairs*, which stay core's. That disjointness is what keeps the composition unambiguous. Its closure then handles `null`.

The interface is one obligation:

```php
interface BinaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $left, Type $right): OperatorResolution;
}
```

The result is one explicit variant:

- `new ResolvedOperation($returnType, $closure)` certifies the operand types. The closure must be total over every value pair of them, and its result must inhabit the return type. Value-dependent partiality remains: a closure may return an `Err`.
- `new UnsupportedOperation($message, $causes)` means the rule owns the operator but rejects these operand types.
- `new DeadOperation($message, $causes)` means the operation is valid in principle but statically meaningless. The compiler converts this to a dead `TypeMismatch` for host-facing diagnostics.

A rule never branches on a symbol: `operator()` advertises its sole symbol, and the resolver only invokes rules in that bucket. A computed absence-as-zero rule can therefore use concise early returns:

```php
final readonly class AddWithAbsentAsZero implements BinaryOperatorRule
{
    public function operator(): string
    {
        return '+';
    }

    public function resolve(Type $left, Type $right): OperatorResolution
    {
        if (!$left->shape() instanceof OptionShape && !$right->shape() instanceof OptionShape) {
            return new UnsupportedOperation('Present pairs belong to the ordinary numeric row.');
        }

        if (TypeRelations::admits($left, new OptionType(new NumberType()))->isErr()
            || TypeRelations::admits($right, new OptionType(new NumberType()))->isErr()) {
            return new UnsupportedOperation('[+] with absence requires optional numbers.');
        }

        return new ResolvedOperation(
            new NumberType(),
            fn (?float $left, ?float $right) => ($left ?? 0) + ($right ?? 0),
        );
    }
}
```

Use the relation registry rather than hand-rolling type tests — it is what keeps rules consistent with each other:

- `TypeRelations::admits($operand, $slot)` — may values of this operand type reach a slot of this type? Assignability, pessimistic on unions, with no `Unknown` hole: `Unknown` is inert, and your rule should refuse it too (using `admits` gives you that for free). It is also how "refuses `Option`" falls out: `Option<Number>` is not assignable to a present `Number` slot.
- `TypeRelations::overlaps($a, $b)` — could any *value* satisfy both? A liveness judgment used only after the operation has established support; it does not make types comparable by itself.
- `TypeRelations::jointlyAdmissible($a, $b)` — could any operand *type* be admitted by both slots? This is the row-ambiguity judgment the `Dialect` runs at construction, useful when your hand-written rule needs to reason about collisions the way the dialect does.

Orderability has exactly one authority: whether the dialect resolves `<` for the type. Shipping ordering rows *is* declaring the type ordered.

Core's rules (`src/Operators/`) are the reference implementations. `Equality` separates `ValueEquality::supports()` from overlap-based dead verdicts and bakes negation into the closure at resolve time; `Has` and `In` show shared operand judgments via `SetOperands`.

## Host Sources

Your host may contribute its own `Source` kinds — a stored value, a lookup-table filter, a geocoding call. This section builds a source compiler one concept at a time. The examples start deliberately small and grow into the same composition tools used by Axiom's core compilers.

### The Source Compiler Contract

A source is persisted description data. A source compiler turns that description into a `CompiledSource`: a certified return type coupled to the evaluation that will produce it.

```php
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;

private function compileExample(
    ExampleSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    // Compile-time composition happens here.
}
```

The compiler runs once per source node when `Expression::compile()` is called. Its evaluation callbacks run later, once per program invocation. That separation is why live dependencies belong to the extension and never to the source.

Register each compiler by the exact source class it owns:

```php
public function sourceCompilers(): array
{
    return [
        ExampleSource::class => $this->compileExample(...),
    ];
}
```

The map key is both routing and ownership. Parent-class registrations do not claim subclasses, two extensions cannot claim the same class, and core source classes are already owned by `Dialect::core()`.

### Step 1: Return a Stored Value

Start with a source that contains one embedded value and no children:

```php
use Superscript\Axiom\Extension;
use Superscript\Axiom\Source;

final readonly class ValueSource implements Source
{
    public function __construct(public mixed $value) {}
}

final class ArithmeticExtension extends Extension
{
    public function sourceCompilers(): array
    {
        return [
            ValueSource::class => $this->compileValue(...),
        ];
    }

    private function compileValue(
        ValueSource $source,
        SourceCompilation $compilation,
    ): CompiledSource {
        $type = $compilation->typeOfValue($source->value);

        return $compilation->constant($type, $source->value);
    }
}
```

This introduces two methods:

- `typeOfValue()` applies Axiom's literal-first inference. `5` becomes `LiteralType(5)`, arrays are typed structurally, and objects use the dialect's literal registry.
- `constant()` promises a type and returns the same value on every invocation. `null` represents absence.

If an object has no literal registration, `typeOfValue()` refuses compilation automatically. There is no `Result` to unwrap or forward inside the compiler.

### Step 2: Compile and Check One Child

Now add a source that wraps another source and doubles its result:

```php
use Superscript\Axiom\Types\NumberType;

final readonly class DoubleSource implements Source
{
    public function __construct(public Source $value) {}
}

private function compileDouble(
    DoubleSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    $value = $compilation
        ->child($source->value)
        ->expectPresent(new NumberType());

    return $value->mapPresent(
        returns: new NumberType(),
        evaluate: fn (int|float $value) => $value * 2,
    );
}
```

Add `DoubleSource::class => $this->compileDouble(...)` to `sourceCompilers()`.

This step introduces three ideas:

- `child()` recursively compiles the persisted child in the same dialect and type environment.
- `expectPresent()` certifies the value your native callback will receive. A numeric literal and `NumberType` both pass; a string refuses compilation. If the child is optional, its present member is checked and absence remains structural.
- `mapPresent()` transforms only present values. An absent child stays absent and the callback is not invoked. When the child is optional, the compiled result is automatically `Option<Number>`; the plugin supplies the callback's present return type and Axiom preserves optionality.

The `returns` argument is a type claim, not a conversion. Nothing re-checks the callback's value after compilation, so the callback must really return a number.

### Step 3: Combine Two Children

A product source owns two independent child expressions:

```php
final readonly class ProductSource implements Source
{
    public function __construct(
        public Source $left,
        public Source $right,
    ) {}
}

private function compileProduct(
    ProductSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    $left = $compilation
        ->child($source->left)
        ->expectPresent(new NumberType());

    $right = $compilation
        ->child($source->right)
        ->expectPresent(new NumberType());

    return $compilation->combine([
        'left' => $left,
        'right' => $right,
    ])->mapPresent(
        returns: new NumberType(),
        evaluate: fn (int|float $left, int|float $right) => $left * $right,
    );
}
```

`combine()` groups children that you have already compiled or certified individually. `CompiledSources::mapPresent()` evaluates them left-to-right and invokes the callback only if every child is present. The first absence short-circuits later children, and any optional child automatically makes the result optional.

String keys become named callback arguments, which is why `left` and `right` match the closure's parameter names. Use numeric keys for positional arguments.

When children need no individual checks, `children([...])` is shorthand for compiling and grouping them in one call:

```php
$compiled = $compilation->children([
    'left' => $source->left,
    'right' => $source->right,
]);
```

### Step 4: Use the Dialect's Operation

The previous compiler hard-codes PHP multiplication. That is appropriate only if `ProductSource` explicitly means native numeric multiplication. If it means Axiom's `*`, bind the operation from the composed dialect instead:

```php
private function compileProduct(
    ProductSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    $left = $compilation->child($source->left);
    $right = $compilation->child($source->right);

    $multiply = $compilation->infix(
        $left->returns,
        '*',
        $right->returns,
    );

    return $compilation->combine([
        'left' => $left,
        'right' => $right,
    ])->applyIncludingAbsent($multiply);
}
```

`infix()` resolves once, during compilation, through the same core and extension rules as an ordinary `InfixExpression`. A refusal or ambiguity automatically refuses the enclosing source. The returned `BoundOperation` exposes its certified `$returns` type and is an ordinary callable during evaluation.

This version supports any types for which the dialect owns `*`, not just core numbers. `applyIncludingAbsent()` evaluates both operands and invokes the bound operation. It therefore inherits the ordinary binary absence policy: an optional operand compiles only if the dialect contains a rule that resolves those optional operand types and handles `null`.

`prefix($operator, $operandType)` is the unary equivalent. Resolve an optional child's operation against its present type; `CompiledSource::apply($operation)` then propagates absence and automatically preserves optionality in the result type.

### Step 5: Inject a Live Service

Sources may be serialized and stored for later. Keep live services in the extension, then capture them only in the compiled evaluation:

```php
final readonly class ExchangeRateSource implements Source
{
    public function __construct(
        public string $base,
        public string $quote,
    ) {}
}

final class RatesExtension extends Extension
{
    public function __construct(private RateProvider $rates) {}

    public function sourceCompilers(): array
    {
        return [
            ExchangeRateSource::class => $this->compileRate(...),
        ];
    }

    private function compileRate(
        ExchangeRateSource $source,
        SourceCompilation $compilation,
    ): CompiledSource {
        return $compilation->produces(
            returns: new NumberType(),
            evaluate: fn () => $this->rates->rate($source->base, $source->quote),
        );
    }
}
```

`produces()` is for a source with no compiled children whose value is calculated per invocation. Compilation captures the provider in the `Program`; the persisted `ExchangeRateSource` remains plain data.

If the provider returns a `Result`, it passes through. That lets a missing rate become an expected program `Err` without exposing Axiom's runtime plumbing to the compiler.

### Step 6: Report Failures at the Right Boundary

There are three distinct failure categories.

For a source-specific compile-time refusal, call `reject()`:

```php
if ($source->base === $source->quote) {
    $compilation->reject('An exchange-rate source requires two different currencies.');
}
```

To add context around a child or operation refusal, use `within()`:

```php
return $compilation->within(
    'DoubleSource requires a numeric child.',
    function () use ($source, $compilation): CompiledSource {
        $value = $compilation
            ->child($source->value)
            ->expectPresent(new NumberType());

        return $value->mapPresent(
            new NumberType(),
            fn (int|float $value) => $value * 2,
        );
    },
);
```

The outer message becomes a `TypeMismatch` whose cause is the original diagnostic.

During evaluation, return `Err($exception)` for an expected value-dependent failure that static types cannot rule out. Return a plain value for success. Let unexpected exceptions propagate: a thrown `TypeError`, service misconfiguration, or impossible branch is a defect, not a normal program result.

### Step 7: Choose Absence Semantics

`null` is Axiom's runtime representation of structural absence. Pick the combinator that states what your source means:

| Combinator | On absence |
| --- | --- |
| `CompiledSource::mapPresent()` | Propagate absence; do not call the callback; make the result type optional. |
| `CompiledSource::mapIncludingAbsent()` | Call the callback with `null`. |
| `CompiledSources::mapPresent()` | Stop at the first absent child; do not call the callback; make the result type optional if any child is optional. |
| `CompiledSources::mapIncludingAbsent()` | Evaluate every child and pass absent children as `null`. |

A defaulting source intentionally consumes absence:

```php
final readonly class DefaultNumberSource implements Source
{
    public function __construct(
        public Source $value,
        public int|float $default,
    ) {}
}

private function compileDefaultNumber(
    DefaultNumberSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    $value = $compilation
        ->child($source->value)
        ->expectPresent(new NumberType());

    return $value->mapIncludingAbsent(
        returns: new NumberType(),
        evaluate: fn (int|float|null $value) => $value ?? $source->default,
    );
}
```

Choose this explicitly. Do not turn absence into a domain value accidentally by accepting nullable callback parameters everywhere.

### Step 8: Add Lazy Control Flow

Ordinary `map...()` methods cover eager composition. Use `custom()` when the source decides which child to evaluate. This first-available source stops after the first present candidate:

```php
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Type;

final readonly class FirstAvailableSource implements Source
{
    /** @param non-empty-list<Source> $candidates */
    public function __construct(
        public Type $type,
        public array $candidates,
    ) {}
}

private function compileFirstAvailable(
    FirstAvailableSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    $candidates = array_map(
        fn (Source $candidate) => $compilation
            ->child($candidate)
            ->expectPresent($source->type),
        $source->candidates,
    );

    return $compilation->custom(
        returns: new OptionType($source->type),
        evaluate: function (SourceEvaluation $evaluation) use ($candidates) {
            foreach ($candidates as $index => $candidate) {
                $value = $evaluation->value($candidate);

                if ($value !== null) {
                    $evaluation->annotate('selected_candidate', $index);

                    return $value;
                }
            }

            return null;
        },
    );
}
```

`SourceEvaluation::value()` evaluates an already-compiled child in the current invocation and propagates its expected failure. `annotate()` adds domain metadata to the current source when an execution observer is present.

Catch only domain exceptions your callback owns. A broad `catch (RuntimeException)` around `value()` can swallow Axiom's private expected-failure channel and replace the child's failure with an unrelated value.

`custom()` is an advanced escape hatch for lazy control flow and annotations. It cannot compile new sources at runtime and does not expose `Runtime`, `Result`, or `Option`.

### Symbols Must Stay Visible

When a host source owns a symbol reference, store the actual `SymbolSource` as a public persisted child and compile it through `symbol()`:

```php
use Superscript\Axiom\Sources\SymbolSource;

final readonly class NamedValueSource implements Source
{
    public function __construct(public SymbolSource $symbol) {}
}

private function compileNamedValue(
    NamedValueSource $source,
    SourceCompilation $compilation,
): CompiledSource {
    return $compilation->symbol($source->symbol);
}
```

This preserves ordinary declared-input, definition, and per-invocation memoization semantics. More importantly, parameter discovery and definition-cycle analysis can see the dependency. Constructing a new `SymbolSource` from a hidden string inside the compiler is refused because the stored source tree would no longer describe the program's real dependencies.

The extension map simply grows as the package gains source kinds:

```php
public function sourceCompilers(): array
{
    return [
        ValueSource::class => $this->compileValue(...),
        DoubleSource::class => $this->compileDouble(...),
        ProductSource::class => $this->compileProduct(...),
        DefaultNumberSource::class => $this->compileDefaultNumber(...),
        FirstAvailableSource::class => $this->compileFirstAvailable(...),
        NamedValueSource::class => $this->compileNamedValue(...),
    ];
}
```

### Resolving Operations Over Host Values

When a source owns a typed operation over values it supplies at runtime — such as a lookup source comparing a typed row cell with a compiled filter value — bind it once through the same composed dialect as ordinary expressions:

```php
private function compileFilter(FilterSource $source, SourceCompilation $compilation): CompiledSource
{
    $value = $compilation->child($source->value);
    $comparison = $compilation->infix(
        $source->cellType,
        $source->operator,
        $value->returns,
    );

    return $value->mapPresent(
        returns: $source->resultType,
        evaluate: fn (mixed $expected) => $this->lookup->find(
            $source->table,
            fn (mixed $cell) => $comparison($cell, $expected),
        ),
    );
}
```

The filter value evaluates once per invocation. The bound operation is an ordinary callable inside that evaluation; an expected operation `Err` automatically becomes the enclosing source's `Err`.

`infix()` returns a `BoundOperation` — the selected return type and evaluation together, including extension-owned rules and the normal ambiguity and refusal diagnostics. The caller must provide honest operand types and admit its runtime values into them. It does not infer types from runtime values or restore value-directed dispatch.

### Claiming a Type Honestly

Every source compiler takes one of three honest postures:

- **Declare** the type beside the lookup that produces it, as the exchange-rate example does.
- **Delegate** by returning `$compilation->child($source->inner)` when your source wraps another source.
- **Return `Unknown`** when you genuinely cannot know (a raw lookup cell) — knowing that an `Unknown` value is inert until the program bridges it with an explicit `Coerce` or `Ascription`.

What you may not do is nothing: a `Source` whose exact class has no compiler is a compile error, so "any expression edge starts here" stays a kept promise.

> [!IMPORTANT]
> The one obligation mirrors the operator closure's: **your evaluation must deliver what your type claims.** The compiler certifies downstream operations against the claimed type, and nothing re-checks the values. A lying source meets named runtime errors at the structural reads — a missing field, an unmatched exhaustive match — not silent corruption, but the honest fix is an honest claim. Sources that cannot promise their payload declare `Unknown` and let the program's author place the `Ascription`, which *is* runtime-verified.

Two practical notes:

- **Persist the `Source` tree, not the compiled `Program`.** Compilation deliberately captures the extension's live collaborators in evaluation closures. Reconstruct the extension (normally through your container), compose the dialect, and compile after loading the source.
- **Annotate only inside `custom()` through `SourceEvaluation::annotate()`.** Ordinary composition participates in the node lifecycle automatically.

The complete method-by-method contract is in the [Plugin API Reference](plugin-api.md#sources-and-source-compilers).

## Testing Your Extension

Three patterns from core are worth copying into your package's suite.

### The Shape Census

The census enforces two laws. First, every type your package ships projects into the expected sealed constructor — the "no unmodelled types" guarantee, kept mechanically:

```php
#[Test]
#[DataProvider('census')]
public function every_type_projects(Type $type, string $expectedShape): void
{
    $this->assertInstanceOf($expectedShape, $type->shape());
}
```

Second, **shape truth**: for every record-projected type, over real specimens of its values, every projected field must be reachable and inhabit the field's shape. This is the law that outlaws fictional projections generatively rather than by review:

```php
#[Test]
#[DataProvider('recordProjections')]
public function record_projections_are_true(Type $type, array $specimen): void
{
    foreach ($type->shape()->fields as $name => $field) {
        $this->assertArrayHasKey($name, $specimen);
        $this->assertTrue(TypeReifier::reify($field)->coerce($specimen[$name])->isOk());
    }
}
```

### The Admission-Honesty Law

For every type, whatever `coerce` emits must pass the same type's `assert`. This is the entire trust anchor of compile-then-trust — a value that crosses a boundary *is* its declared type from then on, and nothing downstream re-checks it — so run every raw input you can think of through both faces:

```php
#[Test]
#[DataProvider('census')]
public function coerce_output_always_passes_assert(Type $type): void
{
    foreach ($this->rawInputs() as $input) {
        $coerced = $type->coerce($input);

        if ($coerced->isOk() && $coerced->unwrap()->isSome()) {
            $this->assertTrue($type->assert($coerced->unwrap()->unwrap())->isOk());
        }
    }
}
```

### The Totality Harness

For every rule of your composed dialect, against a specimen matrix of typed values (`[$type, [$value, ...]]` pairs — include core's scalars *and* your domain values): wherever `resolve` certifies operand types, **every** specimen value pair of those types must evaluate without escaping — no `TypeError` from a closure narrower than its claim, no throw — and every successful result must inhabit the resolved return type. Value-dependent `Err`s (division by zero) are legal; escapes are defects.

Be clear about what the harness quantifies over. Its semantics come in three tiers:

- **Enumerated** — the specimen family itself: a finite set of types covering every shape constructor your rules touch, each with hand-picked edge values (empty list, `None`, zero, negatives, the empty string, and your domain's own edges). This is the part you curate. Your obligation is to add specimens for every type your rules mention: each opaque type, each literal class you register. A money package adds `Money<GBP>` and `Money<USD>` specimens, and the sweep covers money×money, money×number, money×everything automatically.
- **Generated** — the sweep over every certified pair of the family. Nobody hand-writes cases.
- **Trusted** — everything outside the family. The harness is *evidence, not proof*: a certification test sampling the domain at its edges. Totality over the full domain is the obligation you certified when your rule answered `Ok`; the library trusts it and never re-checks results at runtime.

Core's `tests/Operators/TotalityHarnessTest.php` is the reference implementation — point it at your dialect and your specimens.

The one obligation the fixed-rule builder *cannot* discharge for you is your closure being total over the types it declared — and, for the admission law, your type's `coerce` and `assert` agreeing on the value domain. These two properties carry the entire runtime trust chain; everything else was proven at compile time. The same obligation applies to a source compiler callback: its evaluation must land in the type it declares, and your suite should prove it on real fixtures.

## What Stays Yours

Axiom deliberately does not own:

- **Dialect composition** — which rules and extensions are included.
- **Enforcement policy** — whether a `dead` finding blocks a save or just renders a warning.
- **Domain relations** built downstream on the registry — for example, partial-agreement conformance between a supply and an interface.

The language reports; the host decides.
