<?php

declare(strict_types=1);

/**
 * SPIKE ONLY — run with `php spike/demo.php` from the worktree root.
 *
 * A battery of broken (and one clean) expressions put through the
 * error-tolerant walk, each printing every diagnostic, the references it
 * still collected, the root type, and whether program() certified.
 *
 * Every case asserts the soundness invariant: zero diagnostics IFF program()
 * is Ok, and an ErrorType anywhere means diagnostics is non-empty.
 */

require __DIR__ . '/../vendor/autoload.php';

use Superscript\Axiom\Definitions;
use Superscript\Axiom\Describable;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Spike\SpikeAnalysis;
use Superscript\Axiom\Spike\SpikeDiagnostic;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

$failures = 0;

/** @param array<string, \Superscript\Axiom\Types\Type> $declarations */
function scenario(
    string $title,
    Source $source,
    array $declarations = [],
    Definitions $definitions = new Definitions(),
    ?int $expectedDiagnostics = null,
): void {
    global $failures;

    $expression = new Expression($source, $definitions, declarations: $declarations);
    $analysis = $expression->spikeAnalyse();

    printf("\n=== %s ===\n", $title);
    printf("expression : %s\n", $source instanceof Describable ? $source->describe() : $source::class);
    printf("root type  : %s\n", \Superscript\Axiom\Spike\SpikeTypes::describe($analysis->rootType));
    printf("references : [%s]\n", implode(', ', $analysis->references));
    printf("diagnostics: %d\n", count($analysis->diagnostics));

    foreach ($analysis->diagnostics as $diagnostic) {
        printf("  - %s\n", indent($diagnostic->describe()));
    }

    $program = $analysis->program();
    printf("program()  : %s\n", $program->isOk() ? 'CERTIFIED' : 'refused');

    // The invariant, on every case.
    $certified = $program->isOk();
    $clean = $analysis->diagnostics === [];

    check($certified === $clean, 'INVARIANT: certified IFF zero diagnostics');
    check(!$analysis->hasErrorType() || !$certified, 'INVARIANT: no ErrorType survives into a certified Program');

    if ($certified) {
        check($program->unwrap() instanceof Program, 'a genuine Program came back');
    }

    if ($expectedDiagnostics !== null) {
        check(
            count($analysis->diagnostics) === $expectedDiagnostics,
            sprintf('expected exactly %d diagnostic(s), got %d', $expectedDiagnostics, count($analysis->diagnostics)),
        );
    }
}

function check(bool $condition, string $label): void
{
    global $failures;

    if (!$condition) {
        $failures++;
        printf("  !! FAILED: %s\n", $label);

        return;
    }

    printf("  ok %s\n", $label);
}

function indent(string $text): string
{
    return str_replace("\n", "\n    ", $text);
}

$number = new NumberType();
$string = new StringType();

// ---------------------------------------------------------------- 1. clean
scenario(
    '1. clean expression — the tolerant path must not tax the happy path',
    new InfixExpression(
        new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(1000)),
        '&&',
        new InfixExpression(new SymbolSource('postcode'), '===', new StaticSource('SW1')),
    ),
    ['turnover' => $number, 'postcode' => $string],
    expectedDiagnostics: 0,
);

// ------------------------------------------------------ 2. one unbound symbol
scenario(
    '2. one unbound symbol — exactly ONE diagnostic, name still referenced',
    new SymbolSource('mystery'),
    [],
    expectedDiagnostics: 1,
);

// ---------------------------------------------- 3. no cascade through a chain
scenario(
    '3. unbound symbol > 1000 && postcode === "SW1" — still exactly ONE diagnostic',
    new InfixExpression(
        new InfixExpression(new SymbolSource('mystery'), '>', new StaticSource(1000)),
        '&&',
        new InfixExpression(new SymbolSource('postcode'), '===', new StaticSource('SW1')),
    ),
    ['postcode' => $string],
    expectedDiagnostics: 1,
);

// ------------------------------------- 4. two independent errors, two reports
scenario(
    '4. two independent broken subtrees — exactly TWO diagnostics',
    new InfixExpression(
        new InfixExpression(new SymbolSource('mystery'), '>', new StaticSource(1000)),
        '&&',
        new InfixExpression(new SymbolSource('enigma'), '===', new StaticSource('SW1')),
    ),
    [],
    expectedDiagnostics: 2,
);

// --------------------------------- 5. bad operator over two VALID operands
scenario(
    '5. Number + String — one diagnostic, both operands still referenced',
    new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('postcode')),
    ['turnover' => $number, 'postcode' => $string],
    expectedDiagnostics: 1,
);

// -------------------------------------- 6. error inside a used definition
scenario(
    '6. definition with an error, used by the main expression',
    new InfixExpression(new SymbolSource('threshold'), '>', new StaticSource(10)),
    ['turnover' => $number],
    new Definitions([
        'threshold' => new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('missing_rate')),
    ]),
    expectedDiagnostics: 1,
);

// ------------------------------------------ 6b. clean definition, references
scenario(
    '6b. clean definition — references reach through it (#90 behaviour)',
    new InfixExpression(new SymbolSource('threshold'), '>', new StaticSource(10)),
    ['turnover' => $number],
    new Definitions([
        'threshold' => new InfixExpression(new SymbolSource('turnover'), '+', new StaticSource(5)),
    ]),
    expectedDiagnostics: 0,
);

// ------------------------------------------------------- 7. definition cycle
scenario(
    '7. definition cycle — diagnosed once, terminates',
    new SymbolSource('a'),
    [],
    new Definitions([
        'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
        'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
    ]),
);

// ------------------------------------------------ 8. match with a broken arm
scenario(
    '8. match: one broken arm, one valid arm — the valid arm is still checked',
    new MatchExpression(
        new SymbolSource('band'),
        [
            new MatchArm(new LiteralPattern('a'), new InfixExpression(new SymbolSource('unknown_rate'), '+', new StaticSource(1))),
            new MatchArm(new WildcardPattern(), new InfixExpression(new SymbolSource('turnover'), '+', new StaticSource(2))),
        ],
    ),
    ['band' => $string, 'turnover' => $number],
    expectedDiagnostics: 1,
);

// --------------------------- 9. broken match SUBJECT must not gag the arms
scenario(
    '9. match: broken subject AND a broken arm — both surface (subject error does not suppress arm checking)',
    new MatchExpression(
        new SymbolSource('no_such_subject'),
        [
            new MatchArm(new LiteralPattern('a'), new InfixExpression(new SymbolSource('also_missing'), '+', new StaticSource(1))),
            new MatchArm(new WildcardPattern(), new StaticSource(2)),
        ],
    ),
    ['turnover' => $number],
    expectedDiagnostics: 2,
);

// -------- 11. PROBE: does absorption hide a REAL second error? (false negative)
// Non-exhaustive match over a String subject is normally rejected. Here the
// subject is broken, so ErrorType's Never shape satisfies covers() vacuously
// and the missing-wildcard error is NEVER reported. Compare with 11b, the same
// match over a sound subject.
scenario(
    '11. PROBE non-exhaustive match over a BROKEN subject — is the exhaustiveness error suppressed?',
    new MatchExpression(
        new SymbolSource('no_such_subject'),
        [new MatchArm(new LiteralPattern('a'), new StaticSource(1))],
    ),
    [],
);

scenario(
    '11b. the same non-exhaustive match over a SOUND subject — for comparison',
    new MatchExpression(
        new SymbolSource('band'),
        [new MatchArm(new LiteralPattern('a'), new StaticSource(1))],
    ),
    ['band' => $string],
);

// ------------------------------- 10. the clean case still runs, end to end
$clean = new Expression(
    new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(1000)),
    declarations: ['turnover' => $number],
);
$program = $clean->spikeAnalyse()->program()->unwrap();
printf("\n=== 10. a certified Program from the tolerant walk actually runs ===\n");
printf("turnover=2000 -> %s\n", var_export($program(['turnover' => 2000])->unwrap()->unwrap(), true));
check($program(['turnover' => 2000])->unwrap()->unwrap() === true, 'the shared-walk Program evaluates correctly');

printf("\n%s\n", $failures === 0 ? 'ALL INVARIANTS HELD' : sprintf('%d CHECK(S) FAILED', $failures));

exit($failures === 0 ? 0 : 1);
