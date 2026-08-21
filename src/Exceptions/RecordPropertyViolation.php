<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use InvalidArgumentException;
use Throwable;

/** @internal A record admission failure with its structural property path. */
final class RecordPropertyViolation extends InvalidArgumentException
{
    /**
     * @param non-empty-list<string> $path
     */
    private function __construct(
        public readonly array $path,
        public readonly bool $missing,
        public readonly string $detail,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function missing(string $property): self
    {
        return new self(
            [$property],
            true,
            'is missing',
            sprintf('Required property [%s] is missing.', $property),
        );
    }

    public static function invalid(string $property, Throwable $cause): self
    {
        return new self(
            [$property],
            false,
            $cause->getMessage(),
            sprintf('Property [%s]: %s', $property, $cause->getMessage()),
            $cause,
        );
    }

    public static function absent(string $property, string $type): self
    {
        $detail = sprintf('reads as absent, but %s is required.', $type);

        return new self(
            [$property],
            false,
            $detail,
            sprintf('Property [%s] %s', $property, $detail),
        );
    }

    public function beneath(string $property): self
    {
        return new self(
            [$property, ...$this->path],
            $this->missing,
            $this->detail,
            sprintf('Property [%s]: %s', $property, $this->getMessage()),
            $this,
        );
    }

    /** A dynamic collection element is malformed input, never an omitted declared path. */
    public function asElementFailure(int|string $key): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf('Element [%s]: %s', $key, $this->getMessage()),
            previous: $this,
        );
    }
}
