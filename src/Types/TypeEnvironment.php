<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\LocalScope;
use Superscript\Axiom\OptionLayers;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Runtime;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;
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
                static function (Runtime $runtime) use ($name, $property, $root, $scope) {
                    $binding = $scope === null
                        ? $runtime->bindings->get($name)
                        : $runtime->local($scope, $name);
                    $value = OptionLayers::read(
                        $binding,
                        $property->optional && $property->type->shape() instanceof Shapes\OptionShape,
                    );

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

        $definitionKey = $this->definitionKeyOf($reference);
        $definition = $definitionKey === null
            ? null
            : $this->nodeOfDefinition($definitionKey, $compiler, $path, $reads);

        if ($definition !== null) {
            return $definition;
        }

        $reads?->recordReferences([$reference]);

        return Err(new TypeMismatch(sprintf(
            'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            $reference->describe(),
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
            return null;
        }

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $position = array_search($key, $this->inProgress, strict: true);

        if ($position !== false) {
            $reads?->recordReferences([self::referenceOfKey($key)]);

            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...array_slice($this->inProgress, $position), $key]),
            )));
        }

        $source = $this->definitions->get($key)->unwrap();
        $this->inProgress[] = $key;
        $compiled = $compiler->compile($source, $this, $path, $reads);
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
     * Compile a path rooted in a declared input or lexical parameter as one
     * structural read. Definition-rooted, root-only, and arbitrary paths
     * return null; the reference compiler resolves the root and projects any
     * remaining members.
     *
     * @return ?Result<CompiledNode, TypeMismatch>
     */
    public function nodeOfInputPath(ReferencePath $reference): ?Result
    {
        if ($reference->isRoot() || $this->definitionKeyOf($reference) !== null) {
            return null;
        }

        if (!$this->declarations->has($reference->root())) {
            return $this->parent?->nodeOfInputPath($reference);
        }

        $path = $this->structuralPath($reference);

        if ($path === null) {
            return $this->parent?->nodeOfInputPath($reference);
        }

        $type = $path['type'];
        $properties = $path['properties'];

        $scope = $this->localScope;

        return Ok(CompiledNode::returning(
            $type,
            static function (Runtime $runtime) use ($reference, $scope, $properties) {
                $current = $scope === null
                    ? $runtime->bindings->get($reference->root())
                    : $runtime->local($scope, $reference->root());

                foreach ($properties as ['name' => $name, 'property' => $property]) {
                    $current = self::accessPathProperty($current, $name, $property);
                }

                $runtime->annotate('label', $reference->describe());
                $current->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                return Ok($current);
            },
            references: $scope === null ? [$reference] : [],
        ));
    }

    /**
     * @param  Option<mixed>  $current
     * @return Option<mixed>
     */
    private static function accessPathProperty(Option $current, string $name, RecordProperty $property): Option
    {
        return $current->andThen(static function (mixed $value) use ($name, $property): Option {
            $value = OptionLayers::collapse($value);

            if (is_array($value) && array_key_exists($name, $value)) {
                return OptionLayers::read(
                    new Some($value[$name]),
                    $property->optional && $property->type->shape() instanceof Shapes\OptionShape,
                );
            }

            if (is_object($value) && property_exists($value, $name)) {
                return OptionLayers::read(
                    new Some($value->{$name}),
                    $property->optional && $property->type->shape() instanceof Shapes\OptionShape,
                );
            }

            return Option::from(null);
        });
    }

    /**
     * Resolve only paths structurally owned by concrete RecordTypes.
     *
     * @return ?array{type: Type, properties: list<array{name: string, property: RecordProperty}>}
     */
    private function structuralPath(ReferencePath $reference): ?array
    {
        $current = $this->declarations;
        $lifted = false;
        $properties = [];

        foreach ($reference->segments as $index => $name) {
            while ($current instanceof OptionType) {
                $lifted = true;
                $current = $current->inner;
            }

            if (!$current instanceof RecordType || ($property = $current->property($name)) === null) {
                return null;
            }

            if ($index > 0) {
                $properties[] = ['name' => $name, 'property' => $property];
            }
            $current = $property->accessedType();

            if ($lifted && !$current->shape() instanceof \Superscript\Axiom\Types\Shapes\OptionShape) {
                $current = new OptionType($current);
            }
        }

        return ['type' => $current, 'properties' => $properties];
    }

    private static function referenceOfKey(string $key): ReferencePath
    {
        $segments = explode('.', $key);

        return new ReferencePath($segments[0], ...array_slice($segments, 1));
    }

}
