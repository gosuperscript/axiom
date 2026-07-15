<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Monads\Option\Option;

use function Psl\Type\dict;
use function Psl\Type\instance_of;
use function Psl\Type\string;

/**
 * A map of stable, named expressions (e.g. PI, math.pi, named sub-expressions).
 *
 * Definitions are long-lived and shared across calls. For per-call inputs,
 * use {@see Bindings} instead.
 */
final readonly class Definitions
{
    /** @var array<string, Source> */
    private array $entries;

    /**
     * @param array<string, Source|array<string, Source>> $definitions
     */
    public function __construct(array $definitions = [])
    {
        $entries = [];

        foreach ($definitions as $key => $value) {
            if ($value instanceof Source) {
                $entries[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                dict(string(), instance_of(Source::class))->assert($value);

                foreach ($value as $name => $source) {
                    $entries[SymbolSource::key($name, $key)] = $source;
                }

                continue;
            }

            throw new \InvalidArgumentException(
                'Definition values must be either Source instances or arrays of Sources',
            );
        }

        $this->entries = $entries;
    }

    public function has(string $name, ?string $namespace = null): bool
    {
        return array_key_exists(SymbolSource::key($name, $namespace), $this->entries);
    }

    /**
     * Every defined symbol, as flat dotted keys — the node set of the
     * definition graph.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @return Option<Source>
     */
    public function get(string $name, ?string $namespace = null): Option
    {
        return Option::from($this->entries[SymbolSource::key($name, $namespace)] ?? null);
    }
}
