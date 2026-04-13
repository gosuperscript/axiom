<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;

final readonly class SymbolSource implements Source
{
    public function __construct(
        public string $name,
        public ?string $namespace = null,
    ) {}

    public function type(Context $context): Type
    {
        // 1. Declared parameter type (the Expression's "signature").
        $declared = $context->bindings->typeOf($this->name, $this->namespace);
        if ($declared !== null) {
            return $declared;
        }

        // 2. Named definition — recurse into its source's type.
        $definition = $context->definitions->get($this->name, $this->namespace);
        if ($definition->isSome()) {
            return $definition->unwrap()->type($context);
        }

        $key = $this->namespace !== null ? "{$this->namespace}.{$this->name}" : $this->name;

        return new UnresolvedType("unknown symbol '{$key}'");
    }
}
