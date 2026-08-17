<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Analysis\CompilationAnalysis;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Analysis\RecoveringCompiler;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\RecordType;
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
 *     declarations: new RecordType(['radius' => new NumberType()]),
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
 * The declaration record is the expression's complete public input
 * signature. Its root properties and definitions are disjoint symbol sets: a
 * root is an input or a derived value, never both. Structure beneath it is
 * retained in a ReferencePath.
 */
final readonly class Expression
{
    public Dialect $dialect;

    public RecordType $declarations;

    /**
     * @param RecordType|array<string, Type|\Superscript\Axiom\Types\Optional> $declarations
     */
    public function __construct(
        public Source $source,
        public Definitions $definitions = new Definitions(),
        ?Dialect $dialect = null,
        RecordType|array $declarations = [],
        public Boundary $boundary = Boundary::Coerce,
    ) {
        $this->dialect = $dialect ?? Dialect::core();
        $this->declarations = $declarations instanceof RecordType ? $declarations : new RecordType($declarations);

        // Root declarations and definitions must remain unambiguous. Nested
        // names belong to their record and are reached by ReferencePath.
        $collisions = array_filter(
            $this->declarations->names(),
            fn(string $key) => $this->definitions->has($key),
        );

        if ($collisions !== []) {
            throw new InvalidArgumentException(sprintf(
                'Declarations and definitions have disjoint root symbols, but [%s] %s both declared and defined. A symbol is a parameter or a derived value, never both; model an override as an Option-typed parameter the definition consults.',
                implode('], [', $collisions),
                count($collisions) === 1 ? 'is' : 'are',
            ));
        }
    }

    /**
     * Returns the names of the free variables in the expression that are not
     * covered by the bound definitions — i.e. the parameters the caller is
     * expected to provide as bindings.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        $parameters = [];

        foreach (UnboundSymbols::in($this->source) as $reference) {
            $root = $reference->root();

            if ($this->definitions->has($root) || in_array($root, $parameters, strict: true)) {
                continue;
            }

            $parameters[] = $root;
        }

        return $parameters;
    }

    /**
     * Compile the description into a certified, callable {@see Program}:
     * the definition graph is proven well-founded (a cyclic definition
     * would recurse without terminating, and that is a graph property
     * declarations can never repair — see DefinitionGraph), then every
     * node compiles to its type and its evaluation through the dialect's
     * own stacks over the same Definitions the program embeds.
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
     * the expression rather than only the first, the references it reads even
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
        return new self($this->source, $definitions, $this->dialect, $this->declarations, $this->boundary);
    }
}
