<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Types\BooleanType;

final class MoneyExtension extends Extension
{
    /** @param non-empty-list<string> $currencies */
    public function __construct(private readonly array $currencies) {}

    public function operators(): array
    {
        $rules = [];

        foreach ($this->currencies as $currency) {
            foreach ([
                ['=', false],
                ['==', false],
                ['===', false],
                ['!=', true],
                ['!==', true],
            ] as [$operator, $negated]) {
                $rules[] = Operator::infix($operator)
                    ->takes(new MoneyType($currency), new MoneyType($currency))
                    ->returns(new BooleanType())
                    ->evaluatesWith(fn(Money $left, Money $right) => $negated !== $left->isSameValueAs($right));
            }
        }

        return $rules;
    }

    public function literals(): array
    {
        return [
            Money::class => fn(Money $money) => new MoneyType($money->currency),
        ];
    }
}
