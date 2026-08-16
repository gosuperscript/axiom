<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

/**
 * Both faces of compiling an {@see Expression}: certify it, or find out
 * everything wrong with it. One walk answers both.
 *
 * ## Why there are attempts
 *
 * The compiler stops at the first refusal, and the refusal it reports is
 * built by unwinding: the node that refused stamps its path, and every
 * ancestor that added context (`SourceCompilation::within()`) wraps the
 * cause on the way out. That unwinding is what makes the message good — and
 * it is also what discards the rest of the tree. So this class does not try
 * to keep compiling past a failure. It compiles again.
 *
 * Each attempt is an ordinary compilation. When one refuses, the node it
 * names — the deepest path in the refusal's cause chain, the node that
 * actually made it — is **quarantined**: the next attempt hands that node
 * back as a failed node without visiting it, and so gets past it to whatever
 * else is wrong.
 *
 * ## What the retry does *not* do: report twice
 *
 * A failed node absorbs, and absorption is total: every judgment about a
 * child's type is made through {@see \Superscript\Axiom\SourceCompilation},
 * which takes it only over a child that compiled. An operation over a failed
 * operand is never bound, an ascription over one claims nothing, a member
 * access on one certifies nothing — so `mystery > 1000 && postcode == 'SW1'`
 * costs exactly one diagnostic, the unbound `mystery`, while the right-hand
 * comparison is still fully checked and `postcode` is still collected as a
 * reference. One fault, one diagnostic — see {@see Diagnosis} for what that
 * costs.
 *
 * A refusal an attempt meets after quarantining something is therefore a
 * fault of its own, not an echo of the one below it, and it is recorded.
 * The single exception is a refusal whose deepest path is *already*
 * quarantined: a quarantined node is never visited and so cannot refuse, so
 * such a refusal is one a compiler kept from an earlier attempt, and it was
 * reported when it was first met.
 *
 * That is also the termination argument. Every attempt that refuses
 * quarantines either a node no earlier attempt had, or — in the exception
 * above — the root; the root is not yet quarantined whenever an attempt
 * refuses, because a quarantined root compiles to a failed node without being
 * visited and the attempt would have succeeded. Attempts are therefore
 * bounded by the number of nodes, and the last one succeeds.
 *
 * ## Definition cycles
 *
 * A cycle refuses like any other fault: the environment's descent carries
 * the chain of definitions it is inside and refuses when a name reappears
 * on it ({@see TypeEnvironment}), so the refusal is located at the
 * reference that closed the cycle and quarantined like any other failed
 * node. Two references that each close a cycle are two faults, exactly as
 * two references to one unbound name are.
 */
final readonly class RecoveringCompiler
{
    public function __construct(private Expression $expression) {}

    /**
     * One attempt, certified. The refusal, if any, is the first diagnostic
     * {@see diagnose()} would collect.
     *
     * There is no recovery to carry, so none is built: compilation runs
     * without the quarantine check at every node, and nothing keeps what the
     * attempt read. An empty recovery would answer no to every question it is
     * asked, so this is the same compilation attempt diagnose() makes first —
     * the same refusal, the same message, the same path — at the cost of the
     * walk alone.
     *
     * @return Result<Program, TypeMismatch>
     */
    public function compile(): Result
    {
        return $this->attempt(null)->map(fn(CompiledNode $node) => $this->program($node));
    }

    /** Attempts until one succeeds, collecting what each refused. */
    public function diagnose(): Diagnosis
    {
        $diagnostics = [];
        $recovery = new ErrorRecovery();

        while (true) {
            $attempt = $this->attempt($recovery);

            if ($attempt->isOk()) {
                $root = $attempt->unwrap();

                // A root the compiler gave up on has no type at all, so the
                // diagnosis reports the absence.
                if ($diagnostics === []) {
                    return Diagnosis::certified($this->program($root));
                }

                return Diagnosis::refused(
                    $diagnostics,
                    $recovery->references(),
                    $root->failed ? null : $root->returns,
                );
            }

            $refusal = $attempt->unwrapErr();
            $failedPath = $refusal->deepestPath();

            // Every refusal an attempt returns is located: TypeInference
            // stamps the compiling node's path onto whatever refused, and an
            // already-located refusal keeps its own.
            assert($failedPath !== null);

            if ($recovery->isQuarantined($failedPath)) {
                // A quarantined node is never visited, so it cannot have
                // refused: this is a refusal about it kept by a compiler
                // that met it earlier, and it was reported then. Set the
                // root aside so the next attempt ends.
                $recovery->quarantine('$');

                continue;
            }

            $diagnostics[] = $refusal;
            $recovery->quarantine($failedPath);
        }
    }

    /**
     * @param ?ErrorRecovery $recovery Null compiles with no recovery state at
     *                                 all, which is what certification needs.
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function attempt(?ErrorRecovery $recovery): Result
    {
        // Reads are collected for the recovery to accumulate across attempts.
        // Certification has no recovery and so nothing to hand them to, and it
        // threads no recorder rather than filling one it would discard.
        if ($recovery === null) {
            return $this->walk(null, null);
        }

        // Where the attempt's reads end up. Every node hands its reads to its
        // parent — as it finishes, or as it refuses — so the root's recorder
        // holds the whole attempt, in the order the names were read.
        $reads = new CompilationRecorder();
        $compiled = $this->walk($recovery, $reads);

        $compiled->inspect(static fn(CompiledNode $node) => $reads->recordReferences($node->references));
        $recovery->record($reads->references());

        return $compiled;
    }

    /**
     * One ordinary compilation of the expression, quarantining what $recovery
     * has set aside and handing every name it reads to $reads.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function walk(?ErrorRecovery $recovery, ?CompilationRecorder $reads): Result
    {
        $dialect = $this->expression->dialect;

        return new TypeInference(
            $dialect->operators(),
            $dialect->unaryOperators(),
            $dialect->literals(),
            $dialect->sourceCompilers(),
            $dialect->sourceCompilerExtensions(),
            $dialect->opaqueFields(),
            $recovery,
        )->compile(
            $this->expression->source,
            new TypeEnvironment($this->expression->definitions, $this->expression->declarations),
            '$',
            $reads,
        );
    }

    private function program(CompiledNode $node): Program
    {
        return new Program($node, $this->expression->declarations, $this->expression->boundary);
    }
}
