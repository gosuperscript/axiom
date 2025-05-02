<?php

namespace Superscript\Abacus;

use Superscript\Monads\Option\Option;
use Webmozart\Assert\Assert;

class SymbolRegistry
{
    public function __construct(
        /**
         * @var array<string, Source>
         */
        public array $symbols = [],
    ) {
        Assert::allString(array_keys($this->symbols));
        Assert::allIsInstanceOf($this->symbols, Source::class);
    }

    /**
     * @return Option<Source>
     */
    public function get(string $name): Option
    {
        return Option::from($this->symbols[$name] ?? null);
    }
}