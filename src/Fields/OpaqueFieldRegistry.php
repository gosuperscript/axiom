<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

/**
 * The composed opaque-field declarations, keyed by opaque identity then field
 * name. It is the plugin seam for member access on nominal types — a money
 * package declares `money.amount`, a host declares `address.postcode`.
 *
 * Consulted only at the member-access checkpoint
 * ({@see \Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler}), and
 * deliberately absent from {@see \Superscript\Axiom\Types\TypeRelations}: a
 * declared field must never make its opaque assignable to a matching record
 * slot, or a certified access would crash on the real object.
 */
final readonly class OpaqueFieldRegistry
{
    /**
     * @param array<string, array<string, OpaqueField>> $fields keyed by identity, then name
     */
    public function __construct(
        private array $fields = [],
    ) {}

    public function resolve(string $identity, string $name): ?OpaqueField
    {
        return $this->fields[$identity][$name] ?? null;
    }
}
