# Rewriting Stored Source

- [Why](#why)
- [A Worked Example](#a-worked-example)
- [Rules](#rules)
- [Descent, and the Opaque-Leaf Policy](#descent-and-the-opaque-leaf-policy)
- [Obligations](#obligations)
- [The Report](#the-report)
- [Writing a Rule](#writing-a-rule)
- [When the Rule Cannot Decide](#when-the-rule-cannot-decide)
- [What Is Not a Source Rewrite](#what-is-not-a-source-rewrite)

## Why

A host that stores expressions accumulates a corpus it did not write all at once: thousands of conditions authored over years, against a language that has since grown a better spelling for something. Editing them by hand is a migration nobody finishes; editing them with a script is a migration nobody can prove.

This is the toolkit for the middle path: a rule set applied to stored source, bottom-up over an immutable tree, where every replacement must discharge an obligation before it is applied, and everything the run did — and everything it could not see — is reported.

## A Worked Example

An author wrote a double negation, and the corpus has 400 more like it:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Rewrite\{ArrayBindingsCorpus, Rewriter, VerdictPreservation};
use Superscript\Axiom\Rewrite\Rules\RemoveDoubleNegation;
use Superscript\Axiom\Sources\{InfixExpression, StaticSource, SymbolSource, UnaryExpression};
use Superscript\Axiom\Types\{BooleanType, NumberType, OptionType, Optional};

$not = fn ($source) => new UnaryExpression('!', $source);

$expression = new Expression(
    source: new InfixExpression(
        $not($not(new InfixExpression(new SymbolSource('roof'), '>', new StaticSource(0.25)))),
        '&&',
        $not($not(new SymbolSource('flag'))),
    ),
    declarations: [
        'roof' => new Optional(new OptionType(new NumberType())),
        'flag' => new BooleanType(),
    ],
);

$rewriter = new Rewriter(
    [new RemoveDoubleNegation()],
    obligations: [new VerdictPreservation(new ArrayBindingsCorpus([
        'answered' => ['roof' => 0.3, 'flag' => true],
        'unanswered' => ['flag' => false],
    ]))],
);

$run = $rewriter->rewrite($expression);
```

Read the report first and take nothing — that is the dry run:

```
applied axiom.rewrite.remove-double-negation at $.left: !!(roof > 0.25) => roof > 0.25
  type preservation upheld: both compile to Boolean?
  verdict preservation upheld: 2 corpus case(s) agree
applied axiom.rewrite.remove-double-negation at $.right: !!flag => flag
  type preservation upheld: both compile to Boolean
  verdict preservation upheld: 2 corpus case(s) agree
```

Then take the tree:

```php
$run->changed;             // true
$run->source->describe();  // (roof > 0.25) && flag
$run->expression();        // the same dialect, definitions, declarations and boundary over the new tree
```

There is no mode flag: report-only is this same run with the tree ignored.

Now the same rule against a stored expression that never compiled — `!!count` where `count` is a Number:

```
refused axiom.rewrite.remove-double-negation at $: !!count => count
  type preservation broken: the original refuses and the replacement compiles: [!] expects Boolean; got Number.
  Number is not assignable to Boolean.
  verdict preservation unchecked: the run was given no oracle for this claim
```

`$run->changed` is `false` and `$run->source` is the very tree that went in. Simplifying here would have handed back a certified Number program in place of a refusal — a program the author never wrote and the checker never blessed. One site is refused; the rest of the run proceeds.

## Rules

A rule owns matching and replacement for exact source classes:

```php
interface RewriteRule
{
    public function identifier(): string;

    /** @return non-empty-list<class-string<Source>> */
    public function visits(): array;

    public function rewrite(Source $source): ?Source;   // null = nothing to do here

    /** @return list<Preservation> */
    public function preserves(): array;
}
```

Structural knowledge lives here rather than on `Source`, and descent lives in the toolkit. A `Source` is data a host persists; every method the language puts on it is a method every host node must implement forever. A rule that only rewrites `!!x` needs to know about exactly two node shapes, and writes no traversal at all.

Dispatch is by exact class, the same ownership model source compilers use: rules are indexed by the classes they visit, so a node costs one array lookup however many rules the run carries. A rule listing a parent class is never offered a subclass.

At each node, rules registered for its class are offered in registration order until one takes it. A rule that returns `null` is passed over; a rule whose replacement breaks an obligation is recorded refused and the next rule is asked; the first sound replacement wins and ends the visit.

## Descent, and the Opaque-Leaf Policy

The walk is bottom-up: a node's children are rewritten, the node is rebuilt around whatever came back, and only then are rules offered the rebuilt node. A rule therefore always sees the shape that will actually be stored, and one pass collapses a nest — `!!!!x` reduces at every level on the way out.

**Structural sharing.** A node whose children all came back identical is returned as-is, so an untouched subtree is the same instance in the new tree. `$run->changed` is answered by identity, not comparison.

**Core shapes** are the toolkit's own: `CoreSourceDescenders` has an arm per node class, and an exhaustiveness law reflects over `src/Sources` and fails if a class exists without one.

**Host shapes** register through the extension a package already ships:

```php
final class BoxExtension extends Extension
{
    public function sourceDescenders(): array
    {
        return [BoxSource::class => $this->descend(...)];
    }

    private function descend(BoxSource $source, Descent $descent): Source
    {
        $inner = $descent->child($source->inner, 'inner');

        return $inner === $source->inner ? $source : new BoxSource($inner);
    }
}

$rewriter = new Rewriter($rules, SourceDescenders::core()->with(new BoxExtension()));
```

An arm asks for each child by the property holding it — that name is the path segment the report prints — and returns the same instance when no child moved. Ownership is exact and unranked: two extensions claiming one class is a configuration error.

**A class no extension claims is an opaque leaf.** It is never descended, never rewritten — not even by a rule naming its own class, because a rule cannot be trusted to rebuild a shape the toolkit cannot take apart — and it is *reported*:

```
opaque Acme\Sources\LookupSource at $.right: LookupSource
```

Silence would be the dangerous answer. A host that adds a source class and forgets its descent arm would read "no rewrites needed" as "nothing to do" for as long as the omission lasted.

## Obligations

A rule declares what it preserves; the run checks what it can.

| Preservation | Oracle | When |
| --- | --- | --- |
| `CertifiedType` | compile both subtrees in the expression's declaration scope | always, whether or not the rule claims it |
| `Verdict` | run both programs over a `BindingsCorpus` and compare answers | when the run is given a `VerdictPreservation` |

Both subtrees are compiled standalone *in the whole expression's scope*, which is exact rather than approximate because the language has no binding form: no node introduces a name for its children, so a subtree reads the same environment wherever it sits.

Type preservation demands the same certified type — or the same refusal, since neither tree can then run and a rewrite must not be what changes the diagnostic an author reads.

Verdict preservation is evidence, not proof: only the host knows what its programs are fed, and a corpus that never exercises a branch says nothing about it. Its cases are labelled, because a disagreement has to be reproducible:

```
verdict preservation broken: case [zero]: the original answers error(Division by zero) and the replacement value(5)
```

A verdict comes in three states, and the report keeps them apart: **upheld** (checked, and it holds), **broken** (checked, and it does not — the site is refused), and **unchecked** (no oracle was supplied, so nothing is known either way). Unchecked never blocks a rewrite and never counts as evidence for one.

## The Report

```php
$run->report->applied();  // list<RewriteRecord>: rule, path, before, after, verdicts
$run->report->refused();  // the same, for replacements an obligation broke
$run->report->opaque;     // list<OpaqueSource>: path, class, describe
$run->report->describe(); // all of it, as text
```

Paths are property-named: `$.left.operand`, `$.arms[1].expression`. That is deliberately not the `$.children[0].node` language compilation failures and analyses speak — those number the children a *compiler* recorded, and a compiler records what it needs (a wildcard arm records no pattern child at all), so the same arm sits at a different index depending on which patterns precede it. A coordinate into stored source has to name the same node before anything is compiled.

## Writing a Rule

`RemoveDoubleNegation` is the reference. What makes it sound is worth copying, more than the code is:

```php
public function rewrite(Source $source): ?Source
{
    if (! $source instanceof UnaryExpression || ! $this->negates($source->operator)) {
        return null;
    }

    $operand = $source->operand;

    if (! $operand instanceof UnaryExpression || ! $this->negates($operand->operator)) {
        return null;
    }

    return $operand->operand;
}
```

The neutrality argument is about the *dialect*, not the shape. Core's `!` is the only row for that symbol: it takes Boolean and returns Boolean. So a `!!x` that compiles at all has an `x` of `Boolean` or, through the resolver's lift, `Boolean?`. On `Boolean`, `!` is total and involutive. On `Boolean?` the resolver lifts that same row, and lifting is functorial — absence propagates through each negation untouched, so `!!` is the lift of `!∘!`, the identity. Every operand type the rewrite can meet is one it is neutral on.

Note what the argument rests on, and say so in the rule: a host is free to register `!` over its own type and make it something other than an involution, so the spellings are a constructor argument (`new RemoveDoubleNegation(['!'])`) rather than a constant. The obligations then check what the argument assumed rather than trusting it — which is the point. A fold that type-checks can still invert the meaning of a condition, and a rewrite nobody proved is a rewrite nobody should store.

## When the Rule Cannot Decide

`RemoveRedundantDefault` is the other shape a rule takes: one whose applicability is not decided by matching at all.

A `DefaultValue` over a source that can never be absent is already the identity — the compiler returns the inner compiled source untouched, default and all — so the node is noise in stored source, and it reads as if absence were possible where it is not. But whether the inner source can be absent is a fact about *types*, and no amount of looking at the tree settles it.

So the rule proposes the removal everywhere and lets the obligation settle it:

```php
public function rewrite(Source $source): ?Source
{
    return $source instanceof DefaultValue ? $source->source : null;
}
```

Over an optional inner, `x ?? 0` certifies `Number` while `x` certifies `Number?`; the types differ and the site is refused. Over a definite inner, both certify the same type — necessarily, since they compile to the same node — and the rewrite is taken. In `amount ?? 1 ?? 0` with `amount` optional, the inner default — the one absence can actually reach — is refused, and the outer one, which never fires, is removed:

```
refused axiom.rewrite.remove-redundant-default at $.source: amount ?? 1 => amount
  type preservation broken: the original compiles to Number and the replacement to Number?
  verdict preservation unchecked: the run was given no oracle for this claim
applied axiom.rewrite.remove-redundant-default at $: amount ?? 1 ?? 0 => amount ?? 1
  type preservation upheld: both compile to Number
  verdict preservation unchecked: the run was given no oracle for this claim
```

Removing the node does change what an execution observer sees — the `default` label and its annotations go with it. That is a change to the program's trace, not to what it answers.

## What Is Not a Source Rewrite

A migration only becomes a rewrite rule once both the old and new shapes are representable as `Source` trees. Where a language change removed the old shape from the node set, there is nothing for a rule to match: the old form cannot be hydrated into any `Source` at all, and the migration belongs in the host's deserializer, before a tree exists.

The namespaced-symbol migration is exactly that case. `SymbolSource` no longer carries a namespace, and its constructor rejects a dotted name outright, so stored `{"name": "turnover", "namespace": "customer"}` has no `Source` to become except the one the host's hydration chooses — `MemberAccessSource(SymbolSource('customer'), 'turnover')`. Reach for this toolkit for changes *within* the node set; reach for the wire format for changes *to* it.
