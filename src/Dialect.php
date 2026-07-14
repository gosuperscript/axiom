<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NegateOverloader;
use Superscript\Axiom\Operators\NotOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\Type;

/**
 * The operator rules live in exactly one place. A Dialect composes the
 * binary rules, the unary rules, and the literal registry; the evaluator
 * and the checker consume the same instance, so checking with different
 * rules than you run with stops being representable in the API a normal
 * host touches.
 *
 * Packages contribute through {@see Extension}: extension rules prepend
 * core's (specialization wins ties — rare and deliberate under the honesty
 * contract); duplicate literal registrations are loud errors.
 */
final readonly class Dialect
{
    /**
     * @param list<OperatorOverloader> $binaryRules
     * @param list<UnaryOverloader> $unaryRules
     * @param array<class-string, callable(object): Type> $literalMappings
     */
    private function __construct(
        private array $binaryRules,
        private array $unaryRules,
        private array $literalMappings,
    ) {}

    public static function core(): self
    {
        return new self(
            binaryRules: [
                new NullOverloader(),
                new BinaryOverloader(),
                new ComparisonOverloader(),
                new HasOverloader(),
                new InOverloader(),
                new LogicalOverloader(),
                new IntersectsOverloader(),
            ],
            unaryRules: [
                new NotOverloader(),
                new NegateOverloader(),
            ],
            literalMappings: [],
        );
    }

    public function with(Extension ...$extensions): self
    {
        $binary = $this->binaryRules;
        $unary = $this->unaryRules;
        $literals = $this->literalMappings;

        foreach ($extensions as $extension) {
            $binary = [...$extension->operators(), ...$binary];
            $unary = [...$extension->unaryOperators(), ...$unary];

            foreach ($extension->literals() as $class => $factory) {
                if (isset($literals[$class])) {
                    throw new InvalidArgumentException(sprintf(
                        'Literal class [%s] is registered by two extensions; duplicate literal registrations are a configuration error, never a precedence question.',
                        $class,
                    ));
                }

                $literals[$class] = $factory;
            }
        }

        return new self($binary, $unary, $literals);
    }

    public function operators(): OperatorOverloader
    {
        return new OverloaderManager($this->binaryRules);
    }

    public function unaryOperators(): UnaryOverloader
    {
        return new UnaryOverloaderManager($this->unaryRules);
    }

    public function literals(): LiteralTypeRegistry
    {
        return new LiteralTypeRegistry($this->literalMappings);
    }
}
