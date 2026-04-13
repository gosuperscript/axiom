<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;

final readonly class MemberAccessSource implements Source
{
    public function __construct(
        public Source $object,
        public string $property,
    ) {}

    public function type(Context $context): Type
    {
        $objectType = $this->object->type($context);

        if ($objectType instanceof UnresolvedType) {
            return $objectType;
        }

        $member = $objectType->memberType($this->property);
        if ($member->isSome()) {
            return $member->unwrap();
        }

        return new UnresolvedType(
            "type " . $objectType->name() . " has no member '{$this->property}'",
        );
    }
}
