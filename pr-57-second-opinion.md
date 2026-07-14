# Second opinion: PR #57 — Typesafe Axiom

Reviewed pull request: <https://github.com/gosuperscript/axiom/pull/57>

Reviewed head: `f9113d68414f6465eb5cb30c3889601d7e708a68`

## Verdict

Request changes before merging.

The overall direction is strong: runtime and static operator semantics are colocated, the shape vocabulary makes the relation rules inspectable, diagnostics retain cause chains, and the test suite is unusually thorough. However, the current implementation still permits several concrete divergences between what the checker certifies and what the evaluator does.

Findings 1, 2, 3, 5, and 6 are considered merge blockers because they break central soundness or safety guarantees made by the PR. Findings 4, 7, and 8 should also be resolved before presenting the shape algebra and exhaustiveness analysis as exact.

## Findings

### 1. Blocker: numeric matches can be certified as exhaustive and fail at runtime

`TypeInference::covers()` compares numeric literal shapes using loose numeric identity, so `5` and `5.0` denote the same literal:

- `src/Types/TypeInference.php:286`
- `src/Types/Shapes/LiteralShape.php:26`

The runtime literal matcher instead uses strict identity:

- `src/Patterns/LiteralMatcher.php:24`

Reproduction:

```php
match 5 {
    5.0 => 'ok'
}
```

Observed behavior:

```text
infer=ok
runtime=err: No match arm matched the subject; add a wildcard arm to handle unmatched values.
```

The matcher and exhaustiveness checker need to consume one shared equality definition. If numeric value equality is intentional, the runtime matcher should use it too. Otherwise literal-shape identity must become strict.

### 2. Blocker: nested bindings bypass the typed-shadowing rule

`Expression::admit()` checks only the top-level keys originally supplied by the caller:

- `src/Expression.php:196`

`Bindings`, however, also resolves a namespaced symbol by descending into an array binding:

- `src/Bindings.php:33`
- `src/Bindings.php:47`

Given a definition for `customer.turnover`, this binding silently shadows it:

```php
['customer' => ['turnover' => 42]]
```

Confirmed behavior:

```text
check-string=ok
runtime=int:42
```

The checker inferred the string-valued definition, but runtime returned the undeclared integer binding. This directly violates the stated rule that shadowing a definition requires a typed declaration.

The boundary should compare definitions against every effective resolvable binding key, including descended keys, rather than only the top-level input keys.

### 3. Blocker: evaluator and checker can still use different dialects

The `Expression` constructor preserves an `OperatorOverloader` or `UnaryOverloader` already registered in a bindable resolver:

- `src/Expression.php:70`

Inference always builds its operator stacks from `$this->dialect`:

- `src/Expression.php:256`

This leaves the legacy resolver configuration and the expression dialect independently configurable. It also means two expressions sharing one resolver but using different dialects can silently run the first expression's operator stack while checking against the second expression's dialect.

A focused reproduction prebound a `+` overloader that returns a string. The expression produced:

```text
inferred=Superscript\Axiom\Types\NumberType
runtime=string:not a number
```

This contradicts the PR's core claim that dialect miscomposition is no longer representable through the ordinary `Expression` API.

Typed expressions should do one of the following:

- own and install the runtime stacks unconditionally;
- reject an already-configured incompatible resolver;
- or derive inference from the exact runtime stacks obtained from the resolver.

A legacy unsafe configuration path should not continue to expose `infer()` and `check()` as certification APIs.

### 4. High: the List/Dict value algebra contradicts runtime membership

Both of these assertions succeed:

```php
(new ListType(new NumberType()))->assert([]);
(new DictType(new NumberType()))->assert([]);
```

Yet `TypeRelations::overlaps()` reports that `List<Number>` and `Dict<Number>` share no values:

- `src/Types/TypeRelations.php:272`
- `src/Types/TypeRelations.php:296`

This is observable end-to-end. An expression comparing a declared list and dict is rejected as a dead comparison, while strict boundary admission accepts `[]` for both and runtime equality returns `true`:

```text
infer=err/dead=yes
runtime=true
```

There is a related strict-membership problem in `ListType::assert()`:

- `src/Types/ListType.php:33`

It accepts an associative array and reindexes it:

```text
input:  {"x": 1}
output: [1]
```

That is coercion, not a strict assertion that the original value is already a list.

The implementation needs an explicit policy for PHP's ambiguous empty array and consistent rules across:

- `ListType::assert()` and `DictType::assert()`;
- literal inference;
- assignability and overlap;
- value equality;
- and the set operators' `array_is_list()` runtime checks.

### 5. Blocker: certified operator verdicts do not require total runtime coverage

The agreement harness validates the result type only for specimen pairs already claimed by `supportsOverloading()`:

- `tests/Operators/AgreementHarnessTest.php:128`
- `tests/Operators/AgreementHarnessTest.php:146`

When `typeOf()` returns `Ok`, unclaimed values of the operand types are ignored. Consequently, the checker can certify an operation even though no runtime overloader handles some values inhabiting the certified operand types.

#### Opaque equality reproduction

`ComparisonOverloader::typeOf()` checks only whether the types overlap:

- `src/Operators/ComparisonOverloader.php:85`

Its runtime face explicitly rejects objects:

- `src/Operators/ComparisonOverloader.php:24`
- `src/Operators/ComparisonOverloader.php:41`

Two inputs declared `Opaque<Thing>` therefore produce:

```text
check=ok
runtime=err: No overloader found for [stdClass ...] == [stdClass ...]
```

#### Unsupported union branch reproduction

`SetOperands::elements()` does not recursively validate every member of a union:

- `src/Operators/SetOperands.php:81`

For a needle declared `Number | Dict<Number>` in `needle in List<Number>`, the `Number` branch supplies enough overlap for `typeOf()` to return `Boolean`. A dict-valued needle is not claimed at runtime:

```text
check=ok
runtime=err: No overloader found for [associative array] in [list]
```

This is broader than a missing specimen. The current verdict type expresses only a result type; it does not describe which subset of the operand types an overload covers. The manager therefore cannot prove that multiple partial overloads jointly cover every possible operand value.

At minimum, the composed-dialect harness should assert that a non-`Unknown` `Ok` verdict has total specimen coverage. A fully sound design needs either:

- every successful `typeOf()` verdict to cover the complete operand types; or
- a richer verdict that carries the covered operand domain so the manager can prove coverage across rules.

Opaque and mixed-union specimens should be added to the composed-dialect harness.

### 6. Blocker: declarations hide definition cycles from cycle detection

`TypeEnvironment::typeOfSymbol()` returns a declaration before entering definition-cycle tracking:

- `src/Types/TypeEnvironment.php:47`
- `src/Types/TypeEnvironment.php:65`

The declared/defined agreement check infers a definition using that same environment:

- `src/Types/TypeEnvironment.php:98`
- `src/Types/TypeEnvironment.php:109`

This self-cycle therefore checks successfully:

```php
definitions: ['a' => new SymbolSource('a')],
declarations: ['a' => new NumberType()],
```

Observed result:

```text
self-cycle-infer=ok: Number
```

Without a binding, runtime follows the definition recursively. `Context` memoizes symbols only after resolution finishes, so it cannot break the cycle:

- `src/Context.php`
- `src/Resolvers/SymbolResolver.php`

This is exactly the runtime-fatal bug class the RFC says the checker catches.

Definition validation should traverse the definition graph without allowing the declaration for the currently validated definition to terminate recursion. Alternatively, cycle detection can be a separate graph pass independent of type declarations. Runtime cycle detection would also turn remaining configuration mistakes into a controlled error rather than unbounded recursion.

### 7. High: optionality depends on concrete `OptionType`, not projected `OptionShape`

The shape algebra canonicalizes unions containing an option into `OptionShape`:

- `src/Types/Shapes/UnionShape.php:29`

Several consumers still check for the concrete `OptionType` class.

#### Missing optional union is rejected

`Expression::admit()` decides whether a declaration is required using `instanceof OptionType`:

- `src/Expression.php:210`

A declaration such as:

```php
new UnionType(
    new OptionType(new NumberType()),
    new StringType(),
)
```

has canonical shape `(Number | String)?`, but a missing binding is rejected:

```text
shape=(Number | String)?
infer=ok
missing-binding=err: required input [x] is missing
```

#### Unary optionality does not propagate through canonicalized unions

`TypeInference::inferUnary()` also checks `instanceof OptionType`:

- `src/Types/TypeInference.php:199`

`Union<Option<Number>, Number>` has canonical shape `Number?`, but unary negation is rejected rather than inferred as `Number?`:

```text
operand-shape=Number?
unary-infer=err: [-] requires a present number; got Number?.
```

These paths should operate on `OptionShape` and reify its inner shape, just as member-access inference already operates on projected shapes. Otherwise custom option-shaped domain types and canonicalized unions do not participate in the promised projection-only model.

### 8. Medium: a null-only match is incorrectly considered non-exhaustive

The null literal infers as `Option<Never>`. `TypeInference::covers()` requires both a null pattern and coverage of the option's inner shape:

- `src/Types/TypeInference.php:276`

Because `covers(Never)` falls through to `false`, this valid expression is rejected:

```php
match null {
    null => 'ok'
}
```

Observed behavior:

```text
infer=err: This match over Never? may not be exhaustive
runtime=ok: ok
```

`Never` has no inhabitants and should be vacuously covered.

## Additional design concern: core `OpaqueType` does not establish nominal membership

`OpaqueType::assert()` accepts every non-null value regardless of nominal identity:

- `src/Types/OpaqueType.php:36`

At the same time, the relation layer treats distinct opaque identities as disjoint. The same value therefore passes both of these runtime assertions:

```php
new OpaqueType('ClaimId');
new OpaqueType('CatalogueKey');
```

while static overlap reports `false`.

The class documents this as dynamically unverifiable and recommends real host-owned types. That can be a deliberate unsafe escape hatch, but it weakens the claim that the expression boundary establishes declared-type membership. Consider preventing core `OpaqueType` from being used directly as a boundary/ascription validator, requiring a host verifier, or treating its runtime posture explicitly as gradual/unsafe rather than nominally certified.

## Verification performed

The repository remained unmodified during review until this report was added.

The existing verification passes:

```text
PHPUnit: 901 tests, 7,730 assertions
Coverage: 100% of 1,698 lines
PHPStan: no errors
GitHub checks: green at the reviewed head
```

The focused reproductions above demonstrate paths not covered by those tests. In particular, 100% line coverage does not exercise the cross-component contracts among inference, boundary admission, resolver dispatch, and runtime overloader selection.

## Recommended regression tests

Add end-to-end `Expression` tests for at least:

1. Numeric match literal identity (`5` versus `5.0`).
2. Nested binding shadowing of a namespaced definition.
3. A prebound or shared resolver whose runtime dialect differs from the expression dialect.
4. Empty list/dict equality and strict assertion behavior.
5. Same-identity opaque object equality without an object overloader.
6. A union with one runtime-supported and one unsupported operator branch.
7. Declared self-cycles and mutual cycles with a no-binding execution path.
8. Missing bindings for canonical option-shaped unions.
9. Unary propagation through an option-shaped union.
10. Exhaustiveness of `match null { null => ... }`.

These tests should assert both the static verdict and the runtime outcome. That is the level at which the PR's central soundness guarantee lives.
