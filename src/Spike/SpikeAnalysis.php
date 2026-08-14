<?php

declare(strict_types=1);

namespace Superscript\Axiom\Spike;

use Superscript\Axiom\Boundary;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Program;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * SPIKE ONLY. The whole result of one error-tolerant walk.
 *
 * The root node it holds is the *same* node a clean strict compile would
 * have produced — the tolerant walk delegates to the identical source
 * compilers and operator resolvers and only interposes at the seams where
 * the strict walk would have aborted. So program() certifies by handing
 * that node to Program directly; there is no second walk.
 *
 * @phpstan-type Diagnostics list<SpikeDiagnostic>
 */
final readonly class SpikeAnalysis
{
    /**
     * @param list<SpikeDiagnostic> $diagnostics
     * @param list<string> $references
     * @param array<string, string> $types path => described type, including failed nodes
     * @param array<string, Type> $declarations
     */
    public function __construct(
        public array $diagnostics,
        public array $references,
        public Type $rootType,
        public array $types,
        private CompiledNode $root,
        private array $declarations,
        private Boundary $boundary,
    ) {}

    /**
     * Ok IFF the walk accumulated nothing. Because every ErrorType is minted
     * with a diagnostic, this is exactly "no ErrorType anywhere in the tree".
     *
     * @return Result<Program, list<SpikeDiagnostic>>
     */
    public function program(): Result
    {
        if ($this->diagnostics !== []) {
            return Err($this->diagnostics);
        }

        return Ok(new Program($this->root, $this->declarations, $this->boundary));
    }

    /** Does any node in the tree carry ErrorType? The soundness invariant's left-hand side. */
    public function hasErrorType(): bool
    {
        return in_array(SpikeTypes::ErrorLabel, $this->types, true);
    }
}
