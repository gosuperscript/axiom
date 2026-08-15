<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Analysis\CompilationAnalysis;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Execution\Observer;
use Superscript\Axiom\Analysis\CompilationState;
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
 * runtime type check that survives compilation, by design: every declared
 * binding passes through its declared type (coerce by default, assert for
 * strict hosts) before evaluation, with violations aggregated and named;
 * every undeclared binding key is stripped. Declared inputs cannot deliver
 * garbage past the boundary; undeclared inputs cannot touch anything at all.
 */
final readonly class Program
{
    public Type $returns;

    public CompilationAnalysis $analysis;

    /** @var list<string> Declared inputs read by this compiled program. */
    public array $references;

    /**
     * Required-ness per declaration, fixed at compile time. It is a
     * property of the projection, not the concrete class:
     * Union(Option<Number>, String) has shape (Number | String)? and a
     * missing binding is legal absence.
     *
     * @var array<string, bool>
     */
    private array $optional;

    /**
     * @param array<string, Type> $declarations
     */
    public function __construct(
        private CompiledNode $node,
        private array $declarations = [],
        private Boundary $boundary = Boundary::Coerce,
    ) {
        // Certification comes first: past it every node has a type to read,
        // and before it the root may be one that has none.
        $compilation = $node->compilation() ?? ($node->failed
            ? CompilationNode::failed(CompiledNode::class)
            : CompilationNode::certified(CompiledNode::class, $node->returns, 'unattributed'));

        self::certify($compilation);

        $this->returns = $node->returns;
        $this->references = $node->references;
        $this->optional = array_map(fn(Type $type) => $type->shape() instanceof OptionShape, $this->declarations);
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
     * Invoke the program with the given bindings. Declared bindings pass
     * the boundary first; evaluation trusts everything past it.
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
     * The boundary: every declared binding passes through its declared type
     * (coerce or assert, per policy), and every undeclared key is stripped —
     * the declaration list is the program's complete public signature.
     * Violations aggregate, named by binding. Callers bind keys exactly as
     * declared — symbol lookup has no other reading.
     *
     * @param array<string, mixed> $raw
     * @return Result<Bindings, BoundaryViolation>
     */
    private function admit(array $raw): Result
    {
        $violations = [];
        $overlay = [];

        foreach ($this->declarations as $key => $type) {
            if (!array_key_exists($key, $raw)) {
                if (!$this->optional[$key]) {
                    $violations[] = sprintf('required input [%s] is missing', $key);
                }

                continue;
            }

            $value = $raw[$key];

            $admitted = match ($this->boundary) {
                Boundary::Coerce => $type->coerce($value),
                Boundary::Assert => $type->assert($value),
            };

            if ($admitted->isErr()) {
                $violations[] = sprintf('binding [%s]: %s', $key, $admitted->unwrapErr()->getMessage());

                continue;
            }

            // An absence reading ('' → None). OptionType never produces one —
            // it wraps absence as a present null — so this is always a
            // required input that dissolved at the boundary.
            if ($admitted->unwrap()->isNone()) {
                $violations[] = sprintf('binding [%s] reads as missing, but %s is required', $key, TypeDescriber::describe($type));

                continue;
            }

            $overlay[$key] = $admitted->unwrap()->unwrapOr(null);
        }

        if ($violations !== []) {
            return Err(new BoundaryViolation($violations));
        }

        return Ok(new Bindings($overlay));
    }
}
