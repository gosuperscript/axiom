# RFC 0002: Locating compilation failures

- **Status**: Accepted — implemented as described. `describe()` was left unchanged; paths are read from `$path`.
- **Date**: 2026-07-26

## TL;DR

`Expression::compile()` says *why* a program failed to compile but not *where*. `TypeMismatch` is `(string $message, list<TypeMismatch> $causes, bool $dead)` — no location — so a caller that wants to attribute a failure to a node in the source tree cannot.

The proposal: give `TypeMismatch` an optional `?string $path`, and have the compiler stamp it as a refusal leaves the node that made it. The path is the same `$`-rooted string `CompilationNode::toArray()` already threads on the success path, so success and failure address nodes in one language.

- `TypeMismatch::__construct` gains a trailing `?string $path = null`. Source-compatible: every existing construction site keeps working, and `compile()` still returns `Result<Program, TypeMismatch>`.
- The compiler threads the path **down** the walk and stamps **once**, at the single point every node failure passes through. First stamp wins, so the path names the deepest node that refused — the one a caller wants to point at.
- Nested causes carry their own paths when they are node failures, and stay unlocated when they are claims about types rather than nodes. That line is principled, not incidental; see [Which causes get a path](#which-causes-get-a-path).

## Motivation

A caller holding a failed compile learns the program is not well typed and reads prose explaining why. To attribute that to a node it has only one route: compile every complete subtree on its own and see which ones fail. On a 13-node tree of depth 7 against the core dialect, that is 13 compiles costing **4.3× one root compile** — measured after `Dialect` began indexing its rules once, which removed part but not all of the redundancy (each compile still re-runs `DefinitionGraph::cycles()` over every definition and rebuilds the `TypeInference`).

Cost is the smaller problem. Subtree compiles *reconstruct* per-node verdicts instead of reading them, so they can disagree with what the real compile said: a subtree compiled in isolation is a different program from the same subtree compiled in place — its operand types come from the same declarations, but nothing guarantees a caller reassembles the enclosing context the compiler used. Attribution should be read off the compile that actually happened.

Axiom already has the vocabulary. On success, `CompilationAnalysis` holds a `CompilationNode` tree, and `CompilationNode::toArray(string $path = '$')` threads a path down it, so a successful compile tells a caller exactly which node produced which type at which path. On failure it says nothing about location. This RFC closes that asymmetry.

## Current state: a worked example

`(name + 1) * 2`, where `name` is declared `String`. The inner `+` has no overload for `String` and `1`; the outer `*` is never reached.

```php
$source = new InfixExpression(
    new InfixExpression(new SymbolSource('name'), '+', new StaticSource(1)),
    '*',
    new StaticSource(2),
);

$expression = new Expression($source, declarations: ['name' => new StringType()]);
$expression->compile();
```

### What compile() returns today

```
Err(TypeMismatch {
  message: "[+] expects Number and Number; got String and 1."
  causes:  [ TypeMismatch { message: "String is not assignable to Number.", causes: [], dead: false } ]
  dead:    false
})
```

`describe()` renders that as:

```
[+] expects Number and Number; got String and 1.
  String is not assignable to Number.
```

Correct and unattributable. There are two `+`-shaped things a reader could blame and nothing in the value distinguishes them. Meanwhile, the *same tree typed so it compiles* reports every node's position:

```
$                                     InfixExpression  -> Number
$.children[0].node                    InfixExpression  -> Number
$.children[0].node.children[0].node   SymbolSource     -> Number
$.children[0].node.children[1].node   StaticSource     -> Number
$.children[1].node                    StaticSource     -> Number
```

### What compile() would return

```
Err(TypeMismatch {
  message: "[+] expects Number and Number; got String and 1."
  path:    "$.children[0].node"
  causes:  [ TypeMismatch { message: "String is not assignable to Number.", path: null, ... } ]
  dead:    false
})
```

One compile, and the caller knows the inner `+` is the node to mark. The path is the same string the successful analysis used for that node, so a caller that renders analyses already has the addressing code. Because paths are prefixes, the ancestor chain comes for free: the failing node's parent is `$`.

## Design

### The path is threaded down, not accumulated up

On the success path nodes do not store their own paths — `CompilationNode::toArray()` derives them by walking down from the root, numbering each child by its position. During compilation a node cannot know its own position from the bottom up (it is not recorded as a child until its parent records it), so the path is threaded **downward**, along the same walk:

- `TypeInference::compile(Source $source, TypeEnvironment $environment, string $path = '$')` — the node's own path.
- `CompilationRecorder`, already the mutable state scoped to compiling one node, also holds that node's path and answers `childPath()` as `"{$path}.children[" . count($this->children) . "].node"`. Numbering off the recorder's own child list is what keeps compile-time paths and `toArray()` paths identical by construction: both count the same recorded children, and both skip a child that records no compilation.
- `SourceCompilation::child()` and `::symbol()` pass `childPath()` into the nested compile. The failing child's would-be index is the number of children recorded so far, which is correct precisely because compilation stops at the first failure — no sibling ever claims that index.

### One stamping site

Every node-level failure — a `reject()` in a source compiler, an unresolvable operator, an unbound symbol, a failed type relation, a host compiler's own refusal — reaches the same place: `SourceCompilation::require()`/`reject()` throw `CompilationAborted`, and `TypeInference::compile()` catches it. So there is exactly one line to change:

```php
} catch (CompilationAborted $aborted) {
    return Err($aborted->mismatch->at($path));
}
```

plus the one `TypeMismatch` `compile()` constructs itself (the unregistered-source-class refusal), which is constructed with the path directly.

`at()` is **idempotent — first location wins**:

```php
public function at(string $path): self
{
    return $this->path === null ? new self($this->message, $this->causes, $this->dead, $path) : $this;
}
```

That matters because a child's failure propagates *through* its parent: the child's compile stamps the child's path, `child()` rethrows, and the parent's catch sees an already-located mismatch and leaves it alone. The result is that `compile()`'s error names the deepest node that refused. A parent's *own* refusal — an operator no rule resolves at that node — arrives unlocated from the resolver and gets that node's path. Both are what a caller wants to point at.

### Which causes get a path

A cause is located when it is a node failure and unlocated when it is a claim about types.

Node failures nest when a source compiler wraps a child's refusal with context — `SourceCompilation::within()` builds `new TypeMismatch($message, [$aborted->mismatch])`, and the wrapper is unlocated at that moment. So the cause keeps the child's path and the wrapper gets the enclosing node's path from the choke point. A caller walking the chain gets one path per level, which is what makes per-node rendering useful.

Type-relation causes stay `null`, and should. In the worked example the cause is `String is not assignable to Number.` — produced by the operator resolver from operand *types*. Nothing at that level knows which node produced which type: `SourceCompilation::infix()` receives `Type` values, not the `CompiledSource`s they came from, so the association is already gone by the time the rule is asked. Locating operand causes would mean threading paths through `SourceCompilation::infix()`/`prefix()` — a signature change to the public plugin seam every extension compiler uses — to attribute a refusal that arguably is not the operand's fault anyway: `name` genuinely is a `String`; the node with no valid reading is the `+`. If operand-level attribution turns out to be needed, it is a separate, additive change with its own cost to weigh.

`null` therefore carries meaning: *this verdict is not about a node*. Whole-program refusals keep it too — the definition-cycle mismatch `Expression::compile()` returns before any walk starts is a property of the definition graph, not of a position in the tree.

### Definition subtrees

A symbol that resolves to a definition compiles the definition's source, and the failure is located at the referencing edge (`children[i].node` under the referencing node, the same edge the success-path analysis records with role `definition`). Two references to one definition therefore address it by two paths — exactly as the success path already does, since `toArray()` numbers per occurrence.

One caveat to record: `TypeEnvironment` memoises the compiled definition per key, including a failure, so a memoised `Err` would carry the path of the *first* reference that compiled it. It can never be served twice today, because the first failure aborts the whole compile. If a future partial-compile mode continues past a failure, this becomes real and the memo would need to hold the unlocated mismatch and locate it per reference.

### Path syntax

Reuse `CompilationNode::toArray()`'s convention exactly: `$`-rooted, `.children[i].node` per edge, `.operators[i]` for a selection. One correction to the framing in the request: that convention is **positional, not role-named** — `CompilationChild::$role` is a label carried beside the edge, not part of the path, and roles are not all position-shaped anyway (`MatchExpressionCompiler` uses `subject`, `arm.0.expression`, `pattern.expression`). So the question of whether roles are stable enough to address by does not arise; nothing addresses by them. What must be stable is the order a compiler compiles its children in, which is fixed per compiler and already governs the success-path paths a caller reads today.

## Key decisions

| Question | Decision |
| --- | --- |
| Where does location live? | On `TypeMismatch`, as a trailing optional `?string $path = null`. |
| Does `compile()`'s error type change? | No. It stays `Result<Program, TypeMismatch>`. |
| Where does the path come from? | Threaded down the walk with the recorder, stamped once where `CompilationAborted` is caught. |
| Do causes carry their own paths? | Node failures do; type-relation causes stay `null`, because nothing at that level knows the node. |
| Which node does the top-level path name? | The deepest one that refused — `at()` is idempotent, so the first stamp survives. |
| Path syntax | `CompilationNode::toArray()`'s positional `$`-rooted convention, unchanged. |
| Does `describe()` change? | No, so existing rendered output is stable. Paths are read from `->path`. |

## Backwards compatibility

Source-compatible in full.

- `TypeMismatch` is `final readonly` with a public constructor, constructed in 59 places across `src/` and freely by hosts. A trailing optional parameter leaves every existing call valid, and the new public property is additive.
- `TypeInference::compile()` gains a trailing optional `string $path = '$'`; the class is `final`, so no implementor can be broken, and existing two-argument calls keep working.
- `SourceCompilation`'s internal `$compileNode`/`$compileSymbol` closures gain a path argument. These are `@internal`, constructed only by `TypeInference`. Extension source compilers call `child()`/`symbol()`/`infix()`/`prefix()`, whose signatures do not change.
- Two behavioural changes a strict test could notice: a mismatch that used to have three properties now has four, and a mismatch reaching a host through `compile()` now carries a non-null `path`. Nothing in the suite compares whole `TypeMismatch` values, so this is a note for hosts, not a migration.

No deprecation is needed and nothing is removed.

## Alternative considered: a richer error beside the mismatch

`compile()` could return an error pairing the mismatch with a partial `CompilationAnalysis` of the nodes that *did* compile, letting a caller show types for the healthy parts of a broken tree. That is strictly more informative and strictly more expensive: it changes the error type of `compile()`, `infer()`, `check()` and `analyze()` — a real migration for every host — and it needs a compiler that continues past a failure to have anything partial worth returning, which is a bigger change than adding a location and interacts with the definition memo noted above.

The two are not in conflict: locating the mismatch is what attribution needs, and partial analysis is an additive capability that can land later on its own merits (as a separate entry point, leaving `compile()`'s signature alone). Proposed: location now.

## Tests

`tests/CompilationFailureLocationTest.php`, nine cases:

- The worked example end to end: the failure's `path` is `$.children[0].node`, and it is one of the paths the *same tree, typed so it compiles* reports through `analyze()`. Tying the two channels to one string is the test that matters — it fails if either side's numbering drifts.
- The same tie for a definition body: located at the referencing edge, and present in the working tree's analysis paths.
- First-stamp-wins: a failure nested two levels deep names the deepest node, not an ancestor.
- The `within()`-wrapping compiler (match expressions) produces a chain carrying one path per level, outermost first, ending in an unlocated type claim.
- Type-relation causes stay `null`.
- Definition cycles stay unlocated, wrapper and cause alike.
- A source no compiler claims is located where it sits — `$` at the root, `$.children[0].node` nested.
- An unbound symbol is located where it is referenced.
- `at()` as a unit: it carries message, causes and `dead` across, and re-locating is a no-op.

Two notes on coverage of the mechanism. A declared symbol records no `definition` child, so the guard on recording is exercised by the worked example itself — its `SymbolSource` node has no children in the analysis, and the paths still agree. And nothing reachable through `child()` can record a null compilation (every node `compile()` returns carries a `CompilationNode`), so sibling numbering has no way to shift; the only null-compilation child is the declared symbol above.
