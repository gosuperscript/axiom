<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Predicates;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Predicates\AllOf;
use Superscript\Axiom\Predicates\AnyOf;
use Superscript\Axiom\Predicates\Atom;
use Superscript\Axiom\Predicates\Predicate;
use Superscript\Axiom\Predicates\PredicateRelations;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

#[CoversClass(Predicate::class)]
#[CoversClass(Atom::class)]
#[CoversClass(AllOf::class)]
#[CoversClass(AnyOf::class)]
#[CoversClass(PredicateRelations::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
final class PredicateRelationsTest extends TestCase
{
    #[Test]
    public function source_projection_canonicalizes_core_connectives(): void
    {
        $x = self::symbol('x');
        $y = self::symbol('y');
        $z = self::symbol('z');

        $leftAssociated = Predicate::fromSource(new InfixExpression(
            new InfixExpression($x, '&&', $y),
            '&&',
            $z,
        ));
        $rightAssociatedWithDuplicate = Predicate::fromSource(new InfixExpression(
            $z,
            '&&',
            new InfixExpression($y, '&&', new InfixExpression($x, '&&', $x)),
        ));

        self::assertTrue($leftAssociated->equals($rightAssociatedWithDuplicate));
        self::assertInstanceOf(AllOf::class, $leftAssociated);
        self::assertCount(3, $leftAssociated->members);
        self::assertFalse($leftAssociated->equals(Predicate::fromSource(new InfixExpression(
            new InfixExpression($x, '&&', $y),
            '&&',
            self::symbol('other'),
        ))));

        $any = Predicate::fromSource(new InfixExpression(
            new InfixExpression($x, '||', $y),
            '||',
            new InfixExpression($y, '||', $z),
        ));

        self::assertInstanceOf(AnyOf::class, $any);
        self::assertCount(3, $any->members);
        self::assertTrue($any->equals(Predicate::fromSource(new InfixExpression(
            $z,
            '||',
            new InfixExpression($y, '||', $x),
        ))));
        self::assertFalse($any->equals($leftAssociated));
        self::assertFalse($any->equals(Predicate::fromSource(new InfixExpression($x, '||', $y))));
        self::assertFalse($any->equals(Predicate::fromSource(new InfixExpression(
            new InfixExpression($x, '||', $y),
            '||',
            self::symbol('other'),
        ))));
    }

    #[Test]
    public function separately_authored_equal_atoms_are_equal_without_php_type_juggling(): void
    {
        self::assertTrue(
            Predicate::fromSource(new StaticSource(5))->equals(Predicate::fromSource(new StaticSource(5))),
        );
        self::assertFalse(
            Predicate::fromSource(new StaticSource(5))->equals(Predicate::fromSource(new StaticSource('5'))),
        );
    }

    #[Test]
    public function a_non_persistable_atom_is_only_equal_to_itself(): void
    {
        $callback = static fn(): bool => true;
        $same = new HostSource($callback);

        self::assertTrue(Predicate::fromSource($same)->equals(Predicate::fromSource($same)));
        self::assertFalse(Predicate::fromSource($same)->equals(Predicate::fromSource(new HostSource($callback))));
    }

    #[Test]
    public function host_sources_remain_opaque_even_when_they_contain_connectives(): void
    {
        $nested = new InfixExpression(self::symbol('x'), '&&', self::symbol('y'));
        $host = new HostSource($nested);

        self::assertInstanceOf(Atom::class, Predicate::fromSource($host));
        self::assertFalse(self::implies($host, self::symbol('x')));
        self::assertTrue(self::implies($host, new HostSource($nested)));
    }

    #[Test]
    public function conjunction_and_disjunction_obey_their_structural_implication_laws(): void
    {
        $x = self::symbol('x');
        $y = self::symbol('y');
        $z = self::symbol('z');
        $xAndY = new InfixExpression($x, '&&', $y);
        $xOrY = new InfixExpression($x, '||', $y);

        self::assertTrue(self::implies($x, $x));
        self::assertTrue(self::implies($xAndY, $x));
        self::assertTrue(self::implies($x, $xOrY));
        self::assertTrue(self::implies(new InfixExpression($xAndY, '&&', $z), $xAndY));
        self::assertTrue(self::implies($xOrY, new InfixExpression($xOrY, '||', $z)));

        self::assertFalse(self::implies($xOrY, $x));
        self::assertFalse(self::implies($x, $xAndY));
        self::assertFalse(self::implies(new InfixExpression($y, '&&', $z), $x));
        self::assertFalse(self::implies($z, $xOrY));
        self::assertFalse(self::implies($y, $x));
    }

    #[Test]
    public function every_antecedent_branch_and_every_consequent_member_must_be_proved(): void
    {
        $x = self::symbol('x');
        $y = self::symbol('y');
        $z = self::symbol('z');
        $bothBranchesContainX = new InfixExpression(
            new InfixExpression($x, '&&', $y),
            '||',
            new InfixExpression($x, '&&', $z),
        );

        self::assertTrue(self::implies($bothBranchesContainX, $x));
        self::assertTrue(self::implies(
            new InfixExpression($x, '&&', $y),
            new InfixExpression($y, '&&', $x),
        ));
        self::assertFalse(self::implies(new InfixExpression($x, '||', $y), $x));
        self::assertFalse(self::implies($x, new InfixExpression($x, '&&', $y)));
    }

    #[Test]
    public function unfamiliar_operators_are_opaque_atoms(): void
    {
        $left = new InfixExpression(self::symbol('x'), 'xor', self::symbol('y'));

        self::assertTrue(self::implies($left, new InfixExpression(self::symbol('x'), 'xor', self::symbol('y'))));
        self::assertFalse(self::implies($left, self::symbol('x')));
    }

    #[Test]
    public function known_atoms_partially_evaluate_a_predicate_and_leave_a_source_residual(): void
    {
        $predicate = Predicate::fromSource(new InfixExpression(
            new InfixExpression(self::symbol('x'), '&&', self::symbol('y')),
            '||',
            self::symbol('z'),
        ));

        $residual = $predicate->partiallyEvaluate(static function (Atom $atom): ?bool {
            if (!$atom->source instanceof SymbolSource) {
                return null;
            }

            return match ($atom->source->name) {
                'x' => true,
                'z' => false,
                default => null,
            };
        });

        self::assertInstanceOf(Atom::class, $residual);
        self::assertEquals(self::symbol('y'), $residual->toSource());
    }

    #[Test]
    public function partial_evaluation_obeys_boolean_identities_and_short_circuits(): void
    {
        $xAndY = Predicate::fromSource(new InfixExpression(self::symbol('x'), '&&', self::symbol('y')));
        $xOrY = Predicate::fromSource(new InfixExpression(self::symbol('x'), '||', self::symbol('y')));

        self::assertTrue($xAndY->partiallyEvaluate(static fn(Atom $atom): bool => true));
        self::assertFalse($xAndY->partiallyEvaluate(
            static fn(Atom $atom): bool => $atom->source instanceof SymbolSource && $atom->source->name === 'x',
        ));
        self::assertFalse($xOrY->partiallyEvaluate(static fn(Atom $atom): bool => false));
        self::assertTrue($xOrY->partiallyEvaluate(
            static fn(Atom $atom): bool => $atom->source instanceof SymbolSource && $atom->source->name === 'y',
        ));
    }

    #[Test]
    public function a_partially_evaluated_composition_rebuilds_the_same_connective(): void
    {
        $predicate = Predicate::fromSource(new InfixExpression(
            new InfixExpression(self::symbol('x'), '&&', self::symbol('y')),
            '&&',
            self::symbol('z'),
        ));

        $residual = $predicate->partiallyEvaluate(
            static fn(Atom $atom): ?bool => (
                $atom->source instanceof SymbolSource && $atom->source->name === 'x'
                    ? true
                    : null
            ),
        );

        self::assertInstanceOf(AllOf::class, $residual);
        self::assertEquals(
            new InfixExpression(self::symbol('y'), '&&', self::symbol('z')),
            $residual->toSource(),
        );

        $disjunction = Predicate::fromSource(new InfixExpression(
            new InfixExpression(self::symbol('x'), '||', self::symbol('y')),
            '||',
            self::symbol('z'),
        ))->partiallyEvaluate(
            static fn(Atom $atom): ?bool => (
                $atom->source instanceof SymbolSource && $atom->source->name === 'z'
                    ? false
                    : null
            ),
        );

        self::assertInstanceOf(AnyOf::class, $disjunction);
        self::assertEquals(
            new InfixExpression(self::symbol('x'), '||', self::symbol('y')),
            $disjunction->toSource(),
        );
    }

    #[Test]
    public function connective_factories_require_members_and_collapse_a_single_member(): void
    {
        $atom = Predicate::fromSource(self::symbol('x'));

        self::assertSame($atom, AllOf::of($atom));
        self::assertSame($atom, AnyOf::of($atom));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A conjunction requires at least one predicate.');

        AllOf::of();
    }

    #[Test]
    public function a_disjunction_requires_a_member_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A disjunction requires at least one predicate.');

        AnyOf::of();
    }

    private static function symbol(string $name): SymbolSource
    {
        return new SymbolSource($name);
    }

    private static function implies(Source $antecedent, Source $consequent): bool
    {
        return PredicateRelations::implies(
            Predicate::fromSource($antecedent),
            Predicate::fromSource($consequent),
        );
    }
}

final readonly class HostSource implements Source
{
    public function __construct(public Source|Closure $child) {}
}
