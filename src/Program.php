<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Analysis\CompilationAnalysis;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Exceptions\InadmissibleBinding;
use Superscript\Axiom\Exceptions\MissingRequiredInput;
use Superscript\Axiom\Exceptions\RejectedBinding;
use Superscript\Axiom\Execution\Observer;
use Superscript\Axiom\Analysis\CompilationState;
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A compiled, certified, callable program — the only callable thing in the
 * library. `Expression::compile()` is the sole constructor path a host
 * needs: every operator and symbol is already resolved, the return type is
 * a property, and running an unchecked program is unrepresentable because
 * nothing else can be run.
 *
 * ```php
 * $program = $expression->compile()->unwrap();
 * $program->returns;            // Type
 * $program(['radius' => '5']);  // boundary coerces, then evaluates — no dispatch
 * ```
 *
 * Certification is conditional — "if inputs inhabit their declared types" —
 * and compile() cannot prove future inputs, so the boundary is the one
 * runtime type check that survives compilation, by design: every binding the
 * program reads passes through its declared type (coerce by default, assert
 * for strict hosts) before evaluation, with violations aggregated, named, and
 * sorted into the two kinds {@see BoundaryViolation} describes; every other
 * key is stripped. Inputs the program reads cannot deliver garbage past the
 * boundary; the rest cannot touch anything at all.
 *
 * What the boundary demands is what the program reads — `$references` — and
 * not the complete declaration record. Declarations type a vocabulary, and a vocabulary
 * is routinely wider than any one program that speaks it: a host compiling
 * every condition on a page against the page's questions gives each program
 * the same declarations, and each runs on the inputs it reads however much of
 * the rest is still unanswered. A declaration this program never reads is
 * ignored whether or not it is bound — reads are settled at compile time, so
 * no evaluation can observe a symbol the compiler did not record.
 *
 * The declaration record keeps key presence separate from value absence.
 * Properties are required by default; {@see Optional} permits omission, while
 * an Option-typed property permits an explicitly absent value. Projection
 * still intersects those requirements with the reads: an input this program
 * does not read is not required or admitted.
 */
final readonly class Program
{
    public Type $returns;

    public CompilationAnalysis $analysis;

    /** @var list<ReferencePath> Declared input paths read by this program. */
    public array $references;

    /**
     * The complete declaration record — what the analysis explains.
     */
    private RecordType $declarations;

    /**
     * The inputs this program reads, in declaration order — the boundary's
     * entire subject. Every other declaration names something the program
     * never mentions, and so has nothing to demand or admit.
     *
     * Projection preserves required/optional qualifiers at every level.
     */
    private RecordType $inputs;

    /**
     * @param RecordType|array<string, Type|Optional> $declarations
     */
    public function __construct(
        private CompiledNode $node,
        RecordType|array $declarations = [],
        private Boundary $boundary = Boundary::Coerce,
    ) {
        // Certification comes first: past it every node has a type to read,
        // and before it the root may be one that has none.
        $compilation = $node->compilation() ?? ($node->failed
            ? CompilationNode::failed(CompiledNode::class)
            : CompilationNode::certified(CompiledNode::class, $node->returns, 'unattributed'));

        self::certify($compilation);

        $record = $declarations instanceof RecordType ? $declarations : new RecordType($declarations);

        $this->returns = $node->returns;
        $this->references = $node->references;
        $this->declarations = $record;
        $this->inputs = $record->project($node->references);
        $this->analysis = CompilationAnalysis::certified($compilation, $this->declarations, $this->boundary);
    }

    /**
     * The one place a program is minted, and so the one place to hold the
     * line: a node error-tolerant compilation gave up on ({@see Diagnosis})
     * is in the {@see CompilationState::Failed} state, and a program carrying
     * one anywhere would present a checked face over an unchecked subtree.
     * The whole tree is answered for, not just the root — a failed match arm
     * is absorbed into the union of its siblings, so a broken node can sit
     * under a perfectly ordinary type.
     *
     * It is the state that is read, never a type: a type is host data this
     * library cannot see inside, and any question asked of one would be a
     * question a host could answer wrongly. The state is the compiler's own
     * record of what it did.
     *
     * The answer costs one boolean read: {@see CompilationNode::$containsFailure}
     * carries it for a whole subtree, so a sound program — every program
     * `compile()` mints — pays for the guard once, not once per node.
     * {@see locateFailure()} runs only to name the offending node, on the
     * path where no program is minted anyway.
     *
     * The root additionally answers for its own state, because one state
     * carries no failure and is still nothing to run: an
     * {@see CompilationState::Abandoned} node is a position a compiler
     * declined to fill, with no type and no evaluation under it. A compiler
     * abandons a child and never a root, so this is not something compilation
     * reaches — it is a caller building a {@see CompiledNode} around a
     * position instead of a compilation. Only the root is asked: an abandoned
     * child is ordinary, and the parent that abandoned it compiled without it.
     */
    private static function certify(CompilationNode $root): void
    {
        if ($root->state === CompilationState::Abandoned) {
            throw new LogicException('The root of this compilation was abandoned: it is a position no compiler filled, with no type and no evaluation, so a Program cannot be certified from it.');
        }

        self::locateFailure($root, '$');
    }

    /**
     * Name the failed node under a root that carries a failure — the first
     * one the walk reaches, in the same `$`-rooted path language the analysis
     * and every refusal use.
     */
    private static function locateFailure(CompilationNode $node, string $path): void
    {
        if (!$node->containsFailure) {
            return;
        }

        if ($node->state === CompilationState::Failed) {
            throw new LogicException(sprintf(
                'The node at [%s] failed to compile; a Program cannot be certified from it. Read Expression::diagnose() for what refused.',
                $path,
            ));
        }

        // A node carrying a failure that is not itself failed has a failed
        // child, so this loop always reaches one and throws.
        foreach ($node->children as $index => $child) {
            self::locateFailure($child->node, CompilationNode::childPath($path, $index));
        }
    }

    /**
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function __invoke(array $bindings = [], ?Observer $observer = null): Result
    {
        return $this->call($bindings, $observer);
    }

    /**
     * Invoke the program with the given bindings. The bindings this program
     * reads pass the boundary first; evaluation trusts everything past it.
     *
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function call(array $bindings = [], ?Observer $observer = null): Result
    {
        $admitted = $this->admit($bindings);

        if ($admitted->isErr()) {
            return Err($admitted->unwrapErr());
        }

        return $this->node->evaluate(new Runtime($admitted->unwrap(), $observer));
    }

    /**
     * The boundary: every binding this program reads passes through its
     * declared type (coerce or assert, per policy), and every other key is
     * stripped — the reads are the program's complete runtime signature, and
     * a declaration nothing reads is neither demanded nor admitted. Callers
     * bind keys exactly as declared — symbol lookup has no other reading.
     *
     * Absence arrives two ways and the declaration answers them independently.
     * {@see Optional} governs an omitted key. The property's type governs a
     * supplied value that admission reads as absent. Thus `Option<T>` still
     * requires its key, while `Optional(T)` permits omission but rejects null.
     *
     * Violations aggregate, named by binding, and are sorted into the kinds
     * {@see BoundaryViolation} describes: a fault dominates absence, so the
     * refusal is a {@see MissingRequiredInput} only when every violation is
     * an input the call did not supply.
     *
     * @param array<string, mixed> $raw
     * @return Result<Bindings, BoundaryViolation>
     */
    private function admit(array $raw): Result
    {
        // Keyed by input: an input is answered for once, so a rejection
        // replaces nothing and the keys are the inputs at fault, in order.
        $rejections = [];
        $overlay = [];
        $fault = false;

        foreach ($this->inputs->properties as $key => $property) {
            $type = $property->type;

            if (!array_key_exists($key, $raw)) {
                if (!$property->optional) {
                    $rejections[$key] = new RejectedBinding($key, sprintf('required input [%s] is missing', $key));
                }

                continue;
            }

            $value = $raw[$key];

            $admitted = match ($this->boundary) {
                Boundary::Coerce => $type->coerce($value),
                Boundary::Assert => $type->assert($value),
            };

            if ($admitted->isErr()) {
                $rejections[$key] = new RejectedBinding($key, sprintf('binding [%s]: %s', $key, $admitted->unwrapErr()->getMessage()));
                $fault = true;

                continue;
            }

            // An absence reading ('' → None) where the declaration requires
            // presence: a value was supplied, and it does not inhabit the
            // type it was declared at. Where the declaration admits absence,
            // None is simply the value, and falls through to the overlay as
            // the null a symbol reads back as absent — including on a
            // demanded input, which was demanded so that this answer could be
            // told apart from no answer, not so that it could be refused.
            if ($admitted->unwrap()->isNone() && !$type->shape() instanceof OptionShape) {
                $rejections[$key] = new RejectedBinding($key, sprintf('binding [%s] reads as missing, but %s is required', $key, TypeDescriber::describe($type)));
                $fault = true;

                continue;
            }

            $overlay[$key] = $admitted->unwrap()->unwrapOr(null);
        }

        if ($rejections !== []) {
            return Err($fault
                ? new InadmissibleBinding(array_values($rejections))
                : new MissingRequiredInput(array_values($rejections)));
        }

        return Ok(new Bindings($overlay));
    }
}
