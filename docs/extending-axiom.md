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

Pick your projection:

| Your type is… | Project as |
| --- | --- |
| Structurally transparent (an address is its fields) | `RecordShape([...fields], open: false)` |
| Nominal — identity matters, structure shouldn't leak (a claim ID) | `OpaqueShape('ClaimId')` |
| A branded structural type (money: kind + currency + amount) | A **closed record with literal discriminant fields** — see below |
| A refinement of a scalar (an email is a string) | The base shape (`StringShape`) — the refinement is enforced at runtime by `assert`/`coerce`; statically it is a `String` |

The discriminant-field encoding is how a money package gets currency subtyping for free:

```php
use Superscript\Axiom\Types\Shapes;

final readonly class MoneyType implements Type
{
    public function __construct(private string $currency) {}

    // ... assert / coerce / compare / format ...

    public function shape(): Shapes\Shape
    {
        return new Shapes\RecordShape([
            'kind' => new Shapes\LiteralShape('money'),
            'currency' => new Shapes\LiteralShape($this->currency),
            'amount' => new Shapes\NumberShape(),
        ]);
    }
}
```

Now `Money<GBP>` is assignable to a `Money<GBP|USD>` slot by ordinary literal/union rules, `Money<GBP> == Money<USD>` is a *dead comparison* the checker flags, and no relation code anywhere mentions money.

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

Operators are the centerpiece seam. A binary rule implements `OperatorOverloader`; a unary rule implements `UnaryOverloader`. Both have four obligations:

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

### The honesty contract on `supportsOverloading`

Claim **only values your rule owns**. Operator-only dispatch (`return $operator === '<'`) claims every value pair, shadows every rule listed after yours in the dialect, and hides semantics from the checker. Test the operands.

### The certification contract on `typeOf`

`Ok(T)` means: *this rule certifies these operand types — every value pair it claims evaluates to a `T`* (value-dependent partiality remains: division by zero errs, certified or not). `Err(TypeMismatch)` means the rule does not certify them, for one of two reasons you should distinguish:

- **Unsupported** — values of these types fall outside your runtime claims. Plain `new TypeMismatch(...)`.
- **Dead** — the runtime tolerates the operation but it is statically meaningless (a comparison that can never hold). Construct with `dead: true`; consumers render dead findings as probable author bugs rather than unsupported operations.

Use the relation registry rather than hand-rolling type tests — it is what keeps rules consistent with each other:

- `TypeRelations::admits($operand, $slot)` — may values of this operand type reach a slot of this type? Assignability plus the `Unknown` hole; pessimistic on unions. This is the judgment for operand positions, and it is how "refuses `Option`" falls out for free: `Option<Number>` is not assignable to a present `Number` slot.
- `TypeRelations::overlaps($a, $b)` — could any value satisfy both? The judgment for equality and membership.
- `TypeOrder::hasDefinedOrder($type)` — is ranking meaningful? (Number-only in core; your dialect can ship ordered domain types.)

A date-arithmetic rule, complete:

```php
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class DateArithmeticOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        // Honesty: claim only the value pairs this rule owns.
        return $operator === '-' && $left instanceof Date && ($right instanceof Period || $right instanceof Date);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        return Ok($right instanceof Period ? $left->minus($right) : $left->until($right));
    }

    public function handles(string $operator): bool
    {
        return $operator === '-';
    }

    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        if (!$this->handles($operator)) {
            return Err(new TypeMismatch('Date arithmetic does not handle [' . $operator . '].'));
        }

        $date = new DateType();

        if (TypeRelations::admits($left, $date)->isErr()) {
            return Err(new TypeMismatch(sprintf(
                'Date [-] requires a present date on the left; got %s.', TypeDescriber::describe($left),
            )));
        }

        // date − period → date; date − date → period
        return match (true) {
            TypeRelations::admits($right, new PeriodType())->isOk() => Ok($date),
            TypeRelations::admits($right, $date)->isOk() => Ok(new PeriodType()),
            default => Err(new TypeMismatch(sprintf(
                'Date [-] accepts a period or a date on the right; got %s.', TypeDescriber::describe($right),
            ))),
        };
    }
}
```

### Composing your dialect

The dialect is one list. The evaluator dispatches over it (first honest claim wins) and the checker resolves over it (all certifying verdicts collected; agreement resolves to the type, disagreement to `Unknown` — never list order):

```php
use Superscript\Axiom\Operators\OverloaderManager;

$operators = new OverloaderManager([
    new NullOverloader(),
    new DateArithmeticOverloader(),   // your rule, beside core's
    new BinaryOverloader(),
    new ComparisonOverloader(),
    // ...
]);
```

Because your `-` rule certifies `(Date, Period) → Date` while core's arithmetic refuses it, the checker takes your verdict; with `Unknown` operands both may certify with different types, and the honest composed answer is `Unknown`.

Unary rules mirror all of this exactly — `UnaryOverloaderManager` composes them, and `UnaryResolver` accepts your stack:

```php
$unary = new UnaryOverloaderManager([
    new NotOverloader(),
    new NegateOverloader(),
    new NegateMoneyOverloader(),  // -money<GBP> → money<GBP>
]);

new UnaryResolver($inner, overloader: $unary);
```

One asymmetry to know: **absence never reaches a unary rule.** The resolver short-circuits an absent operand before any rule runs, so unary rules only see present values and optionality propagates structurally (`!Option<Boolean>` is `Option<Boolean>`). Binary rules, by contrast, see values as bound — a dialect that wants absence-as-zero arithmetic writes a binary rule whose `supportsOverloading` claims a `null` beside a number and whose `typeOf` admits `Option<Number>` operands.

## Literal registration

When inference meets a `StaticSource` holding one of your objects, it consults the `LiteralTypeRegistry` — the mapping from PHP value classes to types:

```php
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\TypeInference;

$inference = new TypeInference($operators, $unary, new LiteralTypeRegistry([
    Money::class => fn(Money $value) => new MoneyType($value->currency()),
]));
```

The factory receives the value, so the type can be as precise as the value determines (`Money('GBP', 100)` types as `Money<GBP>`, not just "money"). An unregistered object literal is an inference *error*, with a message pointing at the registry — never a silent `Unknown`. For domain literals whose class determines nothing, wrap the source in a `TypeDefinition`: the author's explicit type override.

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

**The shape census.** One test that asserts every type your package ships projects into the expected sealed constructor. It is the "no unmodelled types" guarantee, kept mechanically:

```php
#[Test]
#[DataProvider('census')]
public function every_type_projects(Type $type, string $expectedShape): void
{
    $this->assertInstanceOf($expectedShape, $type->shape());
}
```

**The agreement harness.** For every overloader, against a specimen matrix of typed values (`[$type, [$value, ...]]` pairs — include core's scalars *and* your domain values), check two laws:

- **Soundness**: where `typeOf` certifies `Ok(T)`, every specimen pair the rule claims and successfully evaluates produces a value that `T::assert` accepts.
- **Anti-shadowing**: where `typeOf` refuses (and the mismatch is not `dead`), the rule must not claim every specimen pair of those types — a rule that runtime-owns values it statically refuses is hiding semantics from the checker.

Core's `tests/Operators/AgreementHarnessTest.php` is the reference implementation; point it at your rules and your specimens. Throw your specimens at *core's* rules too — that is exactly the test that catches core's comparison rule accidentally claiming your domain objects.

Skip-list to respect when copying it: soundness is skipped when an operand type is `Unknown` (gradual admission is deliberately unsound), and inhabitance is vacuous when the certified type is `Unknown`.

## What stays yours

Axiom deliberately does not own: **dialect composition** (which rules run, in which order), **enforcement policy** (whether a `dead` finding blocks a save or just renders a warning), and **domain relations** built downstream on the registry (e.g. partial-agreement conformance between a supply and an interface). The language reports; the host decides.
