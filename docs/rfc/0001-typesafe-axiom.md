# RFC 0001: Typesafe Axiom

- **Status**: Accepted
- **Author**: Robert van Steen
- **Date**: 2026-07-14 (revised 2026-07-14 after design review — all open questions resolved; revised again 2026-07-14 after adversarial review — see §Resolved questions, second round)

## Summary

Axiom pivots from a dynamically evaluated expression engine to a **statically typed expression language**: every expression has an inferable `Type`, types stand in decidable relations to one another (assignability, equivalence, overlap, admissibility, orderability), and every operator owns its runtime *and* static semantics in one class, so the two can never drift. Hosts extend the type system the same way they extend evaluation today — by contributing types, literals, and overloaders through the existing plugin seams.

The checker operates on the **runtime AST** (`Source`) — the programs hosts actually run today. It has no dependency on any authoring surface; if one is ever built, it would be a consumer of this layer, not a prerequisite for it (see §Future work).

## Motivation

Two forces converge:

1. **Hosts rebuild static semantics, and it drifts.** Answering static questions about Axiom expressions — "is this condition boolean?", "can this comparison ever hold?", "what does this derived value return?" — currently means building a type layer *outside* Axiom: structural shapes, relations between types, syntax-directed inference, and per-operator typing rules. That last part is the hazard: the static rules are a hand-maintained **mirror** of the runtime overloaders. `Option<Number> + Number` types differently under the bare `DefaultOverloader` (unsupported: no rule pairs an absent operand with a present one) than under a host dialect that deliberately reads an absent operand as zero — so inference is only correct if it is injected with the exact static mirror of the overloader stack the evaluator runs. Nothing enforces that mirror beyond discipline. Co-locating each rule's `evaluate` and `typeOf` in one class makes drift *unrepresentable* rather than merely tested-against.

2. **Every host pays the same tax.** A static layer built downstream is essentially host-agnostic: only the host's domain types and its enforcement policy are truly its own. The traversal, the relations, the typing rules for Axiom's own overloaders — all of it types *Axiom*, and belongs to Axiom. This design has been proven in production by a host application that built the full layer downstream; this RFC upstreams the host-agnostic core of it.

Hosts want to answer, before evaluating anything, questions the engine currently cannot: is `turnover * riskFactor` meaningful for the declared input types? Is this gate condition boolean? Can this comparison ever hold? Today those answers arrive at evaluation time, one unlucky binding at a time.

## Current state

- `Types\Type` is a **runtime contract**: `assert`, `coerce`, `compare`, `format`. It answers "does this value inhabit this type?" — never "how does this type relate to that one?".
- Operators are **value-directed**: `OperatorOverloader::supportsOverloading(mixed $left, mixed $right, string $operator)` dispatches on runtime values; an ordered stack (`OverloaderManager`) composes a dialect.
- The runtime AST (`Source`) already contains one static-typing node, historically named `TypeDefinition` (evaluated via coercion). This RFC splits it into two nodes with distinct semantics: `Coerce` and `Ascription` (§3).

What's missing is the middle: a **static semantics** connecting declared types to expressions.

### Runtime dishonesty this RFC also fixes

The design review surfaced places where the *current runtime* contradicts the semantics the static layer must certify. These are fixed as **Phase 0** (see §Release plan), before any typing rule is written against them:

- `StringType` treats `''` and `'null'` as absence in **`assert`**, not just `coerce` — the empty string is currently not a `String`.
- `DictType` treats the empty array as absence in both `assert` and `coerce` — `{}` is currently not a `Dict`.
- `ComparisonOverloader::supportsOverloading` claims **every value pair** for its operators (it tests only the operator), so `money < money` silently evaluates as PHP structural comparison and shadows any host rule listed after it.
- `UnaryResolver` evaluates `!` as **PHP truthiness on any value** (`!5` is `false`), while the intended static rule is boolean-only.
- `MatchResolver` returns `Ok(None())` when no arm matches — non-exhaustive matches silently produce absence.

## Design

Three layers, each usable without the ones above it (a fourth — a typed authoring surface — is future work, see §Future work):

```
3. Inference & checking  infer(Source, env) / check(Source, expected, env)
2. Typed operators       evaluate() + typeOf() in one class (binary and unary)
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
| `RecordShape(fields, open)` | named fields, open or closed | Width subtyping when open; vocabulary-policing when closed. **No presence flag**: an optional field is a field whose shape is `OptionShape` — missing-key and present-null are one absence concept (see laws). |
| `DictShape(value)` | homogeneous string-keyed map, unknown keys | Distinct from an open record: "all values are `T`" and "these named fields plus anything" are different claims. |
| `ListShape(element, min, max)` | length-bounded list | A plain list is bounds `[0, ∞)`; there is no separate sized-list shape. Bounds participate in subtyping and overlap. |
| `UnknownShape` | statically unnameable | Gradual typing. Admissible at every operand position; certifies nothing under assignability (accepted only by itself). Derived, never authored. |
| `NeverShape` | bottom | The empty value set. Type of impossible joins; `Option<Never>` is the type of the `null` literal; union identity. |
| `OpaqueShape(identity, parameters)` | nominal head, structural parameters | Related only under the same identity; then parameter-wise by the ordinary relations (`Opaque('money', ['currency' => Literal('GBP')])` is assignable to `Opaque('money', ['currency' => 'GBP' \| 'USD'])`). Parameterless opaques are plain nominal identities (claim IDs, catalogue keys). The shape for object-valued domain types. |

**The shape-truth law.** `shape()` is a **truth claim about the runtime structure of the type's values** — every relation trusts it, so it must be load-bearing-true. Project `RecordShape` *only if* the member-access mechanism can reach every projected field on every value of the type and obtain an inhabitant of the field's shape. This is census-enforced, not honor-system (see the shape-soundness census, second law). The consequence for domain types:

- Values that genuinely *are* records (JSON-shaped hosts where money is `['kind' => 'money', 'currency' => 'GBP', 'amount' => 100]`) may project as records — the discriminant-field encoding is legal there, and currency subtyping falls out of literal/union rules.
- **Object-valued** domain types (a `Money` class) must **not** project fictional fields. They project `OpaqueShape` with structural parameters: `Opaque('money', ['currency' => Literal('GBP')])` — nominal head (no structural claims, no field access, no record interop), parameters related by the ordinary rules, so currency subtyping survives without the lie.

*Design history, owned explicitly:* the first revision of this RFC omitted a brand-like constructor on the argument that the discriminant-field encoding gives branded subtyping "for free". The adversarial review round killed that premise: a fictional record projection leaks through assignability — `Money` becomes assignable to `{amount: Number}` slots, whose certified member accesses then crash on the actual object. Once shapes must be true, object-valued types need a nominal-with-parameters constructor, so `OpaqueShape` gained parameters. (Contrast TypeScript's *branded types*, which use exactly this fictional-field trick safely — because TS types are erased and never meet a runtime. Our shapes drive a checker that must agree with a live evaluator; the same trick is a certified-crash factory here.)

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
        either way. The applicability relation for equality and membership. */
    public static function overlaps(Type $a, Type $b): Result;                      // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert

    /** May values of $operand reach a rule's $slot? The operand-admissibility
        relation every typing rule consults: assignable to the slot, OR the
        operand is top-level Unknown. Nothing else. */
    public static function admits(Type $operand, Type $slot): Result;               // Result<bool, TypeMismatch> — Ok is the verdict; the payload is inert
}

final class TypeOrder
{
    /** Are < / > meaningful for this type? An orthogonal, static-only axis. */
    public static function hasDefinedOrder(Type $type): bool;
}
```

There is **no boolean verdict channel**: `TypeMismatch` *is* the negative verdict. Every call site has two branches. `TypeOrder` has **no runtime half** — ordering-at-runtime is owned by overloaders' `evaluate` (a second runtime comparison entry point would be a drift seam), and runtime equality already lives in `Type::compare`. Shapes say *whether* a type is ordered; overloaders say *how* its values rank.

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
  - `Option<T>` always overlaps `Option<U>` (shared member `null`) — which is why `x == null` as the emptiness test types under the comparison rule with no special case.
- Coercion law: `OptionType<T>::coerce(null)` yields a *present* `Some(null)` — absence is a legal value of the option, not a failed coercion. That is what lets an optional field live inside a record whose fields treat a `None` coercion as "required but missing".
- **One absence axis**: missing-key and present-null are indistinguishable after record coercion (coercion canonicalizes, inserting `null` for missing optional keys). No expression can observe the difference — member access yields `null` for both, and `has`/`in` are list membership, not key presence. *Revisit trigger, recorded here deliberately: if a `keys()`-like operation over records ever enters the language, this decision must be revisited before it ships.*

*Overlap & admissibility*
- `overlaps` is symmetric and is **not** derivable from assignability.
- `overlaps(Unknown, T)` always holds — nothing can be ruled out.
- `admits` is **pessimistic**: `Union(Number, String)` is refused at a `Number` slot (the author narrows with `match`; `Unknown` is the *only* sanctioned "can't rule it out" hole). Equality remains governed by the optimistic `overlaps`; operand slots by the pessimistic `admits`. Two relations, two purposes, both centrally owned.

*Order*
- Orderability is not part of the assignability family. `hasDefinedOrder` is true for `Number` only in core — not `String` (PHP's willingness to rank strings is not a defined order; ISO-date-string comparison *should* be a type error, because it's begging for a `Date` type), not `Boolean`. `Literal`/`Union` derive from their bases; `Option` is unordered (`null` doesn't rank). A host that wants lexicographic string ranking ships a one-class overloader in its dialect.

*Value domain (Phase 0 alignment)*
- `assert` defines strict membership: `""` **is** a `String`, `'null'` **is** a `String`, `[]`/`{}` **are** a `List`/`Dict`. The absence readings (`'' → None`, `'null' → None`, `[] → None`) live in — and only in — `coerce`, the lenient input boundary where "empty CSV cell means no value" is a legitimate reading. Hosts keep binding-time leniency; the internal value domain becomes coherent.
- **Equality is value equality, never PHP juggling**: numeric comparison when both operands are numbers (`1 == 1.0` holds — one `Number` base, matching literal-shape identity and `Type::compare`), strict identity for strings/booleans/null, element-wise for lists, and **`false` across bases** (`5 == '5'` is `false`). This is what makes a `dead` verdict honest by construction: no overlap ⇒ genuinely constant-false. `===`/`!==` become aliases of `==`/`!=` (strictness was only distinct while `==` juggled). A host that coerced its stringly inputs — which the typed boundary now does for it — never notices.
- **One representation of null in the resolution channel**: the value `null` travels as `None`; `Some(null)` never escapes a resolver (a bound `null` normalizes to `None` at symbol lookup — shadowing survives, since it lives in `Bindings::has()`, checked before the value is read). The *coercion channel* deliberately keeps its two-value protocol — `Option::coerce(null) → Some(null)` ("present null") vs `None` ("read as missing") — but that distinction is consumed at boundaries (record fields, typed bindings), never propagated. `InfixResolver` already converts both directions at operator positions (`unwrapOr(null)` in, `Option::from` out); this law makes the whole engine as consistent as its operators.

*Coercion is the boundary*
- `assert` defines **membership** (the value domain, what shapes describe); `coerce` defines **admission** (host-facing conversion policy). Coercion is statically opaque — the checker never reasons about it — and has exactly two homes: the typed binding boundary (declared inputs coerced at `Expression` invoke) and the explicit `Coerce` node. Certification is a conditional guarantee ("*if* inputs inhabit their declared types…"); the boundary is what establishes the condition, which is why `coerce` exists on `Type` at all.

### 2. Typed operators — one class, both semantics

The centerpiece. **Hard break** (we are pre-1.0): `typeOf` and `handles` land directly on `OperatorOverloader` — no `TypedOverloader` sub-interface, no legacy adapter that types old overloaders as `Unknown`. A parallel tier would permit an overloader without a typing rule, which is the disease itself with a deprecation timer attached. One interface, one set of obligations; hosts update their overloaders in the same PR that bumps the dependency.

```php
interface OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result;

    /** Which operators this rule types (the static face of supportsOverloading). */
    public function handles(string $operator): bool;

    /**
     * The return type for operands of these types.
     *
     * CONTRACT (certification): Ok(T) means EVERY value of these operand
     * types is handled by this rule and the result inhabits T — the checker
     * may certify the expression. Err(TypeMismatch) means this rule does not
     * certify these operand types: some values would fall outside it, or the
     * operation is statically meaningless though runtime-tolerated (a "dead"
     * mismatch — a comparison or membership test that can never hold).
     *
     * Absence is THIS rule's concern: a rule whose runtime rejects null
     * refuses Option operands (which falls out of admits(): Option<T> is not
     * assignable to a present slot); a rule that substitutes zero admits
     * them; a rule whose result can be absent says so in its return type.
     * The only sanctioned unsoundness is Unknown: gradual admission
     * deliberately certifies what it cannot check.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result;
}
```

`TypeMismatch` carries a `dead` flag distinguishing the two refusals: *unsupported* ("no rule accepts Date and Number" — the runtime would err) versus *dead* ("`Number == String` can never hold" — the runtime evaluates it, constantly, and that constancy is the probable author bug). Consumers render them differently; the agreement harness treats them differently (below).

#### Honesty contract on `supportsOverloading`

`supportsOverloading` must claim **only values the rule owns** — operator-only dispatch is dishonest and shadows every rule listed after it. Phase 0 audits core:

- `ComparisonOverloader` claims equality for scalar/null/array pairs only (no objects — `money == money` is the money package's business), and ordering (`<`, `<=`, `>`, `>=`) for numeric pairs only.
- `LogicalOverloader` claims boolean pairs only.
- After the audit, "ordering is semantics" degrades to "ordering is tie-breaking between rules that genuinely both claim a value" — the only ordering the static composition rule models.

#### Core typing rules

| Overloader | Static rule (`typeOf`) |
| --- | --- |
| `LogicalOverloader` | two present booleans → `Boolean`; refuses `Option` (falls out of `admits`) |
| `ComparisonOverloader` | runtime equality is **value equality** (numeric within `Number`, strict otherwise, `false` across bases — never PHP juggling; `===`/`!==` are aliases); statically, operand types must `overlaps()` — a comparison that cannot hold is `dead`, and value equality makes `dead` mean constant-`false` *by construction*; ordering additionally requires `hasDefinedOrder` on present types; tolerates `Option` (equality against `null` is the emptiness test) → `Boolean` |
| `BinaryOverloader` | two present numbers → `Number`; refuses `Option` |
| `NullOverloader` | the degenerate `null ∘ null → null` rule; contributes no static admissibility beyond what `Option` operand positions of other rules claim |
| `HasOverloader` / `InOverloader` | list side must be list-shaped and present; element types must `overlaps()` → `Boolean` |
| `IntersectsOverloader` | either side list or scalar, absence tolerated (the runtime wraps and filters) → `Boolean` |

The same contract reaches every dialect: `axiom-money`'s `MonetaryOverloader` types money arithmetic (currency agreement via `overlaps` — `Money<GBP> + Money<USD>` is dead, the runtime throws every time); a host's null-tolerant overloader types its absence-as-zero arms; `axiom-time` ships `Date − Period → Date` beside its runtime rule. **A rule's static and runtime semantics are in one class, in one package, in one diff.**

#### Unary operators are overloader-driven too

`UnaryResolver`'s hardcoded rules were a hand-maintained mirror readmitted through the side door — and already drifted (`!` evaluates PHP truthiness on any value while the intended rule is boolean-only, and nothing could catch it because the agreement harness iterates overloaders). A sibling contract, same philosophy:

```php
interface UnaryOverloader
{
    public function supportsOverloading(mixed $operand, string $operator): bool;

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $operand, string $operator): Result;

    public function handles(string $operator): bool;

    /** @return Result<Type, TypeMismatch> */
    public function typeOf(string $operator, Type $operand): Result;
}
```

Composed by the same manager pattern, consumed by `UnaryResolver` the way `InfixResolver` consumes the binary stack, covered by the same harness. Core ships `NotOverloader` (**booleans only** — `!5` becomes an error) and `NegateOverloader` (numbers). `axiom-money` can then ship `-money` as one class.

Deliberate asymmetry, kept and documented: **absence stays resolver-level for unary.** The resolver short-circuits `None` before any rule runs, so unary rules only see present values and optionality propagates structurally (`!Option<Boolean>` is `Option<Boolean>`) as one inference rule. A host cannot write an absence-handling unary rule — a feature, not a gap. Binary keeps value-level absence handling (`NullOverloader` and friends) because binary genuinely needs per-rule absence policy (absence-as-zero dialects).

#### Composition: the dialect is one list

`OverloaderManager` implements the full contract. Its `typeOf` resolves across the stack:

1. Collect the verdicts of every member that `handles()` the operator.
2. Any `Ok`s whose return types all agree (`areEquivalent`) → that type.
3. `Ok`s that disagree → `Unknown`. Several rules certify these operand types with different result types; which rule evaluates a given pair depends on values and list order that inference cannot see. The honest answer is `Unknown` — never the accident of whichever rule was listed first.
4. No `Ok`s → one mismatch (a lone handler's, directly) or an aggregate ("no overload of `-` accepts `Date` and `Number`", causes attached).

Because the evaluator and the inference consume **the same composed list**, dialect drift by miscomposition — a runtime stack and a hand-maintained parallel registry of static rules — becomes structurally impossible.

### 3. Inference and checking

```php
final readonly class TypeInference
{
    public function __construct(
        private OperatorOverloader $operators,     // the evaluator's own stack
        private UnaryOverloader $unaryOperators,   // likewise
        private LiteralTypeRegistry $literals,
    ) {}

    /** @return Result<Type, TypeMismatch> — causes carry multiplicity */
    public function infer(Source $source, TypeEnvironment $environment): Result;

    /** @return Result<Type, TypeMismatch> — Ok carries the inferred type */
    public function check(Source $source, Type $expected, TypeEnvironment $environment): Result;
}
```

#### The environment walks the symbol graph

In the engine, a symbol is satisfied by a per-call binding (`Bindings`, raw values) or by resolving the **`Source`** it names in `Definitions` — defined symbols are derived expressions. A flat pre-seeded type map would force someone to hand-declare the type of every derived symbol: the mirror again. Instead `TypeEnvironment` mirrors `SymbolResolver` structurally — declarations give the types of bindings and take precedence, exactly as bindings shadow definitions at runtime:

- A defined symbol's type = `infer()` of the `Source` it names in the **same `Definitions` the evaluator will use** — one registry, both semantics, same principle as the overloader stack.
- **Declared types terminate recursion**: a host-declared input type (`turnover: Money<GBP>`) enters as a declared leaf; a host `TypedSource` terminates via `returnType()`; `StaticSource` terminates via the literal registry.
- **Memoized**, same key scheme as the resolver (`namespace.name`).
- **Cycle detection**: the checker holds an in-progress set and reports "cyclic symbol definition: a → b → a" as a compile diagnostic — a bug class the runtime currently cannot even survive (its memo is written only after resolution completes), caught statically for free.
- Unbound is an error; a scope that tolerates unknown symbols binds `UnknownType` explicitly.

#### Syntax-directed rules, one per node

- `SymbolSource` → environment lookup as above.
- `StaticSource` → **literal-first inference**: scalars infer as `LiteralType` always (values are immutable; there is no mutation-driven reason to widen). `Literal('shop')` is assignable to `String` wherever needed, and equality/membership get sharper: `x == "warehouse"` where `x: 'shop' | 'office'` is a compile error under `overlaps` — dead-comparison detection for free. Domain literals via `LiteralTypeRegistry`: value-class → type, plugin-extensible (core registers scalars and lists; `axiom-money` registers `Money`; a host registers its own).
- List literals → **union element unification with exact bounds**: `[money<GBP>, money<USD>]` is `List<Money<GBP> | Money<USD>, 2, 2>`; `['shop', 'office']` is `List<'shop' | 'office', 2, 2>` (which is what makes `x in ['shop', 'office']` typecheck precisely against an enum-typed `x`); `[]` is `List<Never, 0, 0>`. Same join as `match`, same position, same precision — never equivalence-else-`Unknown`.
- `Coerce(type, source)` → the declared type, **verbatim, unchecked**. The boundary node: runtime converts via `coerce()`; the boundary is statically opaque *by design* (coercion is admission policy, not membership — see the coercion-is-the-boundary law). The old `TypeDefinition` name is retired: it always behaved as coercion, and "definition" read as annotation — exactly the confusion that produced a review finding.
- `Ascription(type, source)` → the declared type, **checked**: the inner type must be `Unknown` or `overlaps` the declaration; disjoint is an error (a false claim, assert-world, where overlap *is* the correct relation — TypeScript's `as` draws the same line). Runtime verifies via `assert()` and fails loudly, so a lying ascription is a tripwire, not a rot vector. The author's annotation: refine an `Unknown` host source, narrow a union.
- `UnaryExpression` / `InfixExpression` → operand types through the composed overloader stacks; unary applies the structural `Option`-propagation rule first.
- `MatchExpression` → see below.
- `MemberAccessSource` → see below.
- **Host sources** → the extension seam:

```php
interface TypedSource extends Source
{
    /** @return Result<Type, TypeMismatch> — causes carry multiplicity */
    public function returnType(TypeEnvironment $environment, TypeInference $inference): Result;
}
```

A host source that knows its type declares it (a geocoding source → its coordinates record); one that cannot returns `Unknown` honestly (a raw lookup cell); one that wraps another source delegates. An unhandled node is an inference *error*, not a silent `Unknown` — "any expression edge starts here" stays a kept promise.

#### `match`: union join, mandatory exhaustiveness

- The type of a match is the **union of its arm types**, normalized. Arms that agree collapse to the single type; literal arms preserve enum precision (`match kind { 'a' => "low", 'b' => "high" }` is `'low' | 'high'`); cross-base disagreement surfaces at the *use site* through pessimistic `admits`, with a mismatch naming both branches.
- **Non-exhaustive fall-through is a runtime error** (`MatchResolver` changes from `Ok(None())` to `Err` — Phase 0), and correspondingly a **compile error** when exhaustiveness is not provable: "add a wildcard arm". Provably exhaustive means: some arm is a wildcard, or the scrutinee's type is `Boolean` or a union of literals and the literal patterns cover every member. `ExpressionPattern` arms match at runtime but never count toward coverage. An `Option` scrutinee additionally requires a literal `null` pattern (covering the `{null}` member) or a wildcard.
- Net discipline: a wildcard arm is mandatory *except* over enum/boolean scrutinees the author has fully covered — and adding a variant to an enum input turns every non-wildcard match over it into a located compile error. Exhaustiveness checking falls out of the type; it is not a separate feature.
- Zero-arm matches (if the grammar ever admits them) type as `Never`.

#### Member access

Member access is **shape-driven** — it dispatches on the operand's projection, not its concrete `Type` class, so any type whose (census-verified, therefore true) projection is record-like gets field access, extension types included. Field shapes **reify** back to types (`Shape → Type` is mechanical over the sealed constructors; an opaque field reifies to `OpaqueType` — statically nominal, dynamically unverifiable by core, `Unknown`'s runtime posture with a nominal shape).

- Record-shaped operand, declared field → the field's shape, reified. Record coercion canonicalizes, so declared-field access never hits the missing-key error.
- `Option<record-shaped>.field` → `Option<FieldType>` — optionality propagates, mirroring the resolver; chained optional access stays clean because `Option<Option<T>>` collapses by theorem.
- `Unknown.field` → `Unknown` (gradual admission).
- Undeclared field on a **closed** record → compile error. On an **open** record → also compile error: "open" is a claim about assignability width, not a certificate that any particular extra field exists — and the runtime errs when it doesn't. Certifying on "might have it" is the optimistic hole `admits` already closes for unions.
- **Dict-shaped operand → compile error** (strict): a dict's nature is "keys unknown statically", so every access is fallible, and the runtime's missing-key `Err` stays exactly as is. `Dict` is a transport type you *type your way out of*. Escape valve reserved for later: an explicit `get(dict, key)` function typed `Option<V>`.
- **Opaque-shaped operand → compile error**: nominal types make no structural claims, so there is nothing to certify accessing.

Shape-driven access is only sound *because of* the shape-truth law: an earlier design dispatched on concrete classes precisely to avoid trusting projections, which broke extension types; trusting projections without the truth law would have certified crashes on fictional fields. Truth first, then trust.

#### `check` is `infer` + assignability (bidirectionality reserved for lambdas)

Literal-first inference and value-set `Option` semantics dissolve the bidirectional special cases into assignability theorems: `null` fills `Option` slots because `Option<Never> ⊆ Option<T>`; `"shop"` fills the enum because `Literal ⊆ Union`; `[]` fills any admitting list because `List<Never, 0, 0> ⊆ List<T, min≤0, _>`. **v1 `check(expr, expected, env)` is literally `infer` then `isTypeAssignableTo`** — no special cases to disagree with the relations later. The bidirectional *API* stays, because future lambda inference genuinely needs expected-type propagation to infer parameter types.

### How hosts consume the checker

There is no compiler in the loop — hosts hold runtime-AST programs and call the API directly, through one object that owns everything type-relevant:

#### The `Dialect` and its extensions

The operator rules live in exactly one place. A `Dialect` is a value object composing the binary manager, the unary manager, and the literal registry; both the evaluator and the checker consume the *same instance*, so checking with different rules than you run with stops being representable in the API a normal host touches. Packages contribute through an `Extension` (an abstract class with empty-default hooks — `operators()`, `unaryOperators()`, `literals()` — abstract so hooks like `matchers()` can be added later without breaking implementors):

```php
$dialect = Dialect::core()->with(new MoneyExtension(), new TimeExtension());
```

Extension rules **prepend** core's (specialization wins ties — rare and deliberate under the honesty contract); duplicate literal registrations are **loud errors** (a configuration bug, not a precedence question).

#### Typed bindings: the boundary

Certification is conditional — "*if* inputs inhabit their declared types, this expression is sound" — and the boundary establishes the condition. The same declarations map serves both faces: statically it seeds the `TypeEnvironment`; at invoke time each declared binding passes through its declared type (`coerce` by default; `assert` for strict hosts) **before** evaluation begins:

- Declared input, bad value → boundary error, pre-evaluation, aggregated across all bad inputs, named by binding (`binding [customer]: field [turnover]…`) — errors speak the host's language, not the AST's.
- Declared required input, missing → boundary error (all missing inputs reported at once). Declared `Option`, missing or null → legal absence.
- Undeclared extra keys → ignored (superset contexts stay legal). Undeclared *parameters* are the explicit gradual path: at check time they're unbound-symbol errors unless declared `Unknown`.
- **Shadowing a definition requires a declaration**: a binding key colliding with a definition name is a boundary error unless the symbol is declared — the declaration is the typed license to shadow, the boundary enforces it, and the declared∧defined agreement check (below) guarantees either source of the value satisfies the type the checker assumed. Upstream's shadowing feature survives as a typed, declared parameter instead of an implicit override.

The guarantee, stated honestly: *declared inputs cannot deliver garbage past the boundary; undeclared inputs cannot touch anything certified — they are inert, an explicit `Unknown`, or a named error. The only trust remaining is the trust written down.*

#### Bindings hold values whole (descent, not flattening)

`Bindings` stores what it is given — the associative-array-means-namespace heuristic is dead. Lookup does the work: an explicit dotted key wins; otherwise a namespaced lookup **descends** one level into an array binding. So `['customer' => ['turnover' => 600000]]` binds `customer` whole (a record value — coercible at the boundary as a record, member-accessible) *and* still answers `SymbolSource('turnover', 'customer')` via descent. `TypeEnvironment` mirrors the descent: a namespaced symbol whose namespace is declared record-typed resolves to that record's field type. **A namespace is the record view of a binding** — one rule, both semantics; the two concepts stop being separate.

(Rejected on the way here: typed value objects at the call site — `TypedValue::of($type, $value)` bindings. They put types on the wrong side of time — certification needs types *before* values exist — reopen the two-sources-of-truth drift the declarations map closed, and presuppose hosts convert values before the boundary whose job is converting. The co-location instinct is honored where it's sound: declaration-with-enforcement on the `Expression`, not type-with-value at the call site.)

#### The `Expression` types itself

`Expression` owns the dialect, the definitions, and the declarations — so it can answer static questions directly: `$expression->infer()` ("what does this return?"), `$expression->check($expected)` ("is this gate boolean?"). For the common case a host never hand-assembles `TypeInference`/`TypeEnvironment`, and the declared∧defined **agreement check** rides along: a symbol that is both declared and defined must have its definition's inferred type assignable to the declaration — otherwise the no-binding call path would deliver values the checker never blessed.

- **Corpus sweeps**: to migrate onto the strict runtime, a host runs `check`/`infer` across its stored programs and triages the mismatches — non-exhaustive matches, non-boolean negations, dead comparisons all surface before any evaluation happens. The sweep tool for the runtime strictness is this API, not an authoring-surface feature.
- False `Ascription` claims (declared type disjoint from the inferred inner type) surface in the same sweep. `Coerce` nodes do not — coercion satisfiability is deliberately not modeled statically (a lint-grade `CoercionAware` opt-in may arrive later; its absence costs a diagnostic, never soundness).

## What stays in hosts

- **Domain types** — anything whose meaning is the host's (addresses, claims, catalogue keys) implements `Shaped` (projecting into the sealed algebra — `Opaque` where structure shouldn't leak) and, where a literal class exists, registers with the literal registry.
- **Dialect composition** — which overloaders an evaluator runs was always the host's choice; it remains exactly that, now with static semantics attached for free.
- **Policy relations** — derived relations that encode a host's configuration policy (e.g. "does a partial supply agree with an interface on every member it does supply?", built as assignability against a masked interface) **stay downstream**, built on the upstreamed registry. Resolved: this is host policy, not a language relation; upstream it later only if a second host independently reinvents it.
- **Enforcement** — gates, sweeps, feature flags, when a finding blocks anything: entirely host concerns. The language reports; the host decides.

## Companion package: `axiom-time`

`Date`/`Period` live in a companion package, not core — and deliberately so: **`axiom-time` is the canary for the extension seams.** It exercises every one of them at once — a `Shaped` type, a literal-registry entry for its value class, and the hardest case: an *ordered* domain type contributed from outside core. If `axiom-time` can be built cleanly outside core, hosts can; if it can't, the missing seam is found before a host finds it. Core additionally stays neutral on genuinely contested policy (date vs datetime, timezones, calendar arithmetic, locale).

How it lands: `Date` projects as the package chooses (`Opaque`, or a branded record if fields should be accessible). `hasDefinedOrder(Date)` is false in core — correct; the package ships a comparison overloader whose `typeOf` accepts `(Date, Date)` for ordering → `Boolean`, and the manager takes the lone `Ok` while core's rule refuses. `Date − Period → Date` and `Date − Date → Period` are two `typeOf` arms on the package's arithmetic overloader.

## Release plan

**One breaking release** (`0.5.0`, we are pre-1.0) containing Phases 0–3. The runtime strictness and the checker ship together deliberately: the inference API is **the migration tool** for the runtime strictness — a released version with the strict runtime but no way to sweep a program corpus would leave hosts discovering breakage one unlucky evaluation at a time, the exact failure mode this RFC's Motivation opens with. The awkward in-between state never exists in a released version.

Internally, the release is staged as a PR series, in this order:

- **Phase 0 — runtime honesty** (behavioural fixes the static layer will certify):
  1. Value-domain cleanup: `assert` becomes strict membership (`""`/`'null'` are `String`s, `[]` is a `Dict`); absence readings stay in `coerce` only.
  2. `supportsOverloading` honesty audit: `ComparisonOverloader` claims scalar/null/array equality and numeric ordering only; `LogicalOverloader` booleans only.
  3. Boolean-only `!` (kills PHP truthiness).
  4. `match` fall-through: `Ok(None())` → `Err`.
- **Phase 1 — vocabulary and relations**: the sealed shape algebra + `Shaped` + `TypeRelations`/`TypeOrder`/`TypeMismatch`/`TypeDescriber`, with a full-coverage suite and a shape-soundness census with two laws: **(C1)** every projected or `Shaped` type must have specimens (the census fails when one doesn't), and **(C2, shape truth)** for every record-projected type, over its specimens, every projected field must be reachable by the member-access mechanism and inhabit the field's shape — the generative enforcement of the shape-truth law.
- **Phase 2 — typed operators**: `typeOf`/`handles` on `OperatorOverloader`; `UnaryOverloader` + manager + `NotOverloader`/`NegateOverloader`; core overloaders gain their rules; `OverloaderManager` composes and resolves as above; the agreement harness lands.
- **Phase 3 — inference**: `TypeInference`, graph-walking `TypeEnvironment` with cycle detection, `LiteralTypeRegistry`, `TypedSource`.

`axiom-money` follows the release; hand-maintained mirror registries downstream are deleted — the composed evaluator stack *is* the registry now. Host sources adopt `TypedSource` (most can honestly declare `Unknown` at first — exactly what hosts bind for them today).

**Host migration path**: bump the dependency in a branch → sweep the stored program corpus with `check`/`infer` → triage the mismatches (behaviour changes vs latent bugs) → fix or consciously accept each → deploy runtime and checker together. One migration guide for one release.

**Sequencing gate**: the downstream layer this design is lifted from is still hardening against its first consumers, and its operand-judgement surface changed twice in one review cycle. The release lands once that surface has survived at least one consumer unchanged — freezing an API into a shared package a week early is how the API gets designed twice.

**Follow-ups after `0.5.0`**:
- `axiom-time` (any time after; the seam canary).
- The narrowing bundle on the runtime AST: `is`-type patterns in `match` (which is what makes cross-base unions like `Number | String` usable at operand positions) and literal-pattern scrutinee narrowing inside arm bodies.
- Explicit `get(dict, key): Option<V>` if hosts demonstrate the need.

## Future work: a typed authoring surface

If a textual authoring surface is ever built over this engine, it builds on layers 1–3 and must ship *with* its checker wired in — there must never be a released authoring surface whose typed syntax is uncheckable. Decisions recorded now so the algebra and a future grammar can't drift:

- **Wiring**: declarations seed the `TypeEnvironment`; expression bodies infer through the plugin-composed overloader stacks; assertion constructs go through `check(expr, Boolean, env)`; coercion syntax is the textual face of `Coerce`, ascription syntax of `Ascription`; compilation results carry located diagnostics (`TypeMismatch` + a source location), each tagged behaviour-change vs latent-bug for corpus triage.
- **Annotation grammar**: one spelling per constructor, no synonyms. All type identifiers UpperCamelCase (`Boolean`, `Number`, `String`, `List<T>`, `Dict<T>`, `Money<GBP>`), giving the lexical law *uppercase initial = type, lowercase initial = symbol*. Postfix `?` for options (`T??` canonicalizes). Infix `|` for unions with bare literals as types (`'shop' | 'office'`) — no `enum{}` form. Records named via schema declarations only. No bounds syntax in v1. `Unknown`/`Never` unspellable — derived, never authored.
- **Functions and lambdas**: signatures on a function registry first, lambda parameter inference from expected types later, via the reserved bidirectional `check`.

## Drift guarantees

1. **Co-location** — `evaluate` and `typeOf` in one class: a semantics change and its typing rule are one diff, one review. This now covers unary operators too; there are no operator rules outside the contract.
2. **One composed list** — evaluator and inference consume the same managers; miscomposition is unrepresentable. The environment extends the same principle to symbols: one `Definitions`, both semantics.
3. **Agreement harness, bidirectional and cross-package** — a generative suite in Axiom checking two laws for every overloader against a specimen matrix of typed values:
   - **L1, soundness**: if `typeOf` says `Ok(T)`, then every specimen value pair of those types is claimed by `supportsOverloading`, `evaluate` succeeds on it, and the result inhabits `T`. (Skipped where an operand type is `Unknown` — gradual admission is deliberately unsound — and vacuous when `T` is `Unknown`.)
   - **L2, anti-shadowing**: if every specimen value pair of a type pair is claimed by the rule, then `typeOf` must certify it — a rule that runtime-owns values it statically refuses is hiding semantics from the checker. Exempt: refusals marked `dead` (the runtime tolerates dead tests; the checker still flags them) and the degenerate `NullOverloader` (documented in the class).
   - **L3, the dead law**: a refusal marked `dead` is a *claim* ("this can never hold"), and claims get verified — for every specimen value pair of the refused types, the rule must either not claim the pair or evaluate it to `false`. Never `true`. This law exists because its absence let a real bug ship: dead refusals were exempt from L2 on the unverified assumption that dead ⇒ constantly-false, and PHP's loose equality (`5 == '5'` → `true`) broke the assumption silently. Value equality restored the invariant; L3 pins it generatively, for core and every extension.

   Packages and hosts run the same harness over their own overloaders by contributing specimens — and their specimens are thrown at *core's* overloaders too (L2, run against the pre-RFC code, would have caught `ComparisonOverloader` claiming `Money < Money` immediately).
4. **Ambiguity is honest** — disagreement between certifying rules yields `Unknown`, so a new overload (date-period subtraction beside numeric subtraction) can never silently change an existing inference from one concrete type to another.
5. **Honest runtime** — `supportsOverloading` claims only values the rule owns, so first-match shadowing cannot hide semantics from the static layer.

## Alternatives considered

- **Status quo: host-side mirror + conformance tests.** Keep static semantics downstream, pin them to the runtime with agreement tests. Works, and is the right staging — but the mirror is a standing invitation to drift, the tests catch divergence only after the fact, and every host pays again. Rejected as the end state.
- **Static semantics as a sibling package (`axiom-types`).** Avoids growing the core, but the typing nodes (`Coerce`, `Ascription`) already live in the runtime AST, and splitting `typeOf` from `evaluate` across packages reintroduces exactly the seam this RFC exists to close. Rejected.
- **Signatures as a parallel interface (`TypedOverloader`) rather than extending `OperatorOverloader`.** Permits an overloader without a typing rule — the gap becomes opt-in again. Originally this RFC proposed the parallel interface with a migration window; resolved during review to a **hard break** at 0.x: `typeOf` on the one interface, no adapter, no `Unknown`-typing legacy tier.
- **Open shape algebra (relation rules on the shapes, double dispatch).** Would let hosts add shape constructors — and with them, mutually inconsistent relation rules ("who wins when `A->assignableFrom(B)` and `B->assignableTo(A)` disagree?"). The laws stay checkable only if the case analysis is exhaustive. Rejected in favour of the sealed algebra with projection-only extensibility.
- **`Option`-wrapped match results (silent fall-through).** Typing non-exhaustive matches as `Option<join>` was honest but preserved a silent-absence runtime. Resolved instead to fall-through-as-error + mandatory provable exhaustiveness: authors add deliberate default arms.
- **Lenient dict access (`Dict<V>.field : Option<V>`, missing key → `None`).** Usable, but weakens the runtime — a typo'd key that errors loudly today becomes silent absence — cutting against every other strictness decision here. Rejected in favour of strict (compile error; schemas are the way out).

## Resolved questions

Formerly "Open questions"; all resolved in the 2026-07-14 design review:

1. **`Date`/`Period`** → companion package `axiom-time`, doubling as the extension-seam canary. (§Companion package)
2. **`match` join rule** → union of arm types; non-exhaustive fall-through is a runtime error and unprovable exhaustiveness a compile error. (§3)
3. **Partial-agreement conformance** → host policy, stays downstream on the upstreamed registry. (§What stays in hosts)
4. **Unary overloadability** → yes, now: `UnaryOverloader` in the same release; boolean-only `!`; resolver-level absence propagation. (§2)
5. **Migration window mechanics** → hard break at 0.x; `typeOf` on `OperatorOverloader`; no adapter, no legacy tier. (§2)
6. **Enum/annotation syntax** → decided and recorded for any future authoring surface (UpperCamelCase types, postfix `?`, `|` unions with bare literals, no `enum{}`); nothing ships now. (§Future work)
7. **Release cadence** → one breaking release `0.5.0` = Phases 0–3, with the inference API as the corpus-sweep migration tool; one migration guide. An authoring surface, if ever shipped, must ship with its checker wired in. (§Release plan, §Future work)

Second round, resolved after the adversarial review of the first implementation:

8. **Equality semantics** → value equality, never PHP juggling: numeric within `Number`, strict otherwise, `false` across bases; `===`/`!==` are aliases. Makes `dead` mean constant-false by construction. (Laws, §Value domain)
9. **Dead-coercion detection** → the overlap check was the right rule on the wrong node. Resolved by splitting `TypeDefinition` into `Coerce` (runtime `coerce`, statically verbatim — the opaque boundary) and `Ascription` (runtime `assert`, statically checked: `Unknown`-or-overlaps). A per-type static coercion relation (`acceptsInput`) was rejected for the core contract — its absence costs a lint, not soundness; may return later as opt-in `CoercionAware`. (§3)
10. **Whether `coerce` survives** → yes, demoted from language semantics to *the boundary contract*: membership vs admission; two homes (typed bindings, `Coerce`); statically opaque by design. (Laws, §Coercion is the boundary)
11. **Null representation** → one representation in the resolution channel (`None` ≙ `null`; a bound null normalizes at lookup); the coercion channel keeps `Some(null)` vs `None` as a boundary-consumed protocol. Contra the reviewer's recommendation, which would have made null "present" everywhere and broken None-propagation engine-wide. (Laws, §Value domain)
12. **Member access for extension types** → shape-driven with reification — but only after the **shape-truth law** made that sound: projections are census-verified truth claims (C2). The interim `HasFields` counter-proposal died in review: it patched direct access while leaving the assignability leak open. (§1, §3)
13. **Brand constructor, revisited** → `OpaqueShape` gains structural parameters (nominal head, parameter-wise relations). The original omission's premise — "the discriminant-field record encoding is free" — died with shape truth: fictional projections are outlawed for object-valued types. Record-encoding remains legal where values genuinely are records. (§1)
14. **Dialect ownership** → one `Dialect` value object consumed by evaluator and checker alike; packages contribute via `Extension` (abstract base, prepend semantics, loud literal collisions); `Expression::infer()/check()` so the common case cannot miscompose. (§How hosts consume)
15. **Typed bindings** → declarations gain a runtime face: the boundary coerces/asserts declared inputs pre-evaluation, aggregated and named; shadowing a definition requires a declaration; declared∧defined symbols get an agreement check. Typed value objects at the call site were rejected (types on the wrong side of time). (§How hosts consume)
16. **Bindings shape** → descent, not flattening: arrays bind whole; namespaced lookup descends; a namespace is the record view of a binding, statically and dynamically. (§How hosts consume)
17. **Harness completeness** → L3, the dead law: `dead` refusals are verified constant-false-or-refused over specimens. (§Drift guarantees)
