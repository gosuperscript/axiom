<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use LogicException;
use Superscript\Axiom\Execution\Annotated;
use Superscript\Axiom\Execution\Entered;
use Superscript\Axiom\Execution\Exited;
use Superscript\Axiom\Execution\Node;
use Superscript\Axiom\Execution\Observer;
use Superscript\Axiom\Execution\Threw;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * The per-invocation state of a compiled {@see Program}: the admitted
 * bindings, lexically scoped local bindings, lazily-evaluated definition
 * slots, and an optional execution observer. It carries no dialect and no
 * resolver — a compiled program has nothing left to dispatch.
 */
final class Runtime
{
    /** @var array<string, Result<Option<mixed>, Throwable>> */
    private array $slots = [];

    /** @var list<Node> */
    private array $nodes = [];

    /** @var list<array{LocalScope, Bindings}> */
    private array $localBindings = [];

    public function __construct(
        public readonly Bindings $bindings = new Bindings(),
        public readonly ?Observer $observer = null,
    ) {}

    /**
     * Evaluate one compiled node inside an ordered observation scope.
     *
     * @internal
     * @param Closure(): Node $describe
     * @param Closure(): Result<Option<mixed>, Throwable> $evaluation
     * @return Result<Option<mixed>, Throwable>
     */
    public function evaluate(Closure $describe, Closure $evaluation): Result
    {
        if ($this->observer === null) {
            return $evaluation();
        }

        $node = $describe();
        $this->nodes[] = $node;

        try {
            $this->observer->observe(new Entered($node));

            try {
                $result = $evaluation();
            } catch (Throwable $exception) {
                $this->observer->observe(new Threw($node, $exception));

                throw $exception;
            }

            $this->observer->observe(new Exited($node, $result));

            return $result;
        } finally {
            array_pop($this->nodes);
        }
    }

    public function annotate(string $key, mixed $value): void
    {
        if ($this->observer === null) {
            return;
        }

        $index = array_key_last($this->nodes);

        if ($index === null) {
            throw new LogicException('Execution annotations belong to the compiled node currently being evaluated.');
        }

        $this->observer->observe(new Annotated($this->nodes[$index], $key, $value));
    }

    /**
     * Evaluate inside one lexical scope without replacing the enclosing
     * program's bindings, definition slots, or observation stack.
     *
     * @internal Compiled scoped expressions own local evaluation.
     * @param Closure(): Result<Option<mixed>, Throwable> $evaluate
     * @return Result<Option<mixed>, Throwable>
     */
    public function within(LocalScope $scope, Bindings $bindings, Closure $evaluate): Result
    {
        $this->localBindings[] = [$scope, $bindings];

        try {
            return $evaluate();
        } finally {
            array_pop($this->localBindings);
        }
    }

    /**
     * @internal Resolve a symbol compiled against one exact lexical scope.
     * @return Option<mixed>
     */
    public function local(LocalScope $scope, string $name): Option
    {
        for ($index = count($this->localBindings) - 1; $index >= 0; $index--) {
            [$candidate, $bindings] = $this->localBindings[$index];

            if ($candidate === $scope) {
                return $bindings->get($name);
            }
        }

        throw new LogicException('A scoped expression can only be evaluated while its local bindings are active.');
    }

    /**
     * A definition evaluates lazily and at most once per invocation; every
     * later reference reads the memoized result.
     *
     * @param Closure(): Result<Option<mixed>, Throwable> $evaluate
     * @return Result<Option<mixed>, Throwable>
     */
    public function slot(string $key, Closure $evaluate): Result
    {
        if (array_key_exists($key, $this->slots)) {
            $this->annotate('memo', 'hit');

            return $this->slots[$key];
        }

        $this->annotate('memo', 'miss');

        return $this->slots[$key] = $evaluate();
    }
}
