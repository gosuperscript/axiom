<?php

declare(strict_types=1);

namespace Superscript\Abacus;

use Superscript\Monads\Option\Option;

use function Psl\Type\dict;
use function Psl\Type\instance_of;
use function Psl\Type\string;

final readonly class SymbolRegistry
{
    public function __construct(
        /** @var array<string, Source> */
        public array $symbols = [],
    ) {
        dict(string(), instance_of(Source::class))->assert($this->symbols);
    }

    /**
     * @return Option<Source>
     */
    public function get(string $name): Option
    {
        return Option::from($this->symbols[$name] ?? null);
    }
}
