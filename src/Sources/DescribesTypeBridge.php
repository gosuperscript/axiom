<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/** Shared rendering for the two explicit type-bridge nodes. */
trait DescribesTypeBridge
{
    private function describeSource(Source $source): string
    {
        return $source instanceof Describable
            ? $source->describe()
            : (new \ReflectionClass($source))->getShortName();
    }

    private function describeType(Type $type): string
    {
        $shortName = (new \ReflectionClass($type))->getShortName();

        return lcfirst(str_ends_with($shortName, 'Type') ? substr($shortName, 0, -4) : $shortName);
    }
}
