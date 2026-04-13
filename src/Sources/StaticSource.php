<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeInference;

final readonly class StaticSource implements Source
{
    public Type $declaredType;

    public function __construct(
        public mixed $value,
        ?Type $type = null,
    ) {
        $this->declaredType = $type ?? TypeInference::infer($value);
    }

    public function type(Context $context): Type
    {
        return $this->declaredType;
    }
}
