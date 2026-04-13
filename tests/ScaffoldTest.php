<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Artifacts\ArtifactRepository;
use Superscript\Axiom\Conformance\ConformanceCase;
use Superscript\Axiom\Diagnostics\Diagnostic;
use Superscript\Axiom\Diagnostics\DiagnosticSeverity;
use Superscript\Axiom\Diagnostics\SourceLocation;
use Superscript\Axiom\Runtime\EvaluationRequest;
use Superscript\Axiom\Runtime\ProgramBundle;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Values\DecimalValue;

#[CoversClass(ProgramBundle::class)]
#[CoversClass(EvaluationRequest::class)]
#[CoversClass(ConformanceCase::class)]
#[CoversClass(Diagnostic::class)]
#[CoversClass(SourceLocation::class)]
#[CoversClass(NumberType::class)]
#[CoversClass(DecimalValue::class)]
final class ScaffoldTest extends TestCase
{
    #[Test]
    public function it_provides_root_runtime_scaffolding(): void
    {
        $bundle = new ProgramBundle(
            sources: ['main.ax' => 'Premium(): number { 42 }'],
            artifacts: new class implements ArtifactRepository {
                public function has(string $tableName): bool
                {
                    return false;
                }

                public function fetch(string $tableName): string
                {
                    return '';
                }
            },
        );

        $request = new EvaluationRequest('Premium');
        $case = new ConformanceCase('smoke', 'Premium(): number { 42 }', 'Premium');
        $diagnostic = new Diagnostic(
            DiagnosticSeverity::Info,
            'scaffold',
            new SourceLocation('main.ax', 1, 1),
        );
        $number = new NumberType();
        $value = new DecimalValue(BigDecimal::of('42'));

        self::assertArrayHasKey('main.ax', $bundle->sources);
        self::assertSame('Premium', $request->expressionName);
        self::assertSame('smoke', $case->name);
        self::assertSame('scaffold', $diagnostic->message);
        self::assertSame('number', $number->describe());
        self::assertTrue($value->unwrap()->isEqualTo(BigDecimal::of('42')));
    }
}
