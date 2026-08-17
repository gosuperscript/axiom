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
 * The symbol table for one compilation. Root symbols are properties of the
 * declared input record, lexical parameters, or definitions. Structural
 * input paths stay typed as {@see ReferencePath}s so the compiled program
 * can project the smallest honest input record.
 */
final class TypeEnvironment
{
    /** @var array<string, Result<CompiledNode, TypeMismatch>> */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    private ?self $parent = null;

    private ?LocalScope $localScope = null;

    private readonly RecordType $declarations;

    /** @param RecordType|array<string, Type|Optional> $declarations */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        RecordType|array $declarations = [],
    ) {
        $this->declarations = $declarations instanceof RecordType ? $declarations : new RecordType($declarations);
    }

    /**
     * @internal Scoped expression compilation owns lexical environments.
     * @param array<string, Type> $declarations
     */
    public function nested(LocalScope $scope, array $declarations): self
    {
        $nested = new self(declarations: $declarations);
        $nested->parent = $this;
        $nested->localScope = $scope;

        return $nested;
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    public function nodeOfSymbol(string $name, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): Result
    {
        return $this->nodeOfReference(new ReferencePath($name), $compiler, $path, $reads);
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    public function nodeOfReference(ReferencePath $reference, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): Result
    {
        $name = $reference->root();
        $property = $this->declarations->property($name);

        if ($property !== null) {
            $scope = $this->localScope;
            $root = new ReferencePath($name);

            return Ok(CompiledNode::returning(
                $property->accessedType(),
                static function (Runtime $runtime) use ($name, $root, $scope) {
                    $binding = $scope === null
                        ? $runtime->bindings->get($name)
                        : $runtime->local($scope, $name);
                    $value = $binding->andThen(static fn(mixed $item) => Option::from($item));

                    $runtime->annotate('label', $root->describe());
                    $value->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                    return Ok($value);
                },
                references: $scope === null ? [$root] : [],
            ));
        }

        if ($this->parent !== null) {
            return $this->parent->nodeOfReference($reference, $compiler, $path, $reads);
        }

        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        $position = array_search($name, $this->inProgress, strict: true);

        if ($position !== false) {
            $reads?->recordReferences([new ReferencePath($name)]);

            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...array_slice($this->inProgress, $position), $name]),
            )));
        }

        $source = $this->definitions->get($name);

        if ($source->isNone()) {
            $reads?->recordReferences([$reference]);

            return Err(new TypeMismatch(sprintf(
                'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
                $reference->describe(),
            )));
        }

        $this->inProgress[] = $name;
        $compiled = $compiler->compile($source->unwrap(), $this, $path, $reads);
        array_pop($this->inProgress);

        $this->memo[$name] = $compiled->map(fn(CompiledNode $node): CompiledNode => $node->evaluatedBy(
            static function (Runtime $runtime) use ($node, $name) {
                $result = $runtime->slot($name, fn() => $node->evaluate($runtime));

                $runtime->annotate('label', $name);
                $result->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                return $result;
            },
        ));

        return $this->memo[$name];
    }

    /**
     * Compile a path rooted in a declared input or lexical parameter as one
     * structural read. Definition-rooted, root-only, and arbitrary paths
     * return null; the
     * reference compiler resolves the root and projects any remaining members.
     *
     * @return ?Result<CompiledNode, TypeMismatch>
     */
    public function nodeOfInputPath(ReferencePath $reference): ?Result
    {
        if ($reference->isRoot() || $this->definitions->has($reference->root())) {
            return null;
        }

        $type = $this->structuralTypeAt($reference);

        if ($type === null) {
            return $this->parent?->nodeOfInputPath($reference);
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
