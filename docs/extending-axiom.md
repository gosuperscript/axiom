# Extending Axiom

Axiom is a small core with deliberate extension seams. A companion package or host application can contribute domain types, operators, literals, data sources, resolvers, and match patterns — each a full citizen of both the evaluator *and* the type checker — without modifying core. This guide walks each seam, in the order you typically need them.

The design principle behind every seam: **a rule's runtime and static semantics live in one class.** When you add an operator, you write how it evaluates and what it types, side by side; the checker consumes the same composed stack the evaluator runs, so the two can never drift. The background for this is [RFC 0001: Typesafe Axiom](rfc/0001-typesafe-axiom.md).

## Custom types

A type implements `Type`, which is both a **runtime contract** (`assert`, `coerce`, `compare`, `format`) and — via `Shaped` — a **static contract** (`shape()`).

### The runtime contract

- `assert(mixed): Result<Option<T>>` — strict membership: is this value already of the type? Never lenient.
- `coerce(mixed): Result<Option<T>>` — the lenient input boundary: convert what can reasonably be read as the type. Absence readings (an empty CSV cell meaning "no value") belong here, and *only* here.
- `compare(T, T): bool` — value equality within the type.
- `format(T): string` — human rendering.

### The static contract: projection into the shape algebra

Relations between types (assignability, overlap, …) are not defined on your class — they are defined by structural recursion over a **sealed vocabulary of shapes** (`Superscript\Axiom\Types\Shapes`). Your type *projects* into that vocabulary via `shape()`; it can never add a constructor or edit a relation. Adding a type is adding a shape — an unmodelled type is unrepresentable, not silently incompatible.

**The shape-truth law — the one rule everything else depends on:** your projection is a *truth claim about the runtime structure of your values*. Every relation trusts it, and member access is certified from it, so it must be load-bearing-true: project `RecordShape` **only if** the member-access mechanism can genuinely reach every projected field on every value of your type. This is not honor-system — copy the census pattern (§Testing your extension) and the test suite verifies your projection against real specimens.

Pick your projection:

| Your type is… | Project as |
| --- | --- |
| Values genuinely are records — arrays/objects whose fields the resolver can reach (an address, a JSON-shaped position) | `RecordShape([...fields], open: false)` — and member access on your fields is certified for free |
| Nominal — identity matters, structure shouldn't leak (a claim ID) | `OpaqueShape('ClaimId')` |
| **Object-valued domain type needing parameterized subtyping** (a `Money` class with a currency) | `OpaqueShape` **with structural parameters** — see below |
| A refinement of a scalar (an email is a string) | The base shape (`StringShape`) — the refinement is enforced at runtime by `assert`/`coerce`; statically it is a `String` |

The parameterized opaque is how a money package gets currency subtyping *without lying about structure*:

```php
use Superscript\Axiom\Types\Shapes;

final readonly class MoneyType implements Type
{
    public function __construct(private string $currency) {}

    // ... assert / coerce / compare / format ...

    public function shape(): Shapes\Shape
    {
        return new Shapes\OpaqueShape('money', [
            'currency' => new Shapes\LiteralShape($this->currency),
        ]);
    }
}
```

Opaques relate nominally first, then parameter-wise: `Money<GBP>` is assignable to a `Money<GBP|USD>` slot (same identity, `'GBP' ⊆ 'GBP'|'USD'`), `Money<GBP> == Money<USD>` is a *dead comparison* the checker flags, and no relation code anywhere mentions money. And because opaques make **no structural claims**, nothing is certified that your `Money` object can't deliver — `money.amount` is refused unless you expose it structurally.

**Do not project fictional fields.** An earlier version of this guide recommended encoding the brand as a closed record with literal discriminant fields (`{kind: 'money', currency: 'GBP', amount: Number}`). That trick is how TypeScript's *branded types* work — and it is safe there only because TS types are erased and never meet a runtime. Here, shapes drive a checker that must agree with a live evaluator: a fictional record projection leaks through assignability into record slots whose certified member accesses then crash on the real object. The record encoding remains legal for exactly one case: types whose runtime values *genuinely are* such records (e.g. a host where money literally is `['kind' => 'money', 'currency' => 'GBP', 'amount' => 100]`) — and the census will hold you to it.

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

        return $this->assert(trim((string) $value));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
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

One statement of the fact yields both semantic faces mechanically:

- **Runtime dispatch** is strict membership on the declared operand types (their `assert`) — claiming never converts; conversion belongs to the boundary (`Coerce` nodes, typed bindings), never to dispatch. Your type's `assert` is now your dispatch predicate, so keep it cheap and total: it must `Err` on foreign values, never throw.
- **The static verdict** is admissibility (`admits`) against the same declared types, with generated mismatch messages that read uniformly with core's: `[-] expects Date and Period; got Date and String.`

Because both faces are projections of one declaration, they cannot drift — the agreement harness passes *by construction*, and the honesty and certification contracts of the low-level interface (§Advanced) are facts you never need to learn.

The closure receives values both operand types asserted. Its contract:

- A **plain return value** is the result — it is wrapped in `Ok` for you.
- A **returned `Result` passes through** — the door for value-dependent partiality (division by zero, an overflowing add). Corollary: a closure cannot produce a literal `Result` as its evaluation *value*.
- A **thrown exception propagates.** The claiming contract guarantees the closure only ever sees values it declared, so a throw is a defect in your extension, not a property of the input — it should crash in your stack trace, not masquerade as an evaluation error.

The chain is staged — `signature` → `returns` → `evaluate` — and the final `evaluate(...)` call *is* the compiled rule: there is no `build()` to forget, and a half-declared signature is unrepresentable. One asymmetry: `Operator::prefix` **rejects an `Option` operand type loudly**. Absence never reaches a unary rule (the resolver short-circuits absent operands; optionality propagates structurally), so an Option signature would declare a claim that can never fire — declare the present type.

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

This works because currency is part of the type's *value set*: `MoneyType('GBP')::assert` refuses a USD money. Same-currency pairs dispatch to their row; a cross-currency pair matches *no* row, so the checker reports the composed dialect's honest aggregate — `No overload of [+] accepts Money<'GBP'> and Money<'USD'>.` — and the runtime refuses identically. What enumeration cannot express is a return type that is a *function* of operand types over an unbounded space (`List<T> ++ List<U> → List<T|U>`); that is escape-hatch territory (§Advanced).

### Packaging it: the `Extension` and the `Dialect`

A package ships one `Extension` — the unit that carries everything above, consumed by both the evaluator *and* the checker so they cannot be composed differently:

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
        return [Date::class => fn(Date $d) => new DateType()];
    }
}
```

The host composes a `Dialect` once and hands it to the `Expression`:

```php
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;

$dialect = Dialect::core()->with(new TimeExtension(), new MoneyExtension());

$expression = new Expression($source, $resolver, dialect: $dialect, declarations: [...]);

$expression->check(new BooleanType());   // the checker uses the dialect...
$expression(['effective' => $date]);     // ...and so does the evaluator. One list, both semantics.
```

Composition rules worth knowing: extension rules **prepend** core's, so when two honest rules genuinely both claim a value, the specialization wins the tie; duplicate literal registrations across extensions are a **loud error**, never a precedence question. `Extension` is an abstract class with empty defaults — override only what you contribute, and future hooks (matchers, resolvers) can be added without breaking you.

Because your `-` rule certifies `(Date, Period) → Date` while core's arithmetic refuses it, the checker takes your verdict; with `Unknown` operands both may certify with different types, and the honest composed answer is `Unknown`.

There is no other wiring path: resolvers hold no operator state, and the dialect travels with each evaluation in the `Context` — the same instance the checker reads — so running with different rules than you check with is not representable. An overloader bound directly on a resolver container is inert.

### Advanced: writing a rule by hand

A signature is a row; some rules are not rows. Implement `OperatorOverloader` (binary) or `UnaryOverloader` directly when you need:

- **Verdicts that are relations, not slots** — core's equality certifies operand types that *overlap*, something no fixed operand type expresses.
- **Dead findings** — refusing an operation as *statically constant* (`dead: true`) so hosts can render it as a probable author bug rather than an unsupported operation.
- **Return types computed from operand types** over an unbounded space (`List<T> ++ List<U> → List<T|U>`).
- **Absence-tolerant claims** — a binary rule that wants absence-as-zero arithmetic claims a `null` beside a number in `supportsOverloading` and admits `Option<Number>` operands in `typeOf`. (Binary rules see values as bound; only unary rules are shielded from absence.)

The four obligations:

```php
interface OperatorOverloader
{
    // RUNTIME: which values does this rule own?
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    // RUNTIME: evaluate a claimed pair.
    public function evaluate(mixed $left, mixed $right, string $operator): Result;

    // STATIC: which operators does this rule type?
    public function handles(string $operator): bool;

    // STATIC: the return type for operands of these types.
    public function typeOf(string $operator, Type $left, Type $right): Result;
}
```

Two contracts the builder was upholding for you now become yours to keep:

**The honesty contract on `supportsOverloading`.** Claim **only values your rule owns**. Operator-only dispatch (`return $operator === '<'`) claims every value pair, shadows every rule listed after yours in the dialect, and hides semantics from the checker. Test the operands.

**The certification contract on `typeOf`.** `Ok(T)` means: *this rule certifies these operand types — every value pair it claims evaluates to a `T`* (value-dependent partiality remains: division by zero errs, certified or not). `Err(TypeMismatch)` means the rule does not certify them, for one of two reasons you should distinguish:

- **Unsupported** — values of these types fall outside your runtime claims. Plain `new TypeMismatch(...)`.
- **Dead** — the runtime tolerates the operation but it is statically meaningless (a comparison that can never hold). Construct with `dead: true`; consumers render dead findings as probable author bugs rather than unsupported operations. A dead verdict is a *claim of constancy*, and the harness verifies it (§Testing).

Use the relation registry rather than hand-rolling type tests — it is what keeps rules consistent with each other:

- `TypeRelations::admits($operand, $slot)` — may values of this operand type reach a slot of this type? Assignability plus the `Unknown` hole; pessimistic on unions. This is the judgment for operand positions, and it is how "refuses `Option`" falls out for free: `Option<Number>` is not assignable to a present `Number` slot.
- `TypeRelations::overlaps($a, $b)` — could any value satisfy both? The judgment for equality and membership.
- `TypeOrder::hasDefinedOrder($type)` — is ranking meaningful? (Number-only in core; your dialect can ship ordered domain types.)

Core's rules (`src/Operators/`) are the reference implementations — `ComparisonOverloader` shows overlap-based equality with dead verdicts, `NotOverloader` the unary shape.

## Literal registration

When inference meets a `StaticSource` holding one of your objects, it consults the literal registry — the mapping from PHP value classes to types, contributed via your `Extension::literals()`:

```php
public function literals(): array
{
    return [Money::class => fn(Money $value) => new MoneyType($value->currency())];
}
```

The factory receives the value, so the type can be as precise as the value determines (`Money('GBP', 100)` types as `Money<GBP>`, not just "money"). An unregistered object literal is an inference *error*, with a message pointing at the registry — never a silent `Unknown`. For domain literals whose class determines nothing, wrap the source in a `Coerce` or `Ascription` node: the author's explicit type.

## Host sources

If your host contributes its own `Source` kinds (a lookup-table cell, a geocoding call), implement `TypedSource` so the checker can see through them:

```php
use Superscript\Axiom\TypedSource;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Ok;

final readonly class GeocodeSource implements TypedSource
{
    public function __construct(public Source $address) {}

    public function returnType(TypeEnvironment $environment, TypeInference $inference): Result
    {
        return Ok(new RecordType([
            'lat' => new NumberType(),
            'lng' => new NumberType(),
        ]));
    }
}
```

Three honest postures, pick per source: **declare** the type when you know it (as above); **delegate** through `$inference->infer($this->inner, $environment)` when you wrap another source; **return `Ok(new UnknownType())`** when you genuinely cannot know (a raw lookup cell). What you may not do is nothing: a `Source` the inference cannot handle is an error, so "any expression edge starts here" stays a kept promise.

## Custom resolvers

Evaluation of a new `Source` kind needs a `Resolver`. Resolvers are **stateless**; all per-call state (bindings, definitions, the inspector, the symbol memo) lives on the `Context`:

```php
use Superscript\Axiom\Context;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

final readonly class GeocodeResolver implements Resolver
{
    public function __construct(private Resolver $resolver, private Geocoder $geocoder) {}

    public function resolve(Source $source, Context $context): Result
    {
        return $this->resolver->resolve($source->address, $context)
            ->andThen(fn($option) => /* ... call the geocoder ... */);
    }
}
```

Register it in the `DelegatingResolver` map (`GeocodeSource::class => GeocodeResolver::class`). Annotate through `$context->inspector?->annotate(...)` for observability; the null-safe call makes it free when no inspector is attached.

## Custom match patterns

`MatchResolver` delegates pattern evaluation to a registry of `PatternMatcher`s, so packages can add pattern kinds (an interval pattern, a regex pattern) without touching core:

```php
final readonly class IntervalMatcher implements PatternMatcher
{
    public function supports(MatchPattern $pattern): bool
    {
        return $pattern instanceof IntervalPattern;
    }

    public function matches(MatchPattern $pattern, mixed $subjectValue, Context $context): Result
    {
        return Ok($pattern->interval->contains($subjectValue));
    }
}
```

Statically, custom patterns behave like `ExpressionPattern`: they match at runtime but **never count toward match exhaustiveness** — the checker cannot see into them, so a match whose coverage depends on them still needs a wildcard arm.

## Testing your extension

Two patterns from core are worth copying into your package's suite:

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

**The agreement harness.** For every overloader, against a specimen matrix of typed values (`[$type, [$value, ...]]` pairs — include core's scalars *and* your domain values), check three laws:

- **Soundness (total)**: where `typeOf` certifies `Ok(T)`, **every** specimen pair of those types must be claimed by `supportsOverloading` — an unclaimed specimen is a failure, never a skip; a verdict over values your runtime doesn't claim is a certified crash — and every claimed pair that evaluates successfully produces a value that `T::assert` accepts.
- **Anti-shadowing**: where `typeOf` refuses (and the mismatch is not `dead`), the rule must not claim every specimen pair of those types — a rule that runtime-owns values it statically refuses is hiding semantics from the checker.
- **The dead law**: where `typeOf` refuses with `dead: true` ("statically constant"), all claimed specimen pairs must evaluate to one identical boolean. A dead verdict is a claim, and claims get verified — this law exists because its absence let PHP's loose equality ship a lie (`5 == '5'` was true while the checker said "can never hold").

Core's `tests/Operators/AgreementHarnessTest.php` is the reference implementation; point it at your rules and your specimens. Throw your specimens at *core's* rules too — that is exactly the test that catches core's comparison rule accidentally claiming your domain objects.

**If your rules are signature-built, the laws hold by construction** — both faces are projections of one declaration, so there is no drift to catch. Still run the harness over your specimens: the one obligation the builder *cannot* discharge for you is your domain type's `assert` being honest and total (it is your dispatch predicate now), and the harness is what catches an `assert` that lies or throws on foreign values.

Skip-list to respect when copying it: soundness is skipped when an operand type is `Unknown` (gradual admission is deliberately unsound), and inhabitance is vacuous when the certified type is `Unknown`.

## What stays yours

Axiom deliberately does not own: **dialect composition** (which rules run, in which order), **enforcement policy** (whether a `dead` finding blocks a save or just renders a warning), and **domain relations** built downstream on the registry (e.g. partial-agreement conformance between a supply and an interface). The language reports; the host decides.
