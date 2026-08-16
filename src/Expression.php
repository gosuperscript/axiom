<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Analysis\CompilationAnalysis;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Analysis\RecoveringCompiler;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

/**
 * A complete description of a program: a {@see Source} tree, the
 * {@see Dialect} (operator rules + literal and source registrations), any
 * {@see Definitions} it depends on, and the declared input types.
 * Deliberately not runnable — compile() is the one way from description
 * to execution:
 *
 * ```php
 * $area = new Expression($source,
 *     definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
 *     declarations: ['radius' => new NumberType()],
 * );
 * $program = $area->compile()->unwrap(); // every node resolved and certified
 * $program(['radius' => '5']);           // boundary coerces '5' → 5, then ~78.54
 * ```
 *
 * compile() refuses, with names, everything that would make evaluation
 * dishonest: definition cycles, unbound symbols, operators no rule
 * resolves (or two rules claim), inert Unknown at an operand, false
 * ascription claims. Evaluation presupposes a passed check the way
 * admitted values presuppose the boundary — invocation lives only on
 * {@see Program}, so running an unchecked program is unrepresentable.
 *
 * The declaration list is the expression's complete public signature —
 * declarations and definitions are disjoint namespaces (a symbol is a
 * parameter or a derived value, never both; enforced at construction), so
 * shadowing a definition is unrepresentable.
 *
 * A declaration is a {@see Type}, or an {@see Input} wrapping one where the
 * host also has something to say about supply. Both normalize to `$inputs`
 * on the way in, and compilation is handed `$declarations` — the types —
 * whichever form was written.
 */
final readonly class Expression
{
    public Dialect $dialect;

    /**
     * The declared inputs, normalized: a bare {@see Type} declaration is the
     * {@see Input::of()} reading of itself, so every declaration is one kind
     * of thing from here on.
     *
     * @var array<string, Input>
     */
    public array $inputs;

    /**
     * The declared types alone, in declaration order — everything
     * compilation is told about the caller. Demandedness is a boundary fact,
     * and is not among the facts inference can see.
     *
     * @var array<string, Type>
     */
    public array $declarations;

    /**
     * @param array<string, Type|Input> $declarations
     */
    public function __construct(
        public Source $source,
        public Definitions $definitions = new Definitions(),
        ?Dialect $dialect = null,
        array $declarations = [],
        public Boundary $boundary = Boundary::Coerce,
    ) {
        $this->dialect = $dialect ?? Dialect::core();
        $this->inputs = Input::normalize($declarations);
        $this->declarations = array_map(static fn(Input $input) => $input->type, $this->inputs);

        // Symbol lookup is exact-key only (no descent), so declared and
        // defined names can only collide literally: Symbol('turnover',
        // ns: 'customer') and member access on a declared customer record
        // are distinct, unambiguous programs.
        $collisions = array_filter(
            array_keys($this->declarations),
            fn(string $key) => $this->definitions->has($key),
        );

        if ($collisions !== []) {
            throw new InvalidArgumentException(sprintf(
                'Declarations and definitions are disjoint namespaces, but [%s] %s both declared and defined. A symbol is a parameter or a derived value, never both; model an override as an Option-typed parameter the definition consults.',
                implode('], [', $collisions),
                count($collisions) === 1 ? 'is' : 'are',
            ));
        }
    }

    /**
     * The names the caller is expected to provide as bindings: every symbol
     * the compiler reads that no definition answers for, in first-read order.
     *
     * The set comes from the compiler's own walk — the one description of
     * the tree's structure — via {@see diagnose()}, which records reads even
     * through parts that refuse. Reads reached through definitions count
     * (a definition that reads a declared input makes that input this
     * expression's parameter), and a definition's own name never does.
     *
     * The answer is therefore relative to the dialect: a region the dialect
     * cannot compile at all — a source class with no registered compiler —
     * is never descended, so symbols under it do not appear. The refusal
     * that explains the smaller answer is in diagnose()'s diagnostics.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        return array_values(array_filter(
            $this->diagnose()->references,
            fn(string $key) => !$this->definitions->has($key),
        ));
    }

    /**
     * Compile the description into a certified, callable {@see Program}:
     * every node compiles to its type and its evaluation through the
     * dialect's own stacks over the same Definitions the program embeds. A
     * cyclic definition — one whose evaluation would recurse without
     * terminating — is refused where the descent closes the cycle, like any
     * other fault (see TypeEnvironment).
     *
     * Hosts with stored corpora compile once — at authoring or deploy
     * time — and invoke per request: no per-call inference walk, no
     * per-node dispatch, definitions resolved exactly once.
     *
     * @return Result<Program, TypeMismatch>
     */
    public function compile(): Result
    {
        return new RecoveringCompiler($this)->compile();
    }

    /**
     * Compile for the sake of what compilation *learns*: every refusal in
     * the expression rather than only the first, the symbols it reads even
     * through the parts that refuse, and the certified {@see Program} when
     * there is nothing to report. compile() is one attempt of this same
     * walk, so its refusal is this diagnosis' first diagnostic. What the
     * extra attempts can and cannot see is {@see Diagnosis}.
     */
    public function diagnose(): Diagnosis
    {
        return new RecoveringCompiler($this)->diagnose();
    }

    /**
     * What does this expression return? A convenience over compile().
     *
     * @return Result<Type, TypeMismatch>
     */
    public function infer(): Result
    {
        return $this->compile()->map(fn(Program $program) => $program->returns);
    }

    /**
     * Explain the exact typed decisions made by successful compilation.
     *
     * @return Result<CompilationAnalysis, TypeMismatch>
     */
    public function analyze(): Result
    {
        return $this->compile()->map(fn(Program $program) => $program->analysis);
    }

    /**
     * Does this expression produce the expected type? compile + assignability.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function check(Type $expected): Result
    {
        return $this->infer()->andThen(
            fn(Type $actual) => TypeRelations::isTypeAssignableTo($actual, $expected)->map(fn() => $actual),
        );
    }

    public function withDefinitions(Definitions $definitions): self
    {
        return new self($this->source, $definitions, $this->dialect, $this->inputs, $this->boundary);
    }
}
