# Testing ldFlags-cli

This document explains how to run the test suite for ldFlags-cli using PEST testing framework.

## About PEST

PEST is a delightful PHP Testing Framework with a focus on simplicity. It's built on top of PHPUnit and provides a more expressive interface for writing tests.

## Running Tests

To run the test suite:

```bash
./vendor/bin/pest
```

To run a specific test file:

```bash
./vendor/bin/pest tests/Feature/FlagsListCommandTest.php
```

To run tests with coverage report:

```bash
./vendor/bin/pest --coverage
```

## Test Structure

The tests are organized into the following categories:

### Feature Tests

These tests verify that the commands work correctly as a whole:

- `FlagsListCommandTest.php` - Tests for the `flags:list` command
- `FlagsShowCommandTest.php` - Tests for the `flags:show` command
- `FlagsStatusCommandTest.php` - Tests for the `flags:status` command
- `FlagsAddRuleCommandTest.php` - Tests for the `flags:add-rule` command
- `EnvironmentsListCommandTest.php` - Tests for the `environments:list` command

### Unit Tests

WIP

## Test Approach

The tests use mocking to avoid making real API calls to LaunchDarkly:

1. **Service Mocking**: In command tests, the LaunchDarklyService is mocked to return pre-defined responses
2. **HTTP Client Mocking**: In service tests, the GuzzleHttp client is mocked to return controlled responses

This approach allows us to test the full functionality without hitting the actual LaunchDarkly API.

## Adding New Tests

When adding a new command or service method, please add corresponding tests:

1. For commands, create a new feature test that:
   - Tests the happy path (successful execution)
   - Tests error conditions (flag not found, API errors, etc.)
   - Tests parameter validation

2. For service methods, add tests to the LaunchDarklyServiceTest that:
   - Verify the method correctly parses API responses
   - Test error handling
