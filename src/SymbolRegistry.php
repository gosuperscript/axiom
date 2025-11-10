<?php

declare(strict_types=1);

namespace Superscript\Schema;

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
    public function get(string $name, ?string $namespace = null): Option
    {
        // When namespace is provided, look for it with format "namespace.name"
        if ($namespace !== null) {
            $namespacedKey = $namespace . '.' . $name;
            return Option::from($this->symbols[$namespacedKey] ?? null);
        }

        // When namespace is null, first try exact name match,
        // then fall back to checking if there's a global namespace symbol
        return Option::from($this->symbols[$name] ?? null);
    }
}
