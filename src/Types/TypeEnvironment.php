<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\LocalScope;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Runtime;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The symbol table for one compilation. The root environment holds the
 * disjoint declared inputs and definitions; nested environments add lexical
 * declarations that shadow equal outer names. Structural public input paths
 * resolve as {@see ReferencePath}s so the compiled program can project
 * the smallest honest input record. Local reads carry an opaque scope identity
 * instead and never masquerade as public input references.
 */
final class TypeEnvironment
{
    /** @var array<string, Result<CompiledNode, TypeMismatch>> */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    private readonly RecordType $declarations;

    private ?self $parent = null;

    private ?LocalScope $localScope = null;

    /** @param RecordType|array<string, Type|Optional> $declarations */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        RecordType|array $declarations = [],
    ) {
        $this->declarations = $declarations instanceof RecordType ? $declarations : new RecordType($declarations);
    }

    /**
     * Add declarations that shadow this environment while every other symbol
     * continues to resolve lexically through it.
     *
     * @internal Scoped expression compilation owns lexical environments.
     * @param RecordType|array<string, Type|Optional> $declarations
     */
    public function nested(LocalScope $scope, RecordType|array $declarations): self
    {
        $nested = new self(declarations: $declarations);
        $nested->parent = $this;
        $nested->localScope = $scope;

        return $nested;
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    public function nodeOfSymbol(string $name, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): Result
    {
        $property = $this->declarations->property($name);

        if ($property !== null) {
            $reference = new ReferencePath($name);
            $scope = $this->localScope;

            return Ok(CompiledNode::returning(
                $property->accessedType(),
                static function (Runtime $runtime) use ($name, $scope) {
                    $binding = $scope === null
                        ? $runtime->bindings->get($name)
                        : $runtime->local($scope, $name);
                    $value = $binding->andThen(static fn(mixed $item) => Option::from($item));

                    $runtime->annotate('label', $name);
                    $value->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                    return Ok($value);
                },
                references: $scope === null ? [$reference] : [],
            ));
        }

        $definition = $this->nodeOfDefinition($name, $compiler, $path, $reads);

        if ($definition !== null) {
            return $definition;
        }

        if ($this->parent !== null) {
            return $this->parent->nodeOfSymbol($name, $compiler, $path, $reads);
        }

        $reads?->recordReferences([new ReferencePath($name)]);

        return Err(new TypeMismatch(sprintf(
            'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            $name,
        )));
    }

    /**
     * The longest definition prefix consumed by a structural reference.
     * A declared root wins when callers construct an environment directly
     * with the otherwise-forbidden declaration/definition collision.
     */
    public function definitionKeyOf(ReferencePath $reference): ?string
    {
        if ($this->declarations->has($reference->root())) {
            return null;
        }

        if ($this->parent !== null) {
            return $this->parent->definitionKeyOf($reference);
        }

        return $this->definitions->keyOf($reference);
    }

    /** @return ?Result<CompiledNode, TypeMismatch> */
    public function nodeOfDefinition(string $key, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): ?Result
    {
        if (!$this->definitions->has($key)) {
            return $this->parent?->nodeOfDefinition($key, $compiler, $path, $reads);
        }
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (in_array($key, $this->inProgress, strict: true)) {
            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...$this->inProgress, $key]),
            )));
        }

        $source = $this->definitions->get($key);

        $this->inProgress[] = $key;
        $compiled = $compiler->compile($source->unwrap(), $this, $path, $reads);
        array_pop($this->inProgress);

        $this->memo[$key] = $compiled->map(fn(CompiledNode $node): CompiledNode => $node->evaluatedBy(
            static function (Runtime $runtime) use ($node, $key) {
                $result = $runtime->slot($key, fn() => $node->evaluate($runtime));

                $runtime->annotate('label', $key);
                $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                return $result;
            },
        ));

        return $this->memo[$key];
    }

    /**
     * Compile a path rooted in a declared input as one structural read.
     * Definition-rooted, root-only, and arbitrary paths return null; the
     * reference compiler resolves the root and projects any remaining members.
     *
     * @return ?Result<CompiledNode, TypeMismatch>
     */
    public function nodeOfInputPath(ReferencePath $reference): ?Result
    {
        if ($this->declarations->property($reference->root()) === null && $this->parent !== null) {
            return $this->parent->nodeOfInputPath($reference);
        }

        if ($reference->isRoot() || !$this->declarations->has($reference->root())) {
            return null;
        }

        $type = $this->structuralTypeAt($reference);

        if ($type === null) {
            return null;
        }

        $scope = $this->localScope;

        return Ok(CompiledNode::returning(
            $type,
            static function (Runtime $runtime) use ($reference, $scope) {
                $current = $scope === null
                    ? $runtime->bindings->get($reference->root())
                    : $runtime->local($scope, $reference->root());

                foreach ($reference->properties() as $property) {
                    $current = $current->andThen(static function (mixed $value) use ($property): Option {
                        if (is_array($value) && array_key_exists($property, $value)) {
                            return self::optionFrom($value[$property]);
                        }

                        if (is_object($value) && property_exists($value, $property)) {
                            return self::optionFrom($value->{$property});
                        }

                        // Concrete RecordType admission has already rejected
                        // every missing required property. Any key absent here
                        // is therefore an optional property, observed as None.
                        return Option::from(null);
                    });
                }

                $runtime->annotate('label', $reference->describe());
                $current->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                return Ok($current);
            },
            references: $scope === null ? [$reference] : [],
        ));
    }

    /** Resolve only paths structurally owned by concrete RecordTypes. */
    private function structuralTypeAt(ReferencePath $reference): ?Type
    {
        $current = $this->declarations;
        $lifted = false;

        foreach ($reference->segments as $name) {
            while ($current instanceof OptionType) {
                $lifted = true;
                $current = $current->inner;
            }

            if (!$current instanceof RecordType || ($property = $current->property($name)) === null) {
                return null;
            }

            $current = $property->accessedType();

            if ($lifted && !$current->shape() instanceof \Superscript\Axiom\Types\Shapes\OptionShape) {
                $current = new OptionType($current);
            }
        }

        return $current;
    }

    /** @return Option<mixed> */
    private static function optionFrom(mixed $value): Option
    {
        return Option::from($value);
    }
}
