<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Runtime;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The symbol table for one compilation. Root symbols are either properties
 * of the declared input record or definitions; the root symbol sets are disjoint.
 * Structural input member chains resolve as {@see ReferencePath}s so the
 * compiled program can project the smallest honest input record.
 */
final class TypeEnvironment
{
    /** @var array<string, Result<CompiledNode, TypeMismatch>> */
    private array $memo = [];

    /** @var list<string> */
    private array $inProgress = [];

    private readonly RecordType $declarations;

    /** @param RecordType|array<string, Type|Optional> $declarations */
    public function __construct(
        private readonly Definitions $definitions = new Definitions(),
        RecordType|array $declarations = [],
    ) {
        $this->declarations = $declarations instanceof RecordType ? $declarations : new RecordType($declarations);
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    public function nodeOfSymbol(string $name, TypeInference $compiler, string $path = '$', ?CompilationRecorder $reads = null): Result
    {
        $property = $this->declarations->property($name);

        if ($property !== null) {
            $reference = new ReferencePath($name);

            return Ok(CompiledNode::returning(
                $property->accessedType(),
                static function (Runtime $runtime) use ($name) {
                    $value = $runtime->bindings->get($name)->andThen(static fn(mixed $item) => Option::from($item));

                    $runtime->annotate('label', $name);
                    $value->inspect(fn(mixed $item) => $runtime->annotate('result', $item));

                    return Ok($value);
                },
                references: [$reference],
            ));
        }

        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        if (in_array($name, $this->inProgress, strict: true)) {
            return Err(new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...$this->inProgress, $name]),
            )));
        }

        $source = $this->definitions->get($name);

        if ($source->isNone()) {
            $reads?->recordReferences([new ReferencePath($name)]);

            return Err(new TypeMismatch(sprintf(
                'Unbound symbol [%s]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
                $name,
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
     * Compile a member chain rooted in a declared input as one structural
     * read. Definition-rooted and arbitrary member access return null and use
     * the ordinary member-access compiler instead.
     *
     * @return ?Result<CompiledNode, TypeMismatch>
     */
    public function nodeOfInputPath(ReferencePath $reference): ?Result
    {
        if ($this->definitions->has($reference->root())) {
            return null;
        }

        $type = $this->structuralTypeAt($reference);

        if ($type === null) {
            return null;
        }

        return Ok(CompiledNode::returning(
            $type,
            static function (Runtime $runtime) use ($reference) {
                $current = $runtime->bindings->get($reference->root());

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
            references: [$reference],
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
