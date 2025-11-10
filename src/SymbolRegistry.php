<?php

declare(strict_types=1);

namespace Superscript\Schema;

use Superscript\Monads\Option\Option;

use function Psl\Type\dict;
use function Psl\Type\instance_of;
use function Psl\Type\nullable;
use function Psl\Type\shape;
use function Psl\Type\string;
use function Psl\Type\vec;

final readonly class SymbolRegistry
{
    /** @var array<string, Source> */
    private array $symbols;

    /**
     * @param array<array{name: string, namespace: ?string, source: Source}> $symbols
     */
    public function __construct(array $symbols = [])
    {
        // Validate the structure of the input
        vec(shape([
            'name' => string(),
            'namespace' => nullable(string()),
            'source' => instance_of(Source::class),
        ]))->assert($symbols);

        // Transform the array into internal storage format
        $internalSymbols = [];
        foreach ($symbols as $symbol) {
            $key = $symbol['namespace'] !== null
                ? $symbol['namespace'] . '.' . $symbol['name']
                : $symbol['name'];
            $internalSymbols[$key] = $symbol['source'];
        }

        $this->symbols = $internalSymbols;
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
