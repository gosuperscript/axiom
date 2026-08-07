# RFC 0001: Typesafe Axiom

- **Status**: Proposed
- **Author**: Robert van Steen
- **Date**: 2026-07-15

## TL;DR

Axiom pivots from a dynamically evaluated expression engine to a **statically typed, compiled expression language**.

- Every expression has an inferable `Type`, and types stand in decidable relations: assignability, equivalence, overlap, admissibility, joint admissibility.
- Each operator rule owns one symbol and states its typing **and** its evaluation in **one verdict** — `resolve(Type, Type)` returns a `ResolvedOperation`, `UnsupportedOperation`, or `DeadOperation`. Rule selection and evaluation cannot drift apart, because there are no longer two statements to diverge.
- A program is checked **once**: `Expression::compile()` resolves every operator and symbol against the declared input types and returns a `Program`. It is trusted thereafter — a compiled program performs no runtime type dispatch. Values are inspected at exactly **three sites**: the binding boundary, `Coerce`, and `Ascription`.
- Types project to a **sealed `Shape` algebra**, and relations are answered against shape truth. Records are exact; `OpaqueType` fails closed; `Unknown` is inert. The two explicit bridges out of `Unknown` are `Coerce` (convert a representation) and `Ascription` (claim a membership).
- Hosts extend the type system the same way they extend evaluation — contributing types, literals, operator rules, and sources through the plugin seams.
- Ships as **one breaking release** (`0.5.0`, Phases 0–4). The inference API is the migration tool for the runtime strictness, so the strict runtime and its checker ship together.

This is **PR 1 of 4** in the RFC 0001 stack — prose only, no code. Reviewing it first means the three code PRs that follow are reviewed against an agreed design.

## Summary

Axiom pivots from a dynamically evaluated expression engine to a **statically typed, compiled expression language**: every expression has an inferable `Type`, types stand in decidable relations to one another (assignability, equivalence, overlap, admissibility, joint admissibility), and every operator rule states its typing and its evaluation in **one verdict**, so rule selection and evaluation can never drift apart (what one statement cannot prove — the evaluation honoring its stated type — is a certified obligation, tested by the harness; §Drift guarantees). A program is checked once — `Expression::compile()` resolves every operator and every symbol against the declared input types and returns a `Program` — and trusted thereafter: a compiled program performs no runtime type dispatch, and the only places values are ever inspected are the three admission sites (the binding boundary, `Coerce`, `Ascription`). Hosts extend the type system the same way they extend evaluation — by contributing types, literals, operator rules, and sources through the plugin seams.

The checker operates on the **runtime AST** (`Source`) — the programs hosts actually run today. It has no dependency on any authoring surface; if one is ever built, it would be a consumer of this layer, not a prerequisite for it (see §Future work).

## Motivation

Two forces converge:

1. **Hosts rebuild static semantics, and it drifts.** Answering static questions about Axiom expressions — "is this condition boolean?", "can this comparison ever hold?", "what does this derived value return?" — currently means building a type layer *outside* Axiom: structural shapes, relations between types, syntax-directed inference, and per-operator typing rules. That last part is the hazard: the static rules are a hand-maintained **mirror** of the runtime overloaders. `Option<Number> + Number` types differently under the bare `DefaultOverloader` (unsupported: no rule pairs an absent operand with a present one) than under a host dialect that deliberately reads an absent operand as zero — so inference is only correct if it is injected with the exact static mirror of the overloader stack the evaluator runs. Nothing enforces that mirror beyond discipline. Collapsing each rule to a single verdict — one call whose success carries the return type *and* the evaluation — makes drift between rule selection and evaluation *unrepresentable* rather than merely tested-against: there are no longer two statements to diverge. (What a single statement cannot make unrepresentable — the evaluation honoring the type beside it — remains a tested obligation; §Drift guarantees.)

2. **Every host pays the same tax.** A static layer built downstream is essentially host-agnostic: only the host's domain types and its enforcement policy are truly its own. The traversal, the relations, the typing rules for Axiom's own overloaders — all of it types *Axiom*, and belongs to Axiom. This design has been proven in production by a host application that built the full layer downstream; this RFC upstreams the host-agnostic core of it.

Hosts want to answer, before evaluating anything, questions the engine currently cannot: is `turnover * riskFactor` meaningful for the declared input types? Is this gate condition boolean? Can this comparison ever hold? Today those answers arrive at evaluation time, one unlucky binding at a time.

## Current state

- `Types\Type` is a **runtime contract**: `assert`, `coerce`, `compare`, `format`. It answers "does this value inhabit this type?" — never "how does this type relate to that one?".
- Operators are **value-directed**: `OperatorOverloader::supportsOverloading(mixed $left, mixed $right, string $operator)` dispatches on runtime values; an ordered stack (`OverloaderManager`) composes a dialect.
- The runtime AST (`Source`) has a single static-typing node, `TypeDefinition` (evaluated via coercion). This RFC splits it into two nodes with distinct semantics: `Coerce` and `Ascription` (§3).

What's missing is the middle: a **static semantics** connecting declared types to expressions.

### Runtime dishonesty this RFC also fixes

The current runtime contradicts the semantics the static layer must certify in several places. These are fixed as **Phase 0** (see §Release plan), before any typing rule is written against them:

- `StringType` treats `''` and `'null'` as absence in **`assert`**, not just `coerce` — the empty string is currently not a `String`.
- `DictType` treats the empty array as absence in both `assert` and `coerce` — `{}` is currently not a `Dict`.
- `ComparisonOverloader::supportsOverloading` claims **every value pair** for its operators (it tests only the operator), so `money < money` silently evaluates as PHP structural comparison and shadows any host rule listed after it.
- `UnaryResolver` evaluates `!` as **PHP truthiness on any value** (`!5` is `false`), while the intended static rule is boolean-only.
- `MatchResolver` returns `Ok(None())` when no arm matches — non-exhaustive matches silently produce absence.

## Design

Three layers, each usable without the ones above it (a fourth — a typed authoring surface — is future work, see §Future work):

```
3. Compilation           Expression::compile() → Program — every node resolved once, then trusted
2. Typed operators       operator() + resolve(operand types) → explicit verdict
1. Types & relations     sealed shape algebra
                         + relations (assignable, equivalent, overlaps, admits, ordered)
```

### 1. Type vocabulary and relations

#### The sealed shape algebra

Relations are not defined on `Type` directly; every type **projects** to a structural `Shape`, and the relation rules are implemented by structural recursion over the shapes. The shape algebra is **closed**: a fixed vocabulary of constructors owned by Axiom. Extensibility is *projection only* — a domain type implements `Shaped` and maps into the fixed vocabulary; it can never add a constructor or edit a relation. This is what keeps the relation laws checkable: the case analysis is exhaustive.

```php
interface Shaped
{
    public function shape(): Shape;   // a type that owns its projection
}
```

The sealed constructors:

| Constructor | Concept | Notes |
| --- | --- | --- |
| `BooleanShape`, `NumberShape`, `StringShape` | scalar primitives | One `Number`; int/decimal split deferred until a consumer forces it. |
| `LiteralShape(primitive, value)` | singleton of a scalar | `Literal('shop')`, `Literal(42)`. Substitutable for its base, never the reverse. |
| `OptionShape(inner)` | possibly-absent value | **Value-set semantics**: denotes exactly `{null} ∪ values(inner)`. See laws below. |
| `UnionShape(members)` | set of alternatives | Normalized on construction: flattened, deduplicated, order-insensitive, `Never` members eliminated, ≥2 members after normalization. An enum is a union of literals. |
| `RecordShape(fields)` | named fields, exact | A record's value set is fully described by its fields — no open variant, no width subtyping; exactness is what makes whole-record equality total. **No presence flag**: an optional field is a field whose shape is `OptionShape` — missing-key and present-null are one absence concept (see laws). |
| `DictShape(value)` | homogeneous string-keyed map, unknown keys | Distinct from a record: "all values are `T`" and "exactly these named fields" are different claims. `Dict` is the honest type for data whose keys cannot be enumerated. |
| `ListShape(element, min, max)` | length-bounded list | A plain list is bounds `[0, ∞)`; there is no separate sized-list shape. Bounds participate in subtyping and overlap. |
| `UnknownShape` | statically unnameable | Gradual typing, **inert**: refused at every operand, comparison, and member-access position; the ways out are the two explicit admission bridges, `Coerce` and `Ascription`. Accepted only by itself under assignability. Derived, never authored. |
| `NeverShape` | bottom | The empty value set. Type of impossible joins; `Option<Never>` is the type of the `null` literal; union identity. |
| `OpaqueShape(identity, parameters)` | nominal head, structural parameters | Related only under the same identity; then parameter-wise by the ordinary relations (`Opaque('money', ['currency' => Literal('GBP')])` is assignable to `Opaque('money', ['currency' => 'GBP' \| 'USD'])`). Parameterless opaques are plain nominal identities (claim IDs, catalogue keys). The shape for object-valued domain types. |

**The shape-truth law.** `shape()` is a **truth claim about the runtime structure of the type's values** — every relation trusts it, so it must be load-bearing-true. Project `RecordShape` *only if* the member-access mechanism can reach every projected field on every value of the type and obtain an inhabitant of the field's shape. This is census-enforced, not honor-system (see the shape-soundness census, second law). The consequence for domain types:

- Values that genuinely *are* records (JSON-shaped hosts where money is `['kind' => 'money', 'currency' => 'GBP', 'amount' => 100]`) may project as records — the discriminant-field encoding is legal there, and currency subtyping falls out of literal/union rules.
- **Object-valued** domain types (a `Money` class) must **not** project fictional fields. They project `OpaqueShape` with structural parameters: `Opaque('money', ['currency' => Literal('GBP')])` — nominal head (no structural claims, no field access, no record interop), parameters related by the ordinary rules, so currency subtyping survives without the lie.

*Why object-valued types cannot fake records:* a fictional record projection leaks through assignability — `Money` becomes assignable to `{amount: Number}` slots, whose certified member accesses then crash on the actual object. Once shapes must be true, object-valued types need a nominal-with-parameters constructor, which is why `OpaqueShape` carries parameters. (Contrast TypeScript's *branded types*, which use exactly this fictional-field trick safely — because TS types are erased and never meet a runtime. Our shapes drive a checker that must agree with a live evaluator; the same trick is a certified-crash factory here.)

An unmodelled type (no projection, no `Shaped`) **throws** — a programming error in the host, not a mismatch a rule author branches on.

#### The relation registry

```php
final class TypeRelations
{
    /** Can a value of $source flow into a $target slot? ⊆ over value sets.
        Ok = holds; Err(TypeMismatch) = does not hold, with the cause chain. */
    public static function isTypeAssignableTo(Type $source, Type $target): Result;  // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert

    /** Same type? Derived: assignable both ways. */
    public static function areEquivalent(Type $a, Type $b): Result;                 // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert

    /** Could any value satisfy both? Symmetric; weaker than assignability
        either way. Says nothing about whether an operation supports either
        type. */
    public static function overlaps(Type $a, Type $b): Result;                      // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert

    /** May values of $operand reach a rule's $slot? The operand-admissibility
        relation every typing rule consults — assignability, named for its
        intent. There is no top-level-Unknown hole: an Unknown operand is
        refused and bridged explicitly. */
    public static function admits(Type $operand, Type $slot): Result;               // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert

    /** Could one operand type be admitted by both slots? The row-ambiguity
        relation: dispatch resolves operand *types* through admits(), so two
        rows conflict iff some inhabited type is admitted by both slots —
        value overlap is neither necessary nor sufficient (see laws). */
    public static function jointlyAdmissible(Type $a, Type $b): Result;             // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert
}
```

There is **no boolean verdict channel**: `TypeMismatch` *is* the negative verdict. Every call site has two branches. Axiom's built-in data equality has **one definition**, `ValueEquality` (see the value-domain laws); packages that own opaque values contribute their own equality rows. Orderability has no oracle here at all — the dialect's ordering rows are the only authority on whether `<` is meaningful for a type.

Diagnostics are first-class: relations fail with a `TypeMismatch` — a message plus a nested cause chain, TypeScript-style — and a single `TypeDescriber` is the authority for rendering a type. `TypeDescriber` must render literal-heavy types readably (a literal-union list type should read like the literal it came from, not scare authors).

**Laws** (pinned by tests, documented in the registry):

*Assignability & Unknown*
- `Unknown` is consistent, not transitive: accepted only by itself under assignability; never chain consistency through a third type.
- Refinement widens one way: a base accepts its literals and unions of them; never the reverse.
- Union assignability is member-wise: `Union(members) <: T` iff every member is assignable to `T`. (Hence `enum{1,2,3}` is assignable to `Number` and arithmetic on homogeneous literal unions just works.)

*Option (value-set semantics — these are theorems, not choices)*
- `Option<T>` denotes `{null} ∪ values(T)`. Therefore:
  - `T <: Option<T>` — a present value fills an optional slot.
  - `Option<Option<T>> ≡ Option<T>` — canonicalized at construction; the runtime value domain cannot represent nesting.
  - The `null` literal infers as `Option<Never>` — the set `{null}` — assignable to every `Option<T>`.
  - `Option<T>` always overlaps `Option<U>` (shared member `null`). The authored `x == null` emptiness test is a core structural presence reading: `Option<T>` is `1 + T`, while the absence-only `Option<Never>` is `1 + 0`, so their present-present branch is uninhabited and the comparison needs no value equality for `T`. It therefore remains answerable when `T` is opaque or `Unknown`; only a bare `Unknown`, whose outer constructor is not known, stays inert.
- Coercion law: `OptionType<T>::coerce(null)` yields a *present* `Some(null)` — absence is a legal value of the option, not a failed coercion. That is what lets an optional field live inside a record whose fields treat a `None` coercion as "required but missing".
- **One absence axis**: missing-key and present-null are indistinguishable after record coercion (coercion canonicalizes, inserting `null` for missing optional keys). No expression can observe the difference — member access yields `null` for both, and `has`/`in` are list membership, not key presence. *Revisit trigger, recorded here deliberately: if a `keys()`-like operation over records ever enters the language, this decision must be revisited before it ships.*

*Value-equality support, overlap & admissibility*
- `ValueEquality::supports(L, R)` asks whether the built-in evaluator is total over every pair in `L × R`. It is universal over reachable union members, options, and container elements; it refuses `Unknown` and opaque values. A list bounded to length zero passes vacuously because its element is unreachable. The core absence comparison is a separate structural judgment rather than an exception in this support relation.
- Equality support and overlap are independent judgments. `Number` and `String` are supported but disjoint (the comparison is statically constant-false); `Unknown` and `Number` overlap but are unsupported; `Money` and `Money` overlap nominally but built-in equality refuses them so the owning package can supply the lone successful equality resolution.
- `overlaps` is symmetric and is **not** derivable from assignability.
- `overlaps(Unknown, T)` always holds — nothing can be ruled out. (This is the ascription bridge working: a claim over an `Unknown` inner value is never statically false.)
- `overlaps(List<T>, Dict<U>)` always holds — the empty array is a shared member (see the value-domain law below). Assignability and overlap answer to different masters: assignability must be **sound** (never certify a bad flow), overlap must be **complete** (never falsely declare deadness — a `dead` verdict is a constant-`false` claim, and `[] == []` is `true`).
- `admits` is **pessimistic**: `Union(Number, String)` is refused at a `Number` slot, and there is no `Unknown` hole — the author narrows with `match` or bridges with `Coerce`/`Ascription`. Once an equality implementation supports both operands, optimistic `overlaps` answers only whether its result can ever be true; operand slots are governed by pessimistic `admits`. Distinct judgments, distinct purposes.
- `jointlyAdmissible` answers **dispatch ambiguity** and only that: does some *inhabited* type get admitted by both slots? (Inhabited matters: `Never` is vacuously admitted everywhere and proves nothing.) Not derivable from `overlaps`, in either direction of intuition: `List<T>` and `Dict<U>` overlap — the value `[]` inhabits both — yet are jointly *in*admissible, because dispatch sees operand types, never values, and no compilable type is admitted by both slots; `Number` and `Literal(5)` are jointly admissible (a `5`-typed operand reaches both); `Option<Number>` and `Option<String>` are jointly admissible (`Option<Never>`, the type of the `null` literal, is admitted by both). Overlap answers value questions — equality liveness, ascription viability; joint admissibility answers the one dispatch question, row ambiguity.

*Order*
- Orderability is a property of the **dialect**, not of a type: "is `<` meaningful for `T`?" has exactly one authority — does the dialect resolve `<` for `(T, T)`? Core's ordering rows cover `Number` only — not `String` (PHP's willingness to rank strings is not a defined order; ISO-date-string comparison *should* be a type error, because it's begging for a `Date` type), not `Boolean`, not `Option` (`null` doesn't rank). A package that wants ordered dates ships ordering rows (§Companion package); a host that wants lexicographic string ranking ships one row in its dialect — and in both cases the answer to "is this type ordered?" *is* the row's existence. There is deliberately no static orderability oracle beside the rows: it would be a second authority that extension rows contradict the moment a date package ships.

*Value domain (Phase 0 alignment)*
- `assert` defines strict membership: `""` **is** a `String`, `'null'` **is** a `String`, `[]`/`{}` **are** a `List`/`Dict`. The absence readings (`'' → None`, `'null' → None`, `[] → None`) live in — and only in — `coerce`, the lenient input boundary where "empty CSV cell means no value" is a legitimate reading. Hosts keep binding-time leniency; the internal value domain becomes coherent.
- **The empty array inhabits both `List` and `Dict`.** PHP has one value where the algebra has two types, and membership follows reality: `List` membership is `array_is_list` (which admits `[]`); `Dict` membership is "array that is not a non-empty list". Membership never converts — `ListType::assert` *rejects* an associative array rather than reindexing it (reindexing is coercion, and fixed rules dispatch on `assert`). The consequence is the overlap law above: `list == dict` is a **live** comparison, `true` exactly when both are empty — the same shape of theorem as `x == null` being the emptiness test for options.
- **Built-in equality is value equality, never PHP juggling or object identity**: numeric comparison when both operands are numbers (`1 == 1.0` holds — one `Number` base), strict identity for strings/booleans/null, element-wise for arrays, and **`false` across bases** (`5 == '5'` is `false`). `ValueEquality` owns both that evaluation and the static support judgment describing where it is total. It is consumed by the built-in equality operator, membership (`has`/`in`/`intersects`), literal-shape identity, and match coverage. An opaque type's package owns its equality instead, as an ordinary operator row carrying its own evaluation. This is what makes a `dead` verdict honest by construction: after support is established, no overlap ⇒ genuinely constant-false. `===`/`!==` become aliases of `==`/`!=` (strictness was only distinct while `==` juggled). A host that coerced its stringly inputs — which the typed boundary now does for it — never notices.
- **One representation of null in the resolution channel**: the value `null` travels as `None`; `Some(null)` never escapes a compiled node (a bound `null` normalizes to `None` at symbol lookup — a bound null is still a *bound key*, since presence lives in `Bindings::has()`, checked before the value is read). The *coercion channel* deliberately keeps its two-value protocol — `Option::coerce(null) → Some(null)` ("present null") vs `None` ("read as missing") — but that distinction is consumed at boundaries (record fields, typed bindings), never propagated. Compiled operator nodes convert both directions at operand positions (`unwrapOr(null)` in, `Option::from` out); this law makes the whole engine as consistent as its operators.

*Coercion is the boundary*
- `assert` defines **membership** (the value domain, what shapes describe); `coerce` defines **admission** (host-facing conversion policy). Coercion is statically opaque — the checker never reasons about it — and has exactly two homes: the typed binding boundary (declared inputs coerced at `Expression` invoke) and the explicit `Coerce` node. Certification is a conditional guarantee ("*if* inputs inhabit their declared types…"); the boundary is what establishes the condition, which is why `coerce` exists on `Type` at all.

### 2. Typed operators — one verdict, both semantics

The centerpiece. A rule has exactly one question to answer — asked once, with types, at compile time — and the contract is one method. **Hard break** (we are pre-1.0): no `supportsOverloading`, no `handles`, no separate `typeOf`; no adapter, no legacy tier. A parallel tier would permit a rule whose typing and evaluation live apart, which is the disease itself with a deprecation timer attached.

```php
interface BinaryOperatorRule
{
    public function operator(): string;

    public function resolve(Type $left, Type $right): OperatorResolution;
}

interface OperatorResolution {}

final readonly class ResolvedOperation implements OperatorResolution
{
    public function __construct(Type $returns, Closure $evaluation) {}
}

final readonly class UnsupportedOperation implements OperatorResolution
{
    /** @param list<TypeMismatch> $causes */
    public function __construct(string $message, array $causes = []) {}
}

final readonly class DeadOperation implements OperatorResolution
{
    /** @param list<TypeMismatch> $causes */
    public function __construct(string $message, array $causes = []) {}
}
```

Each rule owns exactly one symbol, so `resolve()` never routes or refuses foreign symbols. `ResolvedOperation` is the certification: its closure must be total over the resolved operand types and return an inhabitant of `returns`. `UnsupportedOperation` rejects operand types for the owned symbol; `DeadOperation` reports a valid-in-principle operation that is statically meaningless. The compiler-facing resolver alone converts refusals into `TypeMismatch`, retaining the host-facing `dead` flag.

`TypeMismatch` keeps its `dead` flag distinguishing the two refusals: *unsupported* ("no rule accepts Date and Number") versus *dead* ("`Number == String` can never hold" — constancy is the probable author bug). Consumers render them differently.

#### Core rules: rows and type functions

Most rules are **dispatch-table rows** — the fixed-rule builder compiles to a one-method `resolve` that checks `admits` on each slot and returns the declared type with the declared closure. Everything row-shaped in core is declared as rows: arithmetic (`+ - * /` over `(Number, Number) → Number`), logical (`and`/`or` over booleans), ordering (`< <= > >=` over `(Number, Number) → Boolean` — the rows *are* the orderability axis; there is no separate static oracle), `not` (booleans only), negate (numbers).

A few rules are genuine **type functions** — their verdict is computed from the operand types, not looked up:

| Rule | `resolve` |
| --- | --- |
| Equality (`=`, `==`, `===`, `!=`, `!==`) | `ValueEquality::supports(left, right)` first — unsupported domains are refused — then `overlaps()` solely for liveness; no overlap is `dead`, and value equality makes `dead` mean constant-`false` by construction; tolerates `Option` (equality against `null` is the emptiness test) → `Boolean`, evaluating via `ValueEquality` with the negation baked into the closure at resolution |
| `has` / `in` | list side list-shaped and present; `ValueEquality` must support the element domains, then element overlap determines liveness → `Boolean` |
| `intersects` | either side list or scalar, absence tolerated; `ValueEquality` must support the compared element domains before overlap is used for liveness → `Boolean` |

`NullOverloader` — the degenerate `null ∘ null → null` rule — is deleted, and the reason is instructive: its verdict always refused (it deliberately contributed no static admissibility), which under value-directed dispatch meant "runtime-only behavior for unchecked programs". A rule that cannot certify cannot run in a compiled program; it had no honest translation. A dialect's absence policy is now spelled as rules that *resolve* `Option` operand types — with the composition caveat recorded below.

The same contract reaches every dialect: `axiom-money` resolves money arithmetic (currency agreement computed over the operand types — `Money<GBP> + Money<USD>` refused with a real message) and contributes same-currency equality rows whose evaluation calls the package's value comparison; built-in `ValueEquality` refuses the opaque operands, so the money row is the lone successful resolution. `axiom-time` declares `Date − Period → Date` as a row. **A rule's static and runtime semantics are one verdict, in one package, in one diff.**

#### Unary operators: the same collapse

`UnaryOperatorRule` is the sibling: `operator(): string` and `resolve(Type $operand): OperatorResolution` (the resolved evaluation takes one argument). Prefix rows come from the same builder; core ships `not` (**booleans only** — `!5` is a compile error) and negate (numbers) as rows.

Deliberate asymmetry, kept: **absence is handled structurally for unary.** The compiler resolves the rule against the *present* operand type and wraps the compiled node with the absence short-circuit, so optionality propagates (`!Option<Boolean>` is `Option<Boolean>`) and a unary rule never sees `null`. A host cannot write an absence-handling unary rule — a feature, not a gap. Binary usually leaves absence policy to each rule: a dialect may deliberately resolve `Option`-shaped operands for semantics such as absence-as-zero. Equality against the absence-only type is syntax-directed instead: infix-expression typing elaborates `Option<T> == null` to elimination of the outer option constructor, independently of value equality for `T`. A known total operand is disjoint from absence and therefore yields the corresponding constant theorem; a bare `Unknown` exposes no constructor and stays unresolved.

#### Composition: the dialect is one list, and ambiguity is refused

`BinaryOperatorResolver` is the dialect's overload collection, not a rule and not the typing judgment for an authored expression. It indexes rules by `operator()` at construction. Ordinary resolution only invokes the requested symbol's bucket:

1. No bucket → unsupported-operator `TypeMismatch`; no unrelated rule is called.
2. Exactly one `ResolvedOperation` → that resolution, bound into the program.
3. **Two or more resolutions → a stable ambiguity error naming the competing rules.** Registration order is never precedence.
4. No resolution → one refusal is preserved exactly; multiple refusals are aggregated with their causes and dead metadata.

`InfixExpressionTyping` owns the syntax-directed judgment around that collection. For an equality alias against `Option<Never>`, it first eliminates a known `Option` counterpart to the one core null comparison. Otherwise it asks the dialect's overload collection. If no overload resolves and the counterpart is known total, it uses the disjointness theorem as a typed fallback; an explicit compatibility overload therefore retains its published reading, while ambiguity still refuses. A bare `Unknown` delegates without a fallback. The resulting `ResolvedOperation` is recorded and bound exactly like an overload, so elaboration changes neither analysis nor runtime dispatch.

Rows are statically comparable, so `Dialect` construction additionally refuses two rows for the same operator whose slots are **jointly admissible** — some inhabited operand type is admitted by both (the relation dispatch actually runs on; see the joint-admissibility law). Value overlap is deliberately *not* the test: `List + List` beside `Dict + Dict` is a legal pair — `[]` the value inhabits both types, but no compilable operand type reaches both rows — while `Number + Number` beside `Literal(5) + Literal(5)` is refused, because a `5`-typed operand resolves both and no precedence rule exists to pick one. Construction is the earliest moment the conflict exists. List order decides nothing: it is a registration order, not a precedence.

Because the compiler consumes the composed dialect and the program embeds what it resolved, dialect drift by miscomposition — checking with one set of rules and running another — is not merely guarded against; after compilation there is no dispatch left to miscompose.

### 3. Compilation — inference that also builds the program

Inference and evaluation used to be two walks over the `Source` tree: the checker computed a type per node, and a resolver layer re-walked the same tree per call, re-dispatching operators on values at every visit. This design merges them: **inference is the compiler.** Each syntax-directed rule computes the node's type *and* emits the node's evaluation, as one `CompiledNode` — the source-level twin of `ResolvedOperation`:

```php
final readonly class CompiledNode
{
    public function __construct(
        public Type $returns,
        Closure $evaluation,   // (Runtime): Result<Option<mixed>, Throwable>
    );

    public function evaluate(Runtime $runtime): Result;
}

final readonly class TypeInference   // the compiler
{
    public function __construct(
        private BinaryOperatorResolver $operators,
        private UnaryOperatorResolver $unaryOperators,
        private LiteralTypeRegistry $literals,
    ) {}

    /** @return Result<CompiledNode, TypeMismatch> — causes carry multiplicity */
    public function compile(Source $source, TypeEnvironment $environment): Result;
}
```

The `Runtime` a compiled evaluation receives is small: the admitted bindings, the lazily-memoized definition slots, and the optional invocation-scoped execution observer. It carries no dialect and no resolver — there is nothing left to dispatch. Observation is passed to `Program::call()`, never persisted with the source tree or retained by the compiled program. Every compiled source node emits an ordered enter/annotation/exit (or throw) lifecycle, including nodes supplied by host source compilers.

#### The environment walks the symbol graph

In the engine, a symbol is satisfied by a per-call binding (`Bindings`, raw values) or by the **`Source`** it names in `Definitions` — defined symbols are derived expressions. A flat pre-seeded type map would force someone to hand-declare the type of every derived symbol: the mirror again. Instead `TypeEnvironment` walks the symbol graph — declarations give the types of bindings, definitions compile recursively:

- A defined symbol compiles to a **definition slot**: its `Source` compiled in the **same `Definitions` the program embeds** — one registry, both semantics. At runtime a slot evaluates lazily and memoizes per invocation; it is compiled exactly once.
- **Declared types terminate recursion**: a host-declared input type (`turnover: Money<GBP>`) enters as a declared leaf; a host source terminates via its own compile face; `StaticSource` terminates via the literal registry.
- **Memoized**, same key scheme as the runtime slots (`namespace.name`).
- **Cycle detection is a graph property, not a typing feature**: declarations answer *typing*, never *termination*, so a declared type must never terminate the cycle walk (an in-progress set inside inference misses exactly the cycles the declaration short-circuit truncates — self-cycles and mutual cycles alike). Well-foundedness is a standalone DFS over the `Definitions` symbol-reference graph, independent of declarations, run by `compile()` before typing and reported as "cyclic symbol definition: a → b → a". The former *runtime* re-entry backstop dies with unchecked evaluation: every program that runs has passed the graph check, so there is no re-entry left to guard.
- Unbound is an error; a scope that tolerates unknown symbols binds `UnknownType` explicitly — and then must bridge it before any operation touches it.

#### Syntax-directed rules, one per node

- `SymbolSource` → environment lookup as above.
- `StaticSource` → **literal-first inference**: scalars infer as `LiteralType` always (values are immutable; there is no mutation-driven reason to widen). `Literal('shop')` is assignable to `String` wherever needed, and equality/membership get sharper: `x == "warehouse"` where `x: 'shop' | 'office'` is supported by value equality but rejected as dead under `overlaps`. Domain literals via `LiteralTypeRegistry`: value-class → type, plugin-extensible (core registers scalars and lists; `axiom-money` registers `Money`; a host registers its own).
- List literals → **union element unification with exact bounds**: `[money<GBP>, money<USD>]` is `List<Money<GBP> | Money<USD>, 2, 2>`; `['shop', 'office']` is `List<'shop' | 'office', 2, 2>` (which is what makes `x in ['shop', 'office']` typecheck precisely against an enum-typed `x`); `[]` is `List<Never, 0, 0>`. Same join as `match`, same position, same precision — never equivalence-else-`Unknown`.
- `Coerce(type, source)` → the declared type, **verbatim, unchecked**. The boundary node: runtime converts via `coerce()`; the boundary is statically opaque *by design* (coercion is admission policy, not membership — see the coercion-is-the-boundary law). The old `TypeDefinition` name is retired: it always behaved as coercion, and "definition" read as annotation — exactly the confusion that produced a review finding.
- `Ascription(type, source)` → the declared type, **checked**: the inner type must be `Unknown` or `overlaps` the declaration; disjoint is an error (a false claim, assert-world, where overlap *is* the correct relation — TypeScript's `as` draws the same line). Runtime verifies via `assert()` and fails loudly, so a lying ascription is a tripwire, not a rot vector. The author's annotation: refine an `Unknown` host source, narrow a union.
- `UnaryExpression` / `InfixExpression` → compile the operands, `resolve` the operator against their types through the composed stacks, bind the `ResolvedOperation` into the node (with the absence short-circuit wrapped around it); unary applies the structural `Option`-propagation rule first.
- `MatchExpression` → see below.
- `MemberAccessSource` → see below.
- **Host sources** → exact-class compile-time adapters contributed by extensions. The old shape — a type claim in `TypedSource::returnType()` and behavior in a runtime `Resolver` registered separately in a class map — was the two-faces disease in its worst form: nothing, not even a harness, watched that the resolver produced what the type claimed. The first compilation-pivot draft put `compile()` on the source itself, but that put dependency-injected services into persisted source trees. The final seam keeps sources as data descriptions and moves compilation ownership to the extension:

```php
abstract class Extension
{
    /** @return array<class-string<Source>, Closure> */
    public function sourceCompilers(): array { return []; }
}

// Callback shape:
fn(MySource $source, SourceCompilation $compilation): CompiledSource
```

A host source contains only what must survive persistence (a geocoding source contains its address source, not a `Geocoder`). The extension holds live collaborators and its callback returns the type and evaluation together; that evaluation alone captures the collaborator in the compiled `Program`. `SourceCompilation::child()` and `children()` compile persisted children in the current environment and abort the current adapter straight back to the compiler on a `TypeMismatch`, so adapters write straight-line PHP rather than compose compilation `Result` values. They produce `CompiledSource` values through present mapping, absence-aware mapping, named child composition, constants, or the restricted `custom()` escape hatch; present mapping derives an optional result whenever an input is optional, so a propagated absence cannot hide behind a non-optional claim. `CompiledNode`, `Runtime`, and the internal `Result<Option<...>>` channel stay behind the seam. `SourceCompilation::infix()` additionally binds a callable `BoundOperation` from the composed dialect for runtime values whose types the source owns, so host sources reuse extension operators and diagnostics without reintroducing value-directed dispatch. Map keys are exact-class ownership declarations, and duplicate owners are a dialect construction error.

A compiler that knows its result declares it with its evaluation beside it; one that genuinely cannot know returns `Unknown`, whose value must then be bridged before use. An unhandled node is a compile *error*, not a silent `Unknown` — "any expression edge starts here" stays a kept promise. There is no separate type registry for source behavior, so type claim and behavior cannot drift *apart*. What co-location cannot do is prove the evaluation honors the claim: a source compiler callback is an operator rule with zero operands and carries the identical **fidelity obligation** — trusted the same way, tested by the same harness (§Drift guarantees).

#### `match`: union join, mandatory exhaustiveness

- The type of a match is the **union of its arm types**, normalized. Arms that agree collapse to the single type; literal arms preserve enum precision (`match kind { 'a' => "low", 'b' => "high" }` is `'low' | 'high'`); cross-base disagreement surfaces at the *use site* through pessimistic `admits`, with a mismatch naming both branches.
- **Non-exhaustive fall-through is a runtime error** (`MatchResolver` changes from `Ok(None())` to `Err` — Phase 0), and correspondingly a **compile error** when exhaustiveness is not provable: "add a wildcard arm". Provably exhaustive means: some arm is a wildcard, or the scrutinee's type is `Boolean` or a union of literals and the literal patterns cover every member. `ExpressionPattern` arms match at runtime but never count toward coverage. An `Option` scrutinee additionally requires a literal `null` pattern (covering the `{null}` member) or a wildcard. `Never` is **vacuously covered** — it has no inhabitants — so `match null { null => … }` (scrutinee `Option<Never>`) is exhaustive with the `null` pattern alone. Coverage and the runtime matcher consume **one equality definition** (value equality — `5` and `5.0` are the same literal on both faces); a matcher stricter than the coverage analysis certifies exhaustiveness the runtime then fails.
- Net discipline: a wildcard arm is mandatory *except* over enum/boolean scrutinees the author has fully covered — and adding a variant to an enum input turns every non-wildcard match over it into a located compile error. Exhaustiveness checking falls out of the type; it is not a separate feature.
- Zero-arm matches (if the grammar ever admits them) type as `Never`.

#### Member access

Member access is **shape-driven** — it dispatches on the operand's projection, not its concrete `Type` class, so any type whose (census-verified, therefore true) projection is record-like gets field access, extension types included. Field shapes **reify** back to types (`Shape → Type` is mechanical over the sealed constructors; an opaque field reifies to `OpaqueType` — an `@internal` reification artifact, statically nominal, dynamically **fail-closed**: core cannot verify membership of a host-owned identity, so its `assert`/`coerce` reject every value with a message naming the host as the owner. The public vocabulary is `OpaqueShape` plus a host-owned `Type` class with a real `assert`; a fail-open placeholder would duplicate `Unknown`'s job while wearing a nominal certificate — and, once fixed rules dispatch on `assert`, would claim every non-null value for any rule declared over it).

- Record-shaped operand, declared field → the field's shape, reified. Record coercion canonicalizes, so declared-field access never hits the missing-key error.
- `Option<record-shaped>.field` → `Option<FieldType>` — optionality propagates, mirroring the compiled evaluation; chained optional access stays clean because `Option<Option<T>>` collapses by theorem.
- `Unknown`-shaped operand → compile error: `Unknown` is inert; ascribe a record type first.
- Undeclared field → compile error. Records are exact, so "the field might be there anyway" is not a representable state — the optimistic hole `admits` closes for unions cannot reopen through records.
- **Dict-shaped operand → compile error** (strict): a dict's nature is "keys unknown statically", so every access is fallible, and the runtime's missing-key `Err` stays exactly as is. `Dict` is a transport type you *type your way out of*. Escape valve reserved for later: an explicit `get(dict, key)` function typed `Option<V>`.
- **Opaque-shaped operand → compile error**: nominal types make no structural claims, so there is nothing to certify accessing.

Shape-driven access is only sound *because of* the shape-truth law: an earlier design dispatched on concrete classes precisely to avoid trusting projections, which broke extension types; trusting projections without the truth law would have certified crashes on fictional fields. Truth first, then trust.

#### `check` is `infer` + assignability (bidirectionality reserved for lambdas)

Literal-first inference and value-set `Option` semantics dissolve the bidirectional special cases into assignability theorems: `null` fills `Option` slots because `Option<Never> ⊆ Option<T>`; `"shop"` fills the enum because `Literal ⊆ Union`; `[]` fills any admitting list because `List<Never, 0, 0> ⊆ List<T, min≤0, _>`. **v1 `check(expr, expected, env)` is literally `infer` then `isTypeAssignableTo`** — no special cases to disagree with the relations later. The bidirectional *API* stays, because future lambda inference genuinely needs expected-type propagation to infer parameter types.

### How hosts consume the compiler

The compiler *is* the API — hosts hold runtime-AST programs, compile each once, and invoke the compiled artifact many times, through one object that owns everything type-relevant:

#### The `Dialect` and its extensions

The operator rules live in exactly one place. A `Dialect` is a value object composing the binary resolver, the unary resolver, and the literal registry, consumed **at compile time only**: `compile()` resolves every operator node against it and binds the resolutions into the `Program`. Checking with different rules than you run with is not representable, full stop — a compiled program carries no dialect at all, only what the dialect resolved, so there is nothing at runtime to miscompose. Packages contribute through an `Extension` (an abstract class with empty-default hooks — `operators()`, `unaryOperators()`, `literals()` — abstract so hooks like `matchers()` can be added later without breaking implementors):

```php
$dialect = Dialect::core()->with(new MoneyExtension(), new TimeExtension());
```

Extension rules **join** core's — order carries no meaning, because no tie is ever resolvable: two rules for one operator with jointly admissible slots are refused at construction (§2), whichever contributed them. Duplicate literal registrations are **loud errors** for the same reason (a configuration bug, not a precedence question).

#### Typed bindings: the boundary

Certification is conditional — "*if* inputs inhabit their declared types, this program is sound" — and the boundary establishes the condition, on every call of the compiled `Program`. `compile()` proves the program; it cannot prove future inputs — which is why the boundary is the one runtime type check that survives compilation, *by design*. The same declarations map serves both faces: statically it seeds the `TypeEnvironment`; at invoke time each declared binding passes through its declared type (`coerce` by default; `assert` for strict hosts) **before** evaluation begins:

- Declared input, bad value → boundary error, pre-evaluation, aggregated across all bad inputs, named by binding (`binding [customer]: field [turnover]…`) — errors speak the host's language, not the AST's.
- Declared required input, missing → boundary error (all missing inputs reported at once). Declared `Option`, missing or null → legal absence.
- Undeclared extra keys → **stripped** before evaluation (superset contexts stay legal — hosts may pass the whole context bag; only the declared slice enters). Undeclared *parameters* are the explicit gradual path: unbound-symbol errors, statically and at runtime, unless declared `Unknown`.
- **Declarations and definitions are disjoint namespaces**: a symbol is a parameter *or* a derived value, never both. Collision is a **constructor error**, before any call. Together with stripping and the death of descent, this makes shadowing *unrepresentable* rather than licensed: symbol lookup consults exact keys only, so no binding value can ever answer for a definition. Shadowing is modeled in-language instead — an `Option`-typed parameter the definition consults (`riskFactorOverride: Number?`), explicit in the program, certified on both paths.

The guarantee, stated honestly: *declared inputs cannot deliver garbage past the boundary; undeclared inputs cannot touch anything at all — they are stripped, an explicit `Unknown`, or a named error. The declaration list is the expression's complete public signature; the only trust remaining is the trust written down.*

#### Symbols are names; member access is structure

`Bindings` stores what it is given and answers **exact keys only**. The associative-array-means-namespace heuristic is dead, and so is its successor, descent: nothing ever digs into a binding's value to answer a symbol lookup. A namespaced symbol (`SymbolSource('turnover', 'customer')`) is the flat key `customer.turnover`, found among bindings or definitions by exact match — a namespace is a naming convention, exactly as `Definitions` already treats it. Reaching *into* a record value is the explicit `MemberAccessSource` node, certified against the record's declared fields. One value, one reading — the host chooses at declaration time: a namespaced parameter (`'quote.turnover' => Number`, bound as `['quote.turnover' => 600000]`) or a record parameter (`'quote' => Record`, bound whole, fields reached by member access).

Typed value objects at the call site (`TypedValue::of($type, $value)` bindings) were rejected: they put types on the wrong side of time — certification needs types *before* values exist — reopen the two-sources-of-truth drift the declarations map closed, and presuppose hosts convert values before the boundary whose job is converting. The co-location instinct is honored where it's sound: declaration-with-enforcement on the `Expression`, not type-with-value at the call site.

#### `Expression` describes; `Program` runs

`Expression` owns the source, the dialect, the definitions, and the declarations — a complete *description* of a program, and deliberately not a runnable one. The split:

```php
$expression = new Expression($source, definitions: $definitions,
    declarations: ['radius' => new NumberType()]);

$program = $expression->compile();   // Result<Program, TypeMismatch>
// Err: cycles, unbound symbols, unresolvable or ambiguous operators,
//      inert Unknown at an operand, false ascription claims — all here, named

$program = $program->unwrap();
$program->returns;                   // Type — a property of the artifact, not a query
$program(['radius' => '5']);         // boundary coerces, then evaluates — no dispatch
```

Evaluation presupposes a passed check the way admitted values presuppose the boundary: `call()`/`__invoke` live **only** on `Program`, so running an unchecked program is unrepresentable — the same move as disjoint namespaces and the compiled-in dialect, applied to the program itself. `$expression->infer()` and `->check($expected)` remain as conveniences over `compile()` (the type of the compiled artifact; compile plus one assignability test). The constructor enforces the disjointness of declarations and definitions; `compile()` runs the definition-graph well-foundedness pass before typing. Hosts with stored corpora get the natural economics: compile once at authoring or deploy time, invoke per request — no per-call inference walk, no per-node dispatch, definitions resolved once.

- **Corpus sweeps**: to migrate onto the strict runtime, a host runs `check`/`infer` across its stored programs and triages the mismatches — non-exhaustive matches, non-boolean negations, dead comparisons all surface before any evaluation happens. The sweep tool for the runtime strictness is this API, not an authoring-surface feature.
- False `Ascription` claims (declared type disjoint from the inferred inner type) surface in the same sweep. `Coerce` nodes do not — coercion satisfiability is deliberately not modeled statically (a lint-grade `CoercionAware` opt-in may arrive later; its absence costs a diagnostic, never soundness).

## What stays in hosts

- **Domain types** — anything whose meaning is the host's (addresses, claims, catalogue keys) implements `Shaped` (projecting into the sealed algebra — `Opaque` where structure shouldn't leak) and, where a literal class exists, registers with the literal registry.
- **Dialect composition** — which operator rules the compiler resolves against was always the host's choice; it remains exactly that, with static semantics and evaluation contributed together.
- **Policy relations** — derived relations that encode a host's configuration policy (e.g. "does a partial supply agree with an interface on every member it does supply?", built as assignability against a masked interface) **stay downstream**, built on the upstreamed registry. This is host policy, not a language relation; upstream it later only if a second host independently reinvents it.
- **Enforcement** — gates, sweeps, feature flags, when a finding blocks anything: entirely host concerns. The language reports; the host decides.

## Companion package: `axiom-time`

`Date`/`Period` live in a companion package, not core — and deliberately so: **`axiom-time` is the canary for the extension seams.** It exercises every one of them at once — a `Shaped` type, a literal-registry entry for its value class, and the hardest case: an *ordered* domain type contributed from outside core. If `axiom-time` can be built cleanly outside core, hosts can; if it can't, the missing seam is found before a host finds it. Core additionally stays neutral on genuinely contested policy (date vs datetime, timezones, calendar arithmetic, locale).

How it lands: `Date` projects as the package chooses (`Opaque`, or a branded record if fields should be accessible). Core has no opinion on `Date` ordering — orderability lives only in the dialect — so the package declares ordering rows (`< : (Date, Date) → Boolean` and siblings), the resolver takes the lone resolution while core's rows refuse, and "dates are ordered now" is *true by the same fact that makes it work*. `Date − Period → Date` and `Date − Date → Period` are two more rows beside them.

## Release plan

**One breaking release** (`0.5.0`, we are pre-1.0) containing Phases 0–4 — Phase 4 is not optional to the release: after the compilation pivot there is no unchecked evaluator left to ship, so a Phases-0–3 release is not a representable state. The runtime strictness and the checker ship together deliberately: the inference API is **the migration tool** for the runtime strictness — a released version with the strict runtime but no way to sweep a program corpus would leave hosts discovering breakage one unlucky evaluation at a time, the exact failure mode this RFC's Motivation opens with. The awkward in-between state never exists in a released version.

Internally, the release is staged as a PR series, in this order:

- **Phase 0 — runtime honesty** (behavioural fixes the static layer will certify):
  1. Value-domain cleanup: `assert` becomes strict membership (`""`/`'null'` are `String`s, `[]` is a `Dict`); absence readings stay in `coerce` only.
  2. `supportsOverloading` honesty audit: `ComparisonOverloader` claims scalar/null/array equality and numeric ordering only; `LogicalOverloader` booleans only.
  3. Boolean-only `!` (kills PHP truthiness).
  4. `match` fall-through: `Ok(None())` → `Err`.
- **Phase 1 — vocabulary and relations**: the sealed shape algebra + `Shaped` + `TypeRelations`/`TypeMismatch`/`TypeDescriber`, with a full-coverage suite and a shape-soundness census with two laws: **(C1)** every projected or `Shaped` type must have specimens (the census fails when one doesn't), and **(C2, shape truth)** for every record-projected type, over its specimens, every projected field must be reachable by the member-access mechanism and inhabit the field's shape — the generative enforcement of the shape-truth law.
- **Phase 2 — typed operators**: static semantics land on the operator contracts; unary and binary resolvers index operator-owned rules; core rules gain their static faces; the resolvers compose and resolve as above; the harness lands.
- **Phase 3 — inference**: `TypeInference`, graph-walking `TypeEnvironment` with cycle detection, `LiteralTypeRegistry`, extension-owned source compilers.
- **Phase 4 — compilation**: the operator contract collapses to one `resolve` face; `Expression::compile()` → `Program` with evaluation living only on the artifact; `Unknown` becomes inert; the resolver layer and runtime dispatch are deleted; the harness becomes the totality + admission-honesty suite.

`axiom-money` follows the release; hand-maintained mirror registries downstream are deleted — the composed evaluator stack *is* the registry now. Host sources become data-only nodes with extension-owned compiler callbacks (most callbacks can honestly declare `Unknown` at first — exactly what hosts bind for them today).

**Host migration path**: bump the dependency in a branch → sweep the stored program corpus with `check`/`infer` → triage the mismatches (behaviour changes vs latent bugs) → fix or consciously accept each → deploy runtime and checker together. One migration guide for one release.

**Sequencing gate**: the downstream layer this design is lifted from is still hardening against its first consumers, and its operand-judgement surface changed twice in one review cycle. The release lands once that surface has survived at least one consumer unchanged — freezing an API into a shared package a week early is how the API gets designed twice.

**Follow-ups after `0.5.0`**:
- `axiom-time` (any time after; the seam canary).
- The narrowing bundle on the runtime AST: `is`-type patterns in `match` (which is what makes cross-base unions like `Number | String` usable at operand positions) and literal-pattern scrutinee narrowing inside arm bodies.
- Explicit `get(dict, key): Option<V>` if hosts demonstrate the need.

## Future work: a typed authoring surface

If a textual authoring surface is ever built over this engine, it builds on layers 1–3 and must ship *with* its checker wired in — there must never be a released authoring surface whose typed syntax is uncheckable. Decisions recorded now so the algebra and a future grammar can't drift:

- **Wiring**: declarations seed the `TypeEnvironment`; expression bodies infer through the plugin-composed operator resolvers; assertion constructs go through `check(expr, Boolean, env)`; coercion syntax is the textual face of `Coerce`, ascription syntax of `Ascription`; compilation results carry located diagnostics (`TypeMismatch` + a source location), each tagged behaviour-change vs latent-bug for corpus triage.
- **Annotation grammar**: one spelling per constructor, no synonyms. All type identifiers UpperCamelCase (`Boolean`, `Number`, `String`, `List<T>`, `Dict<T>`, `Money<GBP>`), giving the lexical law *uppercase initial = type, lowercase initial = symbol*. Postfix `?` for options (`T??` canonicalizes). Infix `|` for unions with bare literals as types (`'shop' | 'office'`) — no `enum{}` form. Records named via schema declarations only. No bounds syntax in v1. `Unknown`/`Never` unspellable — derived, never authored.
- **Functions and lambdas**: signatures on a function registry first, lambda parameter inference from expected types later, via the reserved bidirectional `check`.

## Drift guarantees

The central guarantee has **two named layers**, and the document is careful about which one each mechanism delivers:

- **Inseparability, by construction.** A rule's typing and its evaluation are one return value from one call (`ResolvedOperation`; `CompiledSource` at the source compiler seam), so *selection and evaluation cannot drift apart*: a program can never evaluate under a different rule than the one that certified it, because rule and evaluation arrive — and are embedded — as one value. This layer is an identity; no test is needed or possible.
- **Fidelity, by certified obligation.** That a certified evaluation is *total* over its certified operand types and *lands in* its declared return type is not provable by construction — a closure is arbitrary code. It is an author obligation at a **trusted boundary**, with the standing FFI has in typed languages: the library trusts what extension code certifies and never re-checks certified results at runtime (re-checking would be the double check again — and unsound anyway, since a lying closure can lie per call). What earns the trust is the harness below. The boundary is the same for every extension seam: operator closures and source compiler callbacks carry the identical obligation.

1. **One verdict** — the inseparability layer, for operators: typing and evaluation are one statement. This covers unary operators too; there are no operator rules outside the contract.
2. **The program embeds its resolutions** — the compiler consumes the composed `Dialect` and binds what it resolved into the `Program`; a compiled program carries no dialect and performs no dispatch, so checking with one set of rules and running another is not guarded against but *gone*. The environment extends the same principle to symbols: one `Definitions`, compiled once, both semantics.
3. **The totality harness** — the fidelity layer's test, with honestly bounded quantifiers. The harness does not — cannot — sweep the infinite type algebra; its semantics are three tiers:
   - **Enumerated**: a finite **specimen family** — types chosen to cover every shape constructor, each with hand-picked edge values (empty list, `None`, zero, negative numbers, empty string, …). This is the part an author curates.
   - **Generated**: the sweep — for every family pair a rule resolves `Ok` for, every specimen value pair must evaluate without escaping, to a result inhabiting the resolved return type; plus the admission-honesty law (below) over every type in the census.
   - **Trusted**: everything outside the family. The harness is **evidence, not proof** — a certification test that samples the domain at its edges; totality over the full domain remains the author obligation the trust boundary names.
   The extension obligation is concrete: **add specimens for every type your rules mention** (each opaque type, each registered literal class) **and re-run the sweep** — a money package adds `Money<GBP>`/`Money<USD>` specimens and the sweep covers money×money, money×number, money×everything automatically. The other census laws stand unchanged: **admission honesty** (for every `Type`, first-party and extension alike, `coerce` output must pass `assert` — compile-then-trust rests entirely on this) and **shape truth** (C1/C2: projections are census-verified truth claims).
4. **Ambiguity is refused** — two rules resolving the same operator over jointly admissible slots is a construction error for rows and a compile error otherwise, naming both rules. A new overload (date-period subtraction beside numeric subtraction) can never silently change what an existing program means — it either composes cleanly or is refused loudly. List order decides nothing.
5. **Admission is the only gate** — values are inspected at exactly three places (the binding boundary, `Coerce`, `Ascription`), every one an explicit, author-visible node or declaration. Nothing else at runtime reads a value's type, so there is no hidden lenient path to drift away from the checked semantics.

## Key decisions

A consolidated record of the decisions this design makes, with the alternatives rejected and why. The mechanisms themselves are described in the sections above; this is the decision index.

### Overall approach

- **Upstream the host-agnostic core; don't keep it downstream.** Rejected the status quo (host-side mirror + conformance tests): the mirror is a standing invitation to drift, tests catch divergence only after the fact, and every host pays again. Rejected a sibling `axiom-types` package: the typing nodes (`Coerce`, `Ascription`) already live in the runtime AST, and splitting `typeOf` from `evaluate` across packages reintroduces exactly the seam this RFC exists to close.

### Types, shapes, and relations

- **Sealed shape algebra, projection-only extensibility.** Types project into a fixed shape vocabulary via `Shaped`; hosts cannot add constructors or edit relations. Rejected an *open* algebra (relation rules on shapes, double dispatch): it admits mutually inconsistent rules ("who wins when `A->assignableFrom(B)` and `B->assignableTo(A)` disagree?"), and the relation laws stay checkable only if the case analysis is exhaustive.
- **Records are exact — no open records, no width subtyping.** A record's value set is fully described by its declared fields, which is what makes whole-record equality total. An open tail is unclaimable by any total verdict (`==` over an open record certifies crashes). Boundary tolerance of wide input is re-homed to `coerce` (takes the declared slice); `assert` stays strict membership (extra keys are rejected). "Named fields plus arbitrary extras" is spelled `Dict`. (TypeScript makes the opposite trade and pays with unsound record equality; erased types never meet a runtime, ours certify one. Reintroducing openness later is additive; removing it later would have been breaking.)
- **Object-valued types project `OpaqueShape`, never fictional records.** `OpaqueShape` gains structural parameters (nominal head, parameter-wise relations), so currency subtyping survives without the lie. A fictional record projection leaks through assignability — `Money` becomes assignable to `{amount: Number}` slots whose certified member accesses then crash on the actual object. The discriminant-field record encoding stays legal where values genuinely are records.
- **`OpaqueType` is an `@internal`, fail-closed reification artifact.** The public vocabulary is `OpaqueShape` plus a host-owned `Type` with a real `assert`. Core's `OpaqueType` exists only so opaque field shapes can reify, and rejects every value (naming the host as owner). A fail-open placeholder would duplicate `Unknown`'s job while wearing a nominal certificate — and, once fixed rules dispatch on `assert`, would claim every non-null value for any rule declared over it.
- **The empty array inhabits both `List` and `Dict`.** PHP has one value where the algebra has two types; membership follows reality (`array_is_list` admits `[]`), and `assert` never reindexes (claiming never converts). Consequently `overlaps(List, Dict)` holds at the shared member `[]`, so `list == dict` is a *live* comparison (`true` iff both empty), not `dead`. Assignability stays structural and conservative — soundness for assignability, completeness for overlap, deliberately asymmetric.
- **Member access is shape-driven, sound only under the shape-truth law.** Access dispatches on the operand's census-verified projection, so any record-like type (extensions included) gets field access. Rejected an interim `HasFields` counter-proposal: it patched direct access while leaving the assignability leak open.
- **Orderability is a property of the dialect, not a type.** "Is `<` meaningful for `T`?" has exactly one authority: does the dialect resolve `<` for `(T, T)`? A static `TypeOrder`/`hasDefinedOrder` oracle was deleted — a second authority that extension rows prove wrong the moment a date package ships (`hasDefinedOrder(Date)` false while `< : (Date, Date)` compiles). Nothing consumed it; it was kept alive only by its own test suite.

### Value domain and equality

- **One built-in data-equality authority: `ValueEquality`.** It owns both its evaluation and the support judgment describing where that evaluation is total. It is consumed by the built-in equality operator, membership, literal-shape identity, and match coverage. Value equality never uses PHP juggling or object identity: numeric within `Number` (`1 == 1.0`), strict otherwise, `false` across bases (`5 == '5'`). `===`/`!==` become aliases of `==`/`!=`. Opaque values are refused; their owning packages contribute equality rows with domain-specific evaluations. This is what makes a `dead` verdict mean constant-false by construction without pretending overlap establishes comparability.
- **`assert` is strict membership; `coerce` is admission policy.** `""`/`'null'` are `String`s and `[]` is a `Dict` under `assert`; the absence readings live only in `coerce`. **Admission honesty**: `coerce` output must pass `assert` — `Dict::coerce([])` yields `Some([])` and rejects non-empty lists exactly as `assert` does (the first implementation coerced `[1, 2]` to itself, admitting a value the type's own `assert` refuses past a certified boundary). A per-type static coercion relation (`acceptsInput`) was rejected for the core contract — its absence costs a lint, not soundness; may return later as opt-in `CoercionAware`.
- **`coerce` survives, demoted to the boundary contract.** Membership vs admission; two homes (typed bindings, `Coerce`); statically opaque by design.
- **One representation of null in the resolution channel.** The value `null` travels as `None`; `Some(null)` never escapes a compiled node. The coercion channel keeps its two-value protocol (`Some(null)` "present null" vs `None` "read as missing") but consumes it at boundaries only, never propagates it. Rejected making null "present" everywhere — it would break `None`-propagation engine-wide.

### Operators

- **Operator-owned, explicit verdicts.** `operator(): string` plus `resolve(Type, Type): OperatorResolution` replaces the four-method value-directed contract (`supportsOverloading`/`evaluate`/`handles`/`typeOf`) and the intermediate monadic protocol. `ResolvedOperation`, `UnsupportedOperation`, and `DeadOperation` make all outcomes explicit; `UnaryOperatorRule` collapses identically. Typing and evaluation are one return value, so face-agreement is an identity, not a tested property. Rejected a parallel compatibility tier — this is a hard break (pre-1.0), with no adapter. Dispatch-time value inspection and per-rule symbol routing disappear wholesale, including equality's per-evaluation recursive `isComparable` walk.
- **The fixed-rule builder is the extension front door.** A declarative staged row — `Operator::infix('-')->takes(new DateType(), new PeriodType())->returns(new DateType())->evaluatesWith(fn (Date $d, Period $p) => $d->minus($p))` — produces an ordinary rule (each step a distinct value; the final `evaluatesWith()` completes and returns the rule, with no `build()` to forget). Both faces derive from one declaration (runtime claim = strict `assert`, static verdict = `admits`), so the harness laws hold by construction. Return types are **fixed, not computed**: a computed-return callable that refuses (money's cross-currency case) while `assert`-based claiming still owns the pair violates anti-shadowing. Parameterized families (money) enumerate their host-finite parameter space, one row per parameter. Non-row rules (overlap-based verdicts, dead findings, computed return types, absence-tolerant claims) keep the raw contract as the documented escape hatch.
- **Ambiguity is refused, not absorbed.** Two rules resolving the same operator over jointly admissible slots is an error naming both — at `Dialect` construction for rows, at compile time otherwise. The old "disagreeing verdicts type as `Unknown`" rule died with value-directed dispatch. List order carries no meaning. Consequence, owned deliberately: an absence-policy *row* (`+` over `Option<Number>`, absence-as-zero) cannot coexist with core arithmetic — `Number ⊂ Option<Number>`, so a present pair would have two owners; the honest spelling is a hand-written type function resolving only genuinely-absent operand types. Most-specific-wins resolution (the C#/Swift move) is additive if a host ever needs it.
- **Row ambiguity is joint admissibility, not value overlap.** Dispatch resolves operand *types* through `admits`, so the conflict test is `jointlyAdmissible` (does some inhabited type reach both slots?), not `overlaps` (a value question — the last surviving piece of value-directed thinking). `List+List` beside `Dict+Dict` is legal; `Number+Number` beside `Literal(5)+Literal(5)` is refused; `Option<Number>` beside `Option<String>` is refused. Construction-time refusal is kept deliberately — deferring to a compile-time error would let a miscomposed dialect ship and explode only when some user's expression hits the pair.
- **Unary overloadability, with structural absence.** `UnaryOperatorRule` in the same release, boolean-only `!` (`!5` is a compile error). The compiler wraps unary rules with the `Option`-propagation short-circuit, so a unary rule never sees `null` — a host cannot write an absence-handling unary rule (deliberate). Binary keeps per-rule absence policy, because dialects genuinely differ there.
- **Totality of raw verdicts.** `resolve` certifies only operand types whose *every* value the runtime face claims: universal over reachable union members and container elements, opaques refused where runtimes refuse objects. Built-in equality obtains this judgment from `ValueEquality::supports`; overlap is a later liveness check, never a totality claim. The harness's totality law flips from filter to assertion — an unclaimed specimen of a certified pair is a harness failure. Rejected domain-carrying verdicts (partial coverage composed across rules): a second algebra to certify exactly what pessimistic `admits` already refuses; authors narrow with `match`.

### Compilation and runtime

- **Evaluation presupposes a passed check.** `Expression::compile(): Result<Program, TypeMismatch>`; `call()`/`__invoke` live only on `Program`. Running an unchecked program is unrepresentable — checkedness is a type in the API, not a guarded path. This is the keystone: value-directed dispatch existed *because* evaluation could not assume a check had run; once it can, overload resolution is a compile-time operation (as in C#/Swift/Java) and the runtime check is deleted, not duplicated. Rejected lazy check-on-first-`call()` (keeps "unchecked program" representable, turns a type error into a runtime `Err` on the first unlucky invocation) and a compilation side-table over the resolver walk (keeps the per-call walk, per-node dispatch, and the split host seam).
- **`Unknown` is inert; `Coerce` and `Ascription` are the only bridges.** `admits` loses its top-level-`Unknown` hole; operators, comparisons, and member access refuse `Unknown` operands with a message pointing at the fix. Rejected compiler-planted implicit gradual casts — invisible machinery; the language already has the explicit spellings, and they are the same pair as the boundary's two faces: `Coerce` (convert a representation) and `Ascription` (claim a membership). This is also the resolution of dead-coercion detection: `TypeDefinition` split into `Coerce` (runtime `coerce`, statically verbatim) and `Ascription` (runtime `assert`, statically `Unknown`-or-`overlaps`).
- **Absence cannot cross a non-optional `Coerce`.** When the declared type is not `Option`-shaped and the value reads as missing, resolution is a runtime `Err` naming the node — never a silent `None`. Inference stays verbatim (`Coerce : T`, statically opaque); it was the runtime face that was dishonest — certifying `Number` while delivering `null` into `+`.
- **The source seam becomes compile-time extension ownership.** `TypedSource::returnType` (a type claim in one place) and its runtime `Resolver` (behavior registered in a class map) were two faces nothing verified against each other. A source compiler callback now returns a composable `CompiledSource{returns, evaluation}` in one step. The exact source-class map lives on `Extension`, preserving dependency injection while keeping persisted `Source` trees data-only; only the compiled evaluation captures live collaborators. `SourceCompilation` provides straight-line child compilation and typed operation binding from the composed dialect without exposing the full compiler or its runtime channel. This is the compile-time replacement for host sublanguages that formerly called `OperatorOverloader`: they bind one operation from certified operand types and reuse it, rather than dispatching from values. The runtime `Resolvers/` layer, per-call `Context`, and container dependency remain deleted. First-party node semantics stop being host-swappable — the checker certifies them, so silent replacement was a drift channel, not a feature.
- **`match`: union join, mandatory provable exhaustiveness.** The type is the union of arm types; non-exhaustive fall-through is a runtime error (`Ok(None())` → `Err`) and unprovable exhaustiveness a compile error ("add a wildcard arm"). Rejected `Option`-wrapped results — honest but preserves a silent-absence runtime.
- **Well-foundedness is a graph property.** Cycle detection is a standalone DFS over the `Definitions` reference graph, independent of declarations. Declarations answer typing, never termination — a declaration-terminated cycle walk certifies self- and mutual cycles that recurse unboundedly at runtime.

### Bindings, boundary, and hosts

- **Symbols are exact keys; descent is deleted.** `Bindings`/`TypeEnvironment` answer exact (dotted) keys; nothing digs into a binding's value to answer a lookup. `MemberAccessSource` is the one structural path. Any un-enumerated width — an open record, a dict's keys equally — could otherwise answer a lookup the checker refuses, and no collision check can expand unenumerable width; deleting the mechanism closes the class, where guarding (declaration-aware descent) or banning (namespace-collision errors) keeps two mechanisms in sync. Calling convention: bind keys exactly as declared.
- **Declarations and definitions are disjoint namespaces.** Enforced at construction; the boundary strips undeclared keys. Shadowing becomes unrepresentable — no license rule, no descent hole, no declared∧defined agreement check to maintain. Overrides are modeled in-language as `Option`-typed parameters the definition consults.
- **Typed bindings are the boundary.** The same declarations map seeds the `TypeEnvironment` statically and coerces/asserts declared inputs pre-evaluation (aggregated, named by binding). This is the one runtime type check that survives compilation, by design — `compile()` proves the program but cannot prove future inputs. Rejected typed value objects at the call site — they put types on the wrong side of time.
- **One `Dialect` value object, consumed at compile time only.** Packages contribute via `Extension` (abstract base with empty-default hooks); duplicate literal and exact source-compiler registrations are loud errors. Extension rules join core's; order carries no meaning because no tie is resolvable. A compiled program carries no dialect, so checking with different rules than you run with is unrepresentable. (Two earlier designs died on the way here: a resolver-held stack with an install-unless-present slot was order-dependent under resolver sharing; its replacement — the dialect riding the per-call `Context` — kept evaluator and checker honest but paid runtime dispatch per node. Compilation subsumes both.)
- **Partial-agreement conformance stays a host policy**, built on the upstreamed registry; upstream later only if a second host independently reinvents it.

### Harness and guarantees

- **The guarantee has two layers.** *Inseparability, by construction*: selection and evaluation arrive and embed as one value, so a program can never evaluate under a rule other than the one that certified it (an identity, no test possible). *Fidelity, by certified obligation*: that a closure is total over its operand types and lands in its return type is an author obligation at a trusted boundary with FFI standing — the library never re-checks certified results (re-checking is the deleted double check, and unsound anyway: a lying closure lies per call). A source compiler callback is an operator rule with zero operands and carries the identical obligation.
- **The harness is evidence, not proof, with honestly bounded quantifiers.** Three tiers: *enumerated* (a finite specimen family covering every shape constructor, author-curated), *generated* (the sweep over every certified family pair, plus admission honesty over the census), *trusted* (everything outside the family). Extension obligation: add specimens for every type your rules mention, re-run the sweep. Follow-up (not in this release): ship the harness as reusable test support so an extension extends the family instead of re-implementing the sweep.

### Companion package and release

- **`Date`/`Period` live in `axiom-time`, the extension-seam canary.** It exercises every seam at once — a `Shaped` type, a literal-registry entry, and the hardest case, an *ordered* domain type contributed from outside core. If it can be built cleanly outside core, hosts can. Core stays neutral on contested policy (date vs datetime, timezones, calendar arithmetic, locale).
- **One breaking release, `0.5.0`, Phases 0–4.** The inference API is the corpus-sweep migration tool for the runtime strictness, so the strict runtime and its checker ship together — one migration guide. A Phases-0–3 release is not representable: after the compilation pivot there is no unchecked evaluator left to ship.

### Reserved for a future authoring surface

- **Enum/annotation syntax is decided but ships nothing now** (UpperCamelCase types, postfix `?`, `|` unions with bare literals, no `enum{}` form) — recorded so a future grammar and the algebra can't drift. See §Future work.
