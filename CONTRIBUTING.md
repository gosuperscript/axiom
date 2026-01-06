# Contributing to Schema Library

Thank you for your interest in contributing to the Schema Library! We welcome contributions from the community.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues as you might find that you don't need to create one. When you create a bug report, please include as many details as possible:

* **Use a clear and descriptive title**
* **Describe the exact steps to reproduce the problem**
* **Provide specific examples to demonstrate the steps**
* **Describe the behavior you observed and what you expected**
* **Include PHP version and environment details**

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

* **Use a clear and descriptive title**
* **Provide a detailed description of the suggested enhancement**
* **Explain why this enhancement would be useful**
* **List any similar features in other libraries**

### Pull Requests

* Fill in the required template
* Follow the PHP coding style (PER/PSR-12)
* Include tests for new functionality
* Ensure all tests pass
* Update documentation as needed
* Write clear, descriptive commit messages

## Development Setup

1. **Fork and clone the repository**
   ```bash
   git clone https://github.com/your-username/schema.git
   cd schema
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Run tests to ensure everything works**
   ```bash
   composer test
   ```

### Docker Development

If you don't have PHP 8.4 installed locally, you can use Docker:

```bash
docker compose build
docker compose run --rm php composer install
docker compose run --rm php composer test
```

## Development Workflow

### Code Style

We use Laravel Pint for code formatting:

```bash
vendor/bin/pint
```

This will automatically fix code style issues according to the PER (PHP Evolving Recommendations) preset.

### Testing

The project requires **100% code coverage** for all new code. We use three types of testing:

1. **Unit Tests** (PHPUnit)
   ```bash
   composer test:unit
   ```
   * All new code must have corresponding tests
   * Tests must achieve 100% line coverage
   * Use PHPUnit 12+ attributes (`#[Test]`, `#[CoversClass]`)

2. **Static Analysis** (PHPStan)
   ```bash
   composer test:types
   ```
   * Analysis level: max (strictest)
   * All code must pass without errors

3. **Mutation Testing** (Infection)
   ```bash
   composer test:infection
   ```
   * Required Mutation Score Indicator (MSI): 100%
   * Ensures test quality and effectiveness

### Running All Tests

```bash
composer test
```

This runs all three test suites in sequence.

## Coding Guidelines

### PHP Version

* **Minimum PHP version:** 8.4
* Use modern PHP features (readonly properties, enums, etc.)
* Follow strict typing (`declare(strict_types=1)`)

### Architecture Principles

1. **Functional Programming**
   * Use Result and Option monads for error handling
   * Avoid exceptions for control flow
   * Prefer immutability

2. **Type Safety**
   * All methods must have type declarations
   * Use PHPStan level max compliance
   * Return explicit Result/Option types

3. **Design Patterns**
   * Strategy Pattern for resolvers
   * Chain of Responsibility for delegating resolvers
   * Factory Pattern for type creation

### Code Structure

* One class per file
* Follow PSR-4 autoloading
* Keep classes focused and single-purpose
* Write self-documenting code

### Documentation

* Update README.md if adding new features
* Include PHPDoc blocks for complex methods
* Add code examples for new functionality
* Keep documentation clear and concise

## Testing Best Practices

### Writing Tests

```php
<?php

namespace Superscript\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(YourClass::class)]
final class YourClassTest extends TestCase
{
    #[Test]
    public function it_does_something(): void
    {
        // Arrange
        $instance = new YourClass();
        
        // Act
        $result = $instance->doSomething();
        
        // Assert
        self::assertTrue($result->isOk());
    }
}
```

### Test Organization

* Unit tests mirror source structure
* Integration tests go in `tests/KitchenSink/`
* Use `#[CoversClass]` for unit tests
* Use `#[CoversNothing]` for integration tests

## Commit Message Guidelines

* Use present tense ("Add feature" not "Added feature")
* Use imperative mood ("Move cursor to..." not "Moves cursor to...")
* Limit first line to 72 characters
* Reference issues and pull requests when relevant

Examples:
```
Add support for custom operators in expressions

Fix null handling in StringType coercion

Update documentation for SymbolRegistry namespaces
```

## Pull Request Process

1. **Create a feature branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes**
   * Write code
   * Add tests
   * Update documentation

3. **Ensure quality**
   ```bash
   vendor/bin/pint              # Format code
   composer test:types          # Check static analysis
   composer test:unit           # Run unit tests
   composer test:infection      # Check mutation testing
   ```

4. **Commit and push**
   ```bash
   git add .
   git commit -m "Your descriptive message"
   git push origin feature/your-feature-name
   ```

5. **Create Pull Request**
   * Fill out the PR template completely
   * Link related issues
   * Await code review

6. **Address feedback**
   * Make requested changes
   * Push updates to the same branch
   * Respond to review comments

## Additional Resources

* [PHP Fig - PSR Standards](https://www.php-fig.org/psr/)
* [PHPStan Documentation](https://phpstan.org/)
* [Infection Mutation Testing](https://infection.github.io/)
* [azjezz/psl Library](https://github.com/azjezz/psl) - Our standard library

## Questions?

Feel free to open an issue for:
* Questions about contributing
* Clarifications on architecture
* Help with development setup

Thank you for contributing! 🎉
