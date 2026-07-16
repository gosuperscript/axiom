# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- RFC 0001: Typesafe Axiom (`docs/rfc/0001-typesafe-axiom.md`) — the design record for the statically typed, compiled expression language, shipping as one breaking release; `docs/extending-axiom.md` is the extension guide.
- **Compilation**: `Expression::compile(): Result<Program, TypeMismatch>` type-checks a program once — cycles, unbound symbols, unresolvable or ambiguous operators, dead comparisons, non-exhaustive matches, false ascriptions all refuse with named diagnostics — and returns a certified, callable `Program` (`$program->returns` is the inferred type; `$program($bindings)` runs the boundary and then evaluates). A compiled program embeds its operator resolutions and performs **no runtime type dispatch**; definitions compile once and evaluate lazily, memoized per invocation (`Runtime`). `Expression::infer()`/`check()` are conveniences over `compile()`.
- The sealed shape algebra (`Types\Shapes`): `Boolean`/`Number`/`String`, `Literal`, `Option` (value-set semantics, nesting collapses), `Union` (canonicalized), `Record` (exact named fields), `Dict`, `List` (validated length bounds), `Unknown`, `Never`, `Opaque` (nominal head with structural parameters). Shapes are **census-verified truth claims** about runtime structure: fictional record projections for object-valued types (the TypeScript branded-types trick) are outlawed — object domain types project `Opaque`.
- Type relations (`TypeRelations`): assignability (⊆ over value sets), equivalence, symmetric overlap (complete: a `dead` verdict is a constant-`false` claim, so one shared value — `[] == []` across list/dict/record — keeps a comparison live), pessimistic operand admissibility, and joint admissibility (the row-ambiguity relation: could some *operand type* be admitted by both slots? — distinct from overlap exactly where a shared value is not a shared type), with `TypeMismatch` cause chains rendered through `TypeDescriber`. There is deliberately no orderability oracle: whether `<` is meaningful for a type is answered by the dialect's ordering rows and nothing else.
- New language-level types: `OptionType` (null coerces to a present `Some(null)`), `LiteralType`, `UnionType`, `RecordType` (coercion canonicalizes missing optional keys to null), `UnknownType`, `NeverType`; `ListType` gains length bounds; every core type projects via `Type extends Shaped`, and hosts declare object-valued inputs with their own `Shaped` domain types (a real membership check + an `OpaqueShape` projection).
- Typed operators, explicit verdicts: `BinaryOperatorRule`/`UnaryOperatorRule` each own one symbol and return a `ResolvedOperation`, `UnsupportedOperation`, or `DeadOperation` directly from `resolve(operand types)`. A success carries the return type *and* evaluation, so rule selection and evaluation are one statement and cannot drift apart (that the evaluation honors its stated type is the author's certified obligation, tested by the totality harness). Compiler-facing resolvers index rules by symbol and aggregate refusals without registration precedence. Core arithmetic, ordering, and logic are dialect rows; `Equality`, membership (`has`/`in`), and intersection are hand-written type functions that establish `ValueEquality` support before using overlap for liveness.
- The signature builder — the front door for extension operators: `Operator::infix('-')->signature(new DateType(), new PeriodType())->returns(new DateType())->evaluate(fn (Date $d, Period $p) => $d->minus($p))` declares operand ownership once; resolution checks admissibility against the declared types and returns the declared return type with the declared closure, which may take its parameters natively (the compiler proved them). Closures auto-wrap plain values in `Ok`, pass a returned `Result` through (value-dependent partiality — division by zero), and let throws propagate; `Operator::prefix` rejects `Option` operand types loudly. The raw one-method contract remains the documented escape hatch for verdicts computed from operand types.
- `Dialect` + `Extension`: operator rules, the literal registry, and exact-class host source compilers live in one value object consumed at compile time; the program embeds what the dialect resolved, so checking with different rules than you run is unrepresentable. **Ambiguity is refused, never ranked**: two rows for one operator with jointly admissible slots (some operand type would resolve both — a `List` row beside a `Dict` row is a legal pair; a `Literal(5)` row beside a `Number` row is not) are a `Dialect` construction error; multiple rules resolving the same node are a compile error naming both. List order decides nothing. Duplicate literal and source compiler registrations stay loud errors.
- The compiler (`TypeInference`): literal-first inference, union element unification with exact list bounds, mandatory match exhaustiveness (`Never` vacuously covered; expression patterns compile like any program but never count toward coverage), shape-driven member access with reification (`TypeReifier`), optionality propagation, and `check` = infer + assignability. `TypeEnvironment` compiles symbols from declarations (boundary reads) and definitions (memoized slots); `LiteralTypeRegistry` types object literals. Host `Source` objects remain serializable data descriptions; `Extension::sourceCompilers()` maps their exact classes to callbacks that receive the source and a narrow `SourceCompilation` capability, then return the type claim and evaluation together. `SourceCompilation` recursively compiles owned child sources and binds typed infix operations from the composed dialect, letting a source compiler reuse exactly the language's operator semantics without restoring runtime dispatch. Live collaborators stay in the extension and are captured only in the compiled program.
- Typed bindings: `Expression` accepts `declarations` and a `Boundary` mode (`Coerce`/`Assert`); declared inputs are validated/converted per call with aggregated, named `BoundaryViolation`s, and every undeclared binding key is stripped — the declaration list is the program's complete public signature. The boundary is the one runtime type check that survives compilation, by design.
- Static cycle detection: `DefinitionGraph` walks the definition reference graph before typing — self- and mutual definition cycles are compile diagnostics, and since invocation lives only on `Program`, a cyclic program is unrunnable, not merely diagnosed.
- The totality harness and the admission-honesty law: for every rule of the composed dialect, every certified pair of a curated **specimen family** (types covering every shape constructor, each with edge values) must evaluate without escaping, into the resolved return type; for every `Type`, `coerce` output must pass `assert` (census-enforced) — together the runtime trust chain of compile-then-trust. The harness is evidence, not proof: extensions add specimens for every type their rules mention and re-run the sweep. The shape-soundness census keeps its specimen (C1) and shape-truth (C2) laws.
- Initial open source release
- MIT License
- Contributing guidelines
- Security policy
- Comprehensive documentation

### Changed (Breaking)
- **Evaluation presupposes a passed check.** `Expression` is a description, not a callable: `call()`/`__invoke` live only on the `Program` that `compile()` returns, so running an unchecked program is unrepresentable. The value-directed runtime dispatch that double-checked every operator (`supportsOverloading` walking an ordered stack per evaluation) is deleted with its cause — overload resolution happens once, at compile time, on types. **Migration**: `$expression($bindings)` becomes `$expression->compile()->unwrap()($bindings)`; compile once at authoring/deploy time and invoke per request.
- **The runtime resolver layer is deleted.** `Resolvers/*` (including `DelegatingResolver` and its class map), `Patterns/*` (`PatternMatcher` and the matcher registry), and the per-call `Context` are gone — nodes compile to their evaluations, and the `illuminate/container`/`psr/container` dependencies fall away. `Expression` loses its `$resolver` constructor parameter. First-party node semantics are no longer host-swappable: the compiler certifies semantics, so silently replacing them was a drift channel, not a feature. Custom source behavior moves to compile-time `Extension::sourceCompilers()` callbacks (replacing both `TypedSource::returnType()` and the separately registered runtime resolver) so dependency injection survives without putting services into persisted source trees.
- **The operator contract collapses.** `supportsOverloading`/`evaluate`/`handles`/`typeOf` (four methods, two faces) become operator-owned `resolve()` verdicts. `OperatorOverloader`, `UnaryOverloader`, `BinaryOverloader`, `ComparisonOverloader`, `LogicalOverloader`, `NotOverloader`, `NegateOverloader`, and `DefaultOverloader` no longer exist — their semantics live in core dialect rows and `Equality`. `NullOverloader` (the `null ∘ null → null` rule) is deleted outright: it deliberately certified nothing, which under compile-then-trust means it could never run; binary absence policy is spelled as a rule that resolves `Option`-shaped operand types and refuses present pairs. **Migration**: signature-built extension rules are unchanged; hand-written rules implement `BinaryOperatorRule` or `UnaryOperatorRule`, own one symbol, and return an explicit resolution variant (see the extension guide).
- **`Unknown` is inert.** The "sanctioned unsoundness" hole is closed: `admits()` refuses `Unknown` operands, and operators, comparisons, and member access on `Unknown` are compile errors pointing at the fix — bridge with `Coerce` (convert a representation) or `Ascription` (claim a membership, runtime-verified). Gradualness is syntax the author can see, not checker leniency; every runtime type check in the system is admission through a `Type`'s two faces, at three visible sites: the binding boundary, `Coerce`, `Ascription`.
- **Runtime honesty** — the program does only what the compiler certified:
  - `StringType::assert` no longer reads `''` or `'null'` as absence — they are ordinary strings. The absence readings remain in `coerce`, the lenient admission face.
  - `DictType`: `[]` is a dict in **both** faces (`coerce([])` yields `Some([])`, not an absence reading — hosts that want "empty reads as missing" declare `Option<Dict<T>>`), and non-empty lists are rejected in both faces (previously `coerce([1, 2])` returned `[1, 2]`, a value the type's own `assert` refuses).
  - `ListType::assert` is strict membership: associative arrays are rejected instead of silently reindexed (reindexing remains in `coerce`); `ListType`/`ListShape` reject impossible bounds at construction (`min >= 0`, `max >= min`).
  - `BooleanType::coerce(null)` now yields absence (`None`) instead of silently producing `false`, so required-but-missing boolean bindings are detectable.
  - `!`/`not` are boolean-only — PHP truthiness on non-booleans no longer evaluates (it no longer compiles).
  - A match where no arm matches is a runtime error instead of silently evaluating to absence — and unprovable exhaustiveness is a compile error, so add a wildcard arm for a deliberate default.
- **Equality is value equality**, never PHP juggling: numeric within `Number` (`1 == 1.0`), strict identity otherwise, element-wise for containers, `false` across bases (`5 == '5'` is now `false`); `===`/`!==` are aliases of `==`/`!=`. The set operators (`has`/`in`/`intersects`) and literal patterns use the same one definition, so `match 5 { 5.0 => … }` matches exactly as exhaustiveness coverage predicts (`true in [1]` is now `false`). `ValueEquality` is the **one equality authority** — `Type::compare` is deleted: it defined a second, disagreeing equality (strict identity, so `1 === 1.0` was false) that nothing in the engine consumed.
- **Operators own only what they resolve**: equality for scalars, `null`, and (nested) arrays of them — never objects (object equality belongs to the type's owner, now expressible as an ordinary extension row) and never `Unknown`; ordering and arithmetic for real numbers only (numeric strings refused); set-operator needles judged universally over unions, opaques refused. String ordering and object comparison are compile errors, not silently evaluated PHP semantics.
- **Symbols are exact keys; member access is structure.** A namespaced symbol (`SymbolSource('turnover', 'customer')`) is answered by a binding or definition named exactly `customer.turnover` — never by digging into the value of a `customer` binding (`Bindings`' associative-array flattening and its descent successor are both gone; `TypeEnvironment` mirrors the same rule). Reaching into a record value is `MemberAccessSource`, certified against declared record fields. Caller data is therefore structurally unable to answer for — or shadow — a definition. **Migration**: bind keys exactly as declared (`['quote.turnover' => 600000]`, not `['quote' => ['turnover' => 600000]]`), or declare `quote` as a record, bind it whole, and use member access.
- **Records are exact** — no open/closed distinction: a record's value set is fully described by its declared fields (that exactness is what makes whole-record equality certifiable). The admission faces divide the work: `assert` rejects undeclared keys (strict membership), `coerce` takes the declared slice of wide input — hosts still pass whole context rows and only declared fields enter. Data with unenumerable keys is a `Dict`.
- **Declarations and definitions are disjoint namespaces**: a symbol is a parameter or a derived value, never both — a collision is a constructor error. Together with stripping and exact-key lookup, shadowing a definition is unrepresentable. Callers that overrode derived values via bindings model the override in-language: an `Option`-typed parameter the definition consults. Callers that relied on undeclared bindings feeding free symbols declare them (worst case `Unknown` — the explicit gradual path, bridged where used).
- **`TypeDefinition` is split into `Coerce` and `Ascription`**: `Coerce` converts via `coerce()` and types verbatim (the statically opaque conversion bridge); `Ascription` verifies via `assert()` and is checked at compile time (`Unknown`-or-overlaps — a disjoint claim is a compile error). **Absence cannot cross either node non-optionally**: when the declared/claimed type is not Option-shaped and the value reads as missing, evaluation errs naming the requirement instead of passing a silent `None` — declare/claim `Option<T>` where absence is legal.
- One representation of null in the resolution channel: a bound `null` normalizes to absence at symbol lookup (presence still lives in `has()`), and the admission bridges normalize an optional `Some(null)` the same way.
- Changed license from proprietary to MIT

## [1.0.0] - Initial Release

### Added
- Type system for data validation and transformation
  - NumberType for numeric coercion
  - StringType for string conversion
  - BooleanType for boolean validation
  - ListType for array/list validation
  - DictType for dictionary/map validation
- Expression evaluation system
  - InfixExpression for binary operations
  - UnaryExpression for unary operations
  - Operator overloading support
- Source system
  - StaticSource for direct values
  - SymbolSource for named references with namespace support
  - TypeDefinition for type-aware transformations
- Resolver pattern implementation
  - DelegatingResolver for chaining resolvers
  - StaticResolver for static value resolution
  - ValueResolver for type coercion
  - InfixResolver for expression evaluation
  - SymbolResolver for symbol lookup
- SymbolRegistry for managing named values with namespace support
- Functional programming approach
  - Result monad for error handling
  - Option monad for null handling
- Comprehensive test suite
  - 100% code coverage requirement
  - PHPStan level max static analysis
  - Mutation testing with Infection

### Architecture
- Strategy pattern for resolvers
- Chain of responsibility for delegating resolvers
- Factory pattern for type creation
- Functional programming with monadic error handling

[Unreleased]: https://github.com/gosuperscript/axiom/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/gosuperscript/axiom/releases/tag/v1.0.0
