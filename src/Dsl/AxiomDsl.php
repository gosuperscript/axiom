<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Compiler\CompilationResult;
use Superscript\Axiom\Dsl\Compiler\Compiler;
use Superscript\Axiom\Dsl\Lexer\Lexer;
use Superscript\Axiom\Dsl\Parser\Parser;
use Superscript\Axiom\Dsl\PrettyPrinter\PrettyPrinter;

final class AxiomDsl
{
    public function __construct(
        private Lexer $lexer,
        private Parser $parser,
        private Compiler $compiler,
        private PrettyPrinter $prettyPrinter,
    ) {}

    public function parse(string $source): ProgramNode
    {
        $tokens = $this->lexer->tokenize($source);

        return $this->parser->parse($tokens);
    }

    public function compile(ProgramNode $ast): CompilationResult
    {
        return $this->compiler->compileProgram($ast);
    }

    public function prettyPrint(ProgramNode $ast): string
    {
        return $this->prettyPrinter->print($ast);
    }

    public function evaluate(string $source): CompilationResult
    {
        return $this->compile($this->parse($source));
    }

    public static function fromPlugins(DslPlugin ...$plugins): self
    {
        $operatorRegistry = new OperatorRegistry();
        $typeRegistry = new TypeRegistry();
        $functionRegistry = new FunctionRegistry();

        /** @var list<DslLiteralExtension> $literalExtensions */
        $literalExtensions = [];

        foreach ($plugins as $plugin) {
            $plugin->operators($operatorRegistry);
            $plugin->types($typeRegistry);
            $plugin->functions($functionRegistry);
            $literalExtensions = [...$literalExtensions, ...$plugin->literals()];
        }

        return new self(
            new Lexer($operatorRegistry),
            new Parser($operatorRegistry, $literalExtensions),
            new Compiler($typeRegistry, $literalExtensions),
            new PrettyPrinter($operatorRegistry, $literalExtensions),
        );
    }
}
