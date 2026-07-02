<?php

declare(strict_types=1);

namespace Superscript\Axiom\Serialization;

use InvalidArgumentException;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Json\encode;
use function Superscript\Monads\Option\None;

/**
 * Orchestrates the cross-system envelope: {v, type, value}. The type rides as
 * a closed, structural generics DSL string (Tag<arg, …>); the value as the
 * type's own lossless encoding, null for None. All grammar and delimiter
 * syntax lives here — types only expose tag() / toArgs() / encode() / decode().
 *
 * Everything that can go wrong with wire data is an Err; only boot-time
 * registration errors throw.
 */
final readonly class Codec
{
    public const int VERSION = 1;

    private const int MAX_DEPTH = 32;

    private TypeRegistry $registry;

    public function __construct(?TypeRegistry $registry = null)
    {
        $this->registry = $registry ?? TypeRegistry::default();
    }

    /**
     * @param TypedValue<mixed> $typedValue
     */
    public function serialize(TypedValue $typedValue): string
    {
        return encode([
            'v' => self::VERSION,
            'type' => $this->typeToString($typedValue->type),
            'value' => $typedValue->value->mapOr(null, fn(mixed $value) => $typedValue->type->encode($value)),
        ]);
    }

    /**
     * @return Result<TypedValue<mixed>, Throwable>
     */
    public function deserialize(string $payload): Result
    {
        if (!json_validate($payload)) {
            return new Err(new CodecException('Payload is not valid JSON'));
        }

        $document = json_decode($payload, associative: true);

        if (!is_array($document) || !array_key_exists('type', $document) || !is_string($document['type'])) {
            return new Err(new CodecException('Payload must be an object with a string [type] field'));
        }

        $version = $document['v'] ?? self::VERSION;

        if (!is_int($version)) {
            return new Err(new CodecException('Version [v] must be an integer'));
        }

        if ($version > self::VERSION) {
            return new Err(new CodecException("Payload version [$version] is newer than supported version [" . self::VERSION . ']'));
        }

        return $this->parseType($document['type'])->andThen(function (Type $type) use ($document) {
            $value = $document['value'] ?? null;

            if ($value === null) {
                return new Ok(new TypedValue($type, None()));
            }

            return $type->decode($value)
                ->andThen(fn(mixed $decoded) => $type->assert($decoded))
                ->map(fn(Option $asserted) => new TypedValue($type, $asserted));
        });
    }

    private function typeToString(Type $type): string
    {
        $args = array_map(
            fn(Type|int|float|bool $arg) => $arg instanceof Type ? $this->typeToString($arg) : var_export($arg, true),
            $type->toArgs(),
        );

        return $type::tag() . ($args === [] ? '' : '<' . implode(',', $args) . '>');
    }

    /**
     * @return Result<Type, Throwable>
     */
    private function parseType(string $dsl): Result
    {
        try {
            $position = 0;
            $type = $this->parseNode($dsl, $position, depth: 0);

            if ($position !== strlen($dsl)) {
                throw new CodecException("Unexpected trailing characters at position $position in type [$dsl]");
            }

            return new Ok($type);
        } catch (CodecException|InvalidArgumentException $e) {
            return new Err($e);
        }
    }

    private function parseNode(string $dsl, int &$position, int $depth): Type
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CodecException('Type string exceeds maximum nesting depth of ' . self::MAX_DEPTH);
        }

        if (!preg_match('/[a-z][a-zA-Z0-9_]*/A', $dsl, $matches, offset: $position)) {
            throw new CodecException("Expected a type tag at position $position in type [$dsl]");
        }

        $tag = $matches[0];
        $position += strlen($tag);

        $factory = $this->registry->get($tag)->unwrapOrElse(
            fn() => throw new CodecException("Unknown type tag [$tag]"),
        );

        $args = [];

        if ($position < strlen($dsl) && $dsl[$position] === '<') {
            $position++;

            do {
                $args[] = $this->parseArg($dsl, $position, $depth + 1);
            } while ($position < strlen($dsl) && $dsl[$position] === ',' && ++$position);

            if ($position >= strlen($dsl) || $dsl[$position] !== '>') {
                throw new CodecException("Expected [>] at position $position in type [$dsl]");
            }

            $position++;
        }

        return $factory($args);
    }

    private function parseArg(string $dsl, int &$position, int $depth): Type|int|float|bool
    {
        if (preg_match('/(?:true|false)(?![a-zA-Z0-9_])/A', $dsl, $matches, offset: $position)) {
            $position += strlen($matches[0]);

            return $matches[0] === 'true';
        }

        if (preg_match('/-?[0-9]+(\.[0-9]+)?/A', $dsl, $matches, offset: $position)) {
            $position += strlen($matches[0]);

            return str_contains($matches[0], '.') ? (float) $matches[0] : (int) $matches[0];
        }

        return $this->parseNode($dsl, $position, $depth);
    }
}
