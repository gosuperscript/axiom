<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\SymbolSource;

/** @internal Compiler for the core member-access source. */
final readonly class MemberAccessSourceCompiler
{
    public static function compile(MemberAccessSource $source, SourceCompilation $compilation): CompiledSource
    {
        $reference = self::referencePath($source);

        if ($reference !== null) {
            return $compilation->reference($reference);
        }

        $object = $compilation->child($source->object, 'object');

        return $compilation->member($object, $source->property);
    }

    /** A legacy member chain rooted directly in a deprecated symbol source. */
    private static function referencePath(MemberAccessSource $source): ?ReferencePath
    {
        $properties = [];
        $current = $source;

        while ($current instanceof MemberAccessSource) {
            array_unshift($properties, $current->property);
            $current = $current->object;
        }

        if (!$current instanceof SymbolSource) {
            return null;
        }

        $reference = $current->reference();

        foreach ($properties as $property) {
            $reference = $reference->append($property);
        }

        return $reference;
    }
}
