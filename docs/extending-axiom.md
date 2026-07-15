# Extending Axiom

Axiom is a small core with deliberate extension seams. A companion package or host application can contribute domain types, operators, literals, and data sources — each a full citizen of the compiler and therefore of every program it certifies — without modifying core. This guide walks each seam, in the order you typically need them.

The design principle behind every seam: **a rule's typing and its evaluation are one statement.** When you add an operator, its `resolve()` verdict carries the return type and the evaluation together; when you add a source, its `compile()` does the same. The compiler binds what your rules resolved into the `Program`, and a compiled program performs no runtime dispatch — so the static and runtime semantics cannot drift, because there are never two faces to keep in agreement. The background for this is [RFC 0001: Typesafe Axiom](rfc/0001-typesafe-axiom.md).

## Custom types

A type implements `Type`, which is both a **runtime contract** (`assert`, `coerce`, `format`) and — via `Shaped` — a **static contract** (`shape()`).

### The runtime contract

- `assert(mixed): Result<Option<T>>` — strict membership: is this value already of the type? Never lenient.
- `coerce(mixed): Result<Option<T>>` — the lenient input boundary: convert what can reasonably be read as the type. Absence readings (an empty CSV cell meaning "no value") belong here, and *only* here.
- `format(T): string` — human rendering.

### The static contract: projection into the shape algebra

Relations between types (assignability, overlap, …) are not defined on your class — they are defined by structural recursion over a **sealed vocabulary of shapes** (`Superscript\Axiom\Types\Shapes`). Your type *projects* into that vocabulary via `shape()`; it can never add a constructor or edit a relation. Adding a type is adding a shape — an unmodelled type is unrepresentable, not silently incompatible.

**The shape-truth law — the one rule everything else depends on:** whatever shape you project is a *claim about what your values look like at runtime*, and the compiler certifies programs against that claim without ever re-checking it. Project a `NumberShape` and your values had better be numbers; project a `RecordShape(['lat' => …])` and every value of your type had better be something member access can read `lat` from. A projection that promises more than the values deliver produces programs that are certified and still crash. This is not honor-system — copy the census pattern (§Testing your extension) and the test suite verifies your projection against real specimens.

Pick your projection, then read its section below for the specifics:

| Your type is… | Project as |
| --- | --- |
| Values genuinely are records — arrays whose fields member access can reach (an address, a JSON-shaped position) | `RecordShape([...fields])` |
| Nominal — identity matters, structure shouldn't leak (a claim ID) | `OpaqueShape('ClaimId')` |
| **Object-valued domain type needing parameterized subtyping** (a `Money` class with a currency) | `OpaqueShape` **with structural parameters** |
| A refinement of a scalar (an email is a string) | The base shape (`StringShape`) |

#### Records: exact fields, reachable fields

Project `RecordShape` only when your values *are* array-shaped records the member-access mechanism can read. What you get: member access on every declared field is certified, and field types flow into inference. What to watch:

- **Records are exact.** Your fields fully describe the value set — undeclared extras make a value a non-member under `assert` (though `coerce` takes the declared slice of wide input). "These fields plus who-knows-what" is not a record; it's a `Dict`.
- **An optional field is a field whose shape is `OptionShape`** — there is no presence flag, and record coercion canonicalizes a missing optional key to a present `null`.
- **Never project fictional fields.** Encoding a brand as a record with literal discriminant fields (`{kind: 'money', currency: 'GBP', amount: Number}`) is how TypeScript's branded types work — safe there only because TS types are erased and never meet a runtime. Here the projection leaks through assignability into record slots whose certified member accesses then crash on the real object. The record encoding is legal exactly when values *genuinely are* such arrays — and the census will hold you to it.

#### Opaques: identity first, parameters for subtyping

Project `OpaqueShape` for object-valued domain types and nominal identities. What you get: your type relates only to opaques of the same identity, so no core rule ever accidentally claims your values. What to watch:

- **No structural claims means no member access**: `money.amount` is a compile error unless your host exposes it another way. That is the point — nothing is certified your object can't deliver.
- **Parameters give you subtyping without structure.** Parameters relate by the ordinary rules under the same identity, so `Money<GBP>` is assignable to a `Money<GBP|USD>` slot (`'GBP' ⊆ 'GBP'|'USD'`), while `Money<GBP>` vs `Money<USD>` share no values — and no relation code anywhere mentions money:

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

- **Operators must be yours.** Core refuses opaques everywhere (including `==` — object equality belongs to you), so every operation on your type is a rule you ship (§Custom operators).

#### Scalar refinements: strict at the boundary, plain inside

Project the base shape (`StringShape` for an email) when your type is a validated subset of a scalar. What to watch:

- **The refinement lives only in `assert`/`coerce`** — it is enforced whenever a value crosses a boundary (typed bindings, `Coerce`, `Ascription`), and nowhere else. Statically your type *is* the base: an email fills any `String` slot, `email == string` compares as strings, and core's string rules apply unchanged.
- That is usually exactly right for refinements. If it isn't — if treating your values as plain scalars ever produces wrong behavior — you wanted an opaque, not a refinement.

A complete refinement-type example:

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

### Optionality is a type, not a flag

`OptionType<T>` denotes `{null} ∪ T`. Consequences your extension inherits for free:

- A present `T` fills an `Option<T>` slot; `Option<Option<T>>` collapses.
- `OptionType::coerce(null)` yields a **present** `Some(null)` — absence is a legal value of the option, not a failed coercion. An optional record field is simply a field whose type is `OptionType`; `RecordType` coercion canonicalizes a missing optional key to a present `null`.
- Don't hand-roll "nullable" variants of your types — wrap them.

## Custom operators

Operators are the centerpiece seam, and the front door is one declaration per rule: a **signature** — a row in a dispatch table. Date arithmetic, complete:

```php
use Superscript\Axiom\Operators\Operator;

Operator::infix('-')
    ->signature(new DateType(), new PeriodType())
    ->returns(new DateType())
    ->evaluate(fn (Date $d, Period $p) => $d->minus($p)),

Operator::infix('-')
    ->signature(new DateType(), new DateType())
    ->returns(new PeriodType())
    ->evaluate(fn (Date $a, Date $b) => $a->until($b)),

Operator::prefix('abs')
    ->signature(new NumberType())
    ->returns(new NumberType())
    ->evaluate(fn (int|float $n) => abs($n)),
```

A row resolves like this: when the compiler asks about your operator over some operand types, the row checks admissibility (`admits`) against its declared types — with generated mismatch messages that read uniformly with core's: `[-] expects Date and Period; got Date and String.` — and on success returns its declared return type together with your closure. The compiler binds that closure into the program; **no dispatch happens at runtime**, and your closure only ever sees values of the operand types you declared, because the compiler proved them and the boundary admitted them.

The closure's contract:

- It may take its parameters **natively typed** (`fn (Carbon $l, Period $r) => …`) — the values are proven.
- A **plain return value** is the result — it is wrapped in `Ok` for you.
- **Errors that depend on the values** — division by zero, an overflowing add, a date outside your calendar's range — are signaled by **returning an `Err`**. The type check cannot rule these out (the types were fine; the values weren't), so they are legitimate evaluation results the program reports to its caller. Core's division does exactly this:

  ```php
  Operator::infix('/')->signature($number, $number)->returns($number)
      ->evaluate(fn (int|float $l, int|float $r) => attempt(fn () => $l / $r)),
      // attempt() catches DivisionByZeroError and returns it as Err
  ```

- A **thrown exception propagates.** The compiler guarantees the closure only sees values of its declared types, so an uncaught throw is a defect in your extension, not a property of the input — it should crash in your stack trace, not masquerade as an evaluation error.
- It must be **total** over its declared operand types: every value of them evaluates without escaping. This is the one obligation you carry, and the totality harness checks it generatively (§Testing).

The chain is staged — `signature` → `returns` → `evaluate` — and the final `evaluate(...)` call *is* the compiled rule: there is no `build()` to forget, and a half-declared signature is unrepresentable. One asymmetry: `Operator::prefix` **rejects an `Option` operand type loudly**. Absence never reaches a unary rule (the compiled node short-circuits absent operands; optionality propagates structurally), so an Option signature would declare a claim that can never fire — declare the present type.

**Ambiguity is refused, never ranked.** Two rows for the same operator whose slots are **jointly admissible** — some operand type would resolve both — are a `Dialect` construction error, and which evaluation runs must never depend on registration order. The relation is about operand *types*, not values: a `List` row beside a `Dict` row is a legal pair (the empty array inhabits both types, but no compilable operand type reaches both rows), while a `Literal(5)` row beside a `Number` row is refused (a `5`-typed operand resolves both, and there is no precedence to pick a winner — specialization included). Declare disjoint rows (the money pattern below), or hand-write a type function that refuses what another rule owns.

### Packaging it: the `Extension` and the `Dialect`

A package ships one `Extension` — the unit that carries everything above, consumed by the compiler, whose resolutions are what every program runs:

```php
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;

final class TimeExtension extends Extension
{
    public function operators(): array
    {
        return [
            Operator::infix('-')
                ->signature(new DateType(), new PeriodType())
                ->returns(new DateType())
                ->evaluate(fn (Date $d, Period $p) => $d->minus($p)),
            Operator::infix('-')
                ->signature(new DateType(), new DateType())
                ->returns(new PeriodType())
                ->evaluate(fn (Date $a, Date $b) => $a->until($b)),
        ];
    }

    public function literals(): array
    {
        // Types a Date object embedded in an expression — see §Literal registration.
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

Composition rules worth knowing: jointly admissible rows for one operator are a **construction error** (list order decides nothing — ambiguity is refused at the earliest moment it exists); duplicate literal registrations across extensions are equally loud, never a precedence question. `Extension` is an abstract class with empty defaults — override only what you contribute, and future hooks (matchers) can be added without breaking you.

Because your `-` row resolves `(Date, Period) → Date` while core's arithmetic refuses it, the compiler takes your lone resolution. If two rules both resolved the same operand types, compilation fails naming both — there is no silent winner.

There is no other wiring path: a compiled program embeds the resolutions of the dialect it was compiled with and carries no dialect at runtime, so running with different rules than you compiled with is not representable.

### Parameterized families: enumerate

A signature's return type is fixed. Rules whose typing is *parameterized* — money, where `Money<'GBP'> + Money<'GBP'> → Money<'GBP'>` but `Money<'GBP'> + Money<'USD'>` must be refused — are declared by **enumeration over the parameter space**, which is host-finite at composition time:

```php
final class MoneyExtension extends Extension
{
    /** @param non-empty-list<string> $currencies the host's configured set */
    public function __construct(private readonly array $currencies) {}

    public function operators(): array
    {
        return array_map(
            fn (string $c) => Operator::infix('+')
                ->signature(new MoneyType($c), new MoneyType($c))
                ->returns(new MoneyType($c))
                ->evaluate(fn (Money $a, Money $b) => $a->plus($b)),
            $this->currencies,
        );
    }
}
```

This works because currency is part of the type's *value set*, so the rows are disjoint: same-currency pairs resolve to their row; a cross-currency pair matches *no* row, so the compiler reports the composed dialect's honest aggregate — `No overload of [+] accepts Money<'GBP'> and Money<'USD'>.` — and no program containing that expression can be compiled, let alone run. What enumeration cannot express is a return type that is a *function* of operand types over an unbounded space (`List<T> ++ List<U> → List<T|U>`); that is escape-hatch territory (§Advanced).

### Advanced: writing a rule by hand

A signature is a row; some rules are not rows. Implement `OperatorOverloader` (binary) or `UnaryOverloader` directly when you need:

- **Verdicts that are relations, not slots** — core's equality resolves operand types that *overlap*, something no fixed operand type expresses.
- **Dead findings** — refusing an operation as *statically constant* (`dead: true`) so hosts can render it as a probable author bug rather than an unsupported operation.
- **Return types computed from operand types** over an unbounded space (`List<T> ++ List<U> → List<T|U>`).
- **Absence-tolerant rules** — a rule that wants absence-as-zero arithmetic resolves operand types where a side is `Option`-shaped (and *refuses present-present pairs*, which stay core's — that disjointness is what keeps the composition unambiguous). Its closure then handles `null`.

The one obligation:

```php
interface OperatorOverloader
{
    /**
     * Does this rule own $operator over these operand types — and if so,
     * what does it return and how does it evaluate?
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $left, Type $right): Result;
}
```

`Ok(new ResolvedOperation($returnType, $closure))` means: *this rule certifies these operand types — the closure is total over every value pair of them, and its result inhabits the return type* (value-dependent partiality remains: a closure may return an `Err`). `Err(TypeMismatch)` refuses, in three flavors you should distinguish:

- **Unhandled** — the operator simply is not yours. Construct with `unhandled: true` so the composing manager keeps your refusal out of aggregated diagnostics for operators you never claimed to own.
- **Unsupported** — your operator, but these operand types fall outside your rule. Plain `new TypeMismatch(...)` with a message naming what you expected.
- **Dead** — the operation is well-formed but statically meaningless (a comparison that can never hold). Construct with `dead: true`; consumers render dead findings as probable author bugs rather than unsupported operations.

Use the relation registry rather than hand-rolling type tests — it is what keeps rules consistent with each other:

- `TypeRelations::admits($operand, $slot)` — may values of this operand type reach a slot of this type? Assignability, pessimistic on unions, with **no `Unknown` hole**: `Unknown` is inert, and your rule should refuse it too (using `admits` gives you that for free). It is also how "refuses `Option`" falls out: `Option<Number>` is not assignable to a present `Number` slot.
- `TypeRelations::overlaps($a, $b)` — could any *value* satisfy both? The judgment for equality and membership.
- `TypeRelations::jointlyAdmissible($a, $b)` — could any operand *type* be admitted by both slots? The row-ambiguity judgment the `Dialect` runs at construction; useful when your hand-written rule needs to reason about collisions the way the dialect does.

Orderability has exactly one authority: whether the dialect resolves `<` for the type. Shipping ordering rows *is* declaring the type ordered.

Core's rules (`src/Operators/`) are the reference implementations — `EqualityOverloader` shows overlap-based resolution with dead verdicts and the negation baked into the closure at resolve time; `HasOverloader`/`InOverloader` show shared operand judgments via `SetOperands`.

## Literal registration

When inference meets a `StaticSource` holding one of your objects, it consults the literal registry — the mapping from PHP value classes to types, contributed via your `Extension::literals()`:

```php
public function literals(): array
{
    return [Money::class => fn(Money $value) => new MoneyType($value->currency())];
}
```

The factory receives the value, so the type can be as precise as the value determines (`Money('GBP', 100)` types as `Money<GBP>`, not just "money"). This is a purely static lookup — the object is already part of the program, so nothing is converted or validated here; values arriving at *runtime* are admitted by `assert`/`coerce` at the boundary and the bridge nodes, which never consult this registry. The factory's one obligation is honesty: the type it returns must `assert` the very value it was derived from, and the census (§Testing) checks exactly that.

An unregistered object literal is an inference *error*, with a message pointing at the registry — never a silent `Unknown`. For domain literals whose class determines nothing, wrap the source in a `Coerce` or `Ascription` node: the author's explicit type.

## Host sources

If your host contributes its own `Source` kinds (a lookup-table cell, a geocoding call), implement `TypedSource` — the type claim and the evaluation, **one statement**, so your source cannot register behavior its claim does not describe (there is no separate place to put it):

```php
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\TypedSource;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class GeocodeSource implements TypedSource
{
    public function __construct(public Source $address, private Geocoder $geocoder) {}

    public function compile(TypeEnvironment $environment, TypeInference $compiler): Result
    {
        return $compiler->compile($this->address, $environment)->map(
            fn(CompiledNode $address) => new CompiledNode(
                new RecordType(['lat' => new NumberType(), 'lng' => new NumberType()]),
                fn(Runtime $runtime) => ($address->evaluate)($runtime)
                    ->map(fn(Option $option) => $option->map($this->geocoder->locate(...))),
            ),
        );
    }
}
```

Three honest postures, pick per source: **declare** the type beside the lookup that produces it (as above); **delegate** through `$compiler->compile($this->inner, $environment)` when you wrap another source; **return `Unknown`** when you genuinely cannot know (a raw lookup cell) — knowing that an `Unknown` value is inert until the program bridges it with an explicit `Coerce` or `Ascription`. What you may not do is nothing: a `Source` the compiler cannot handle is a compile error, so "any expression edge starts here" stays a kept promise.

The one obligation mirrors the operator closure's: **your evaluation must deliver what your type claims.** The compiler certifies downstream operations against the claimed type, and nothing re-checks the values — a lying source meets named runtime errors at the structural reads (a missing field, an unmatched exhaustive match), not silent corruption, but the honest fix is an honest claim. Sources that cannot promise their payload declare `Unknown` and let the program's author place the `Ascription`, which *is* runtime-verified.

Annotate through `$runtime->inspector?->annotate(...)` for observability; the null-safe call makes it free when no inspector is attached.

## Testing your extension

Three patterns from core are worth copying into your package's suite:

**The shape census — two laws.** First, every type your package ships projects into the expected sealed constructor (the "no unmodelled types" guarantee, kept mechanically):

```php
#[Test]
#[DataProvider('census')]
public function every_type_projects(Type $type, string $expectedShape): void
{
    $this->assertInstanceOf($expectedShape, $type->shape());
}
```

Second — **shape truth**: for every record-projected type, over real specimens of its values, every projected field must be reachable and inhabit the field's shape. This is the law that outlaws fictional projections generatively rather than by review:

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

**The admission-honesty law.** For every type, whatever `coerce` emits must pass the same type's `assert`. This is the entire trust anchor of compile-then-trust — a value that crosses a boundary *is* its declared type from then on, and nothing downstream re-checks it — so run every raw input you can think of through both faces:

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

**The totality harness.** For every rule of your composed dialect, against a specimen matrix of typed values (`[$type, [$value, ...]]` pairs — include core's scalars *and* your domain values): wherever `resolve` certifies operand types, **every** specimen value pair of those types must evaluate without escaping — no `TypeError` from a closure narrower than its claim, no throw — and every successful result must inhabit the resolved return type. Value-dependent `Err`s (division by zero) are legal; escapes are defects.

Be clear about what the harness quantifies over (RFC item 38). Its semantics come in three tiers:

- **Enumerated** — the specimen family itself: a finite set of types covering every shape constructor your rules touch, each with hand-picked edge values (empty list, `None`, zero, negatives, the empty string, and your domain's own edges). This is the part you curate — **your obligation is to add specimens for every type your rules mention**: each opaque type, each literal class you register. A money package adds `Money<GBP>`/`Money<USD>` specimens, and the sweep covers money×money, money×number, money×everything automatically.
- **Generated** — the sweep over every certified pair of the family. Nobody hand-writes cases.
- **Trusted** — everything outside the family. The harness is *evidence, not proof*: a certification test sampling the domain at its edges. Totality over the full domain is the obligation you certified when your rule answered `Ok`; the library trusts it and never re-checks results at runtime.

Core's `tests/Operators/TotalityHarnessTest.php` is the reference implementation; point it at your dialect and your specimens. The one obligation the signature builder *cannot* discharge for you is your closure being total over the types it declared — and, for the admission law, your type's `coerce`/`assert` agreeing on the value domain. These two properties carry the entire runtime trust chain; everything else was proven at compile time. (The same obligation applies to a `TypedSource`: it is an operator rule with zero operands — its evaluation must land in the type it declares, and your suite should prove it on real fixtures.)

## What stays yours

Axiom deliberately does not own: **dialect composition** (which rules run, in which order), **enforcement policy** (whether a `dead` finding blocks a save or just renders a warning), and **domain relations** built downstream on the registry (e.g. partial-agreement conformance between a supply and an interface). The language reports; the host decides.
