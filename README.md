# ldFlags-cli

A powerful command-line interface application for managing LaunchDarkly feature flags. Built with Laravel Zero, this CLI tool provides convenient commands for listing, inspecting, and modifying feature flags directly from your terminal.

## Overview

ldFlags-cli simplifies LaunchDarkly feature flag management by offering a suite of commands designed to integrate with the LaunchDarkly API. It's particularly useful for:

- Viewing and filtering feature flags across projects and environments
- Inspecting detailed flag configurations
- Adding targeting rules for API endpoint patterns
- Checking flag status across environments
- Viewing environment configurations

This application is built with [Laravel Zero](https://laravel-zero.com/), a micro-framework that provides an elegant starting point for your console application.

## Installation

### Requirements

- PHP 8.1 or higher
- Composer

### Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/your-repo/ldflags-cli.git
   cd ldflags-cli
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure your LaunchDarkly API key:
   ```bash
   cp .env.example .env
   ```

   Then edit the `.env` file and add your LaunchDarkly API key:
   ```
   LAUNCHDARKLY_API_KEY=your-api-key
   LAUNCHDARKLY_API_URL=https://app.launchdarkly.com/api/v2
   LAUNCHDARKLY_DEFAULT_PROJECT=your-default-project
   LAUNCHDARKLY_DEFAULT_ENVIRONMENT=your-default-environment
   ```

4. Make the CLI executable:
   ```bash
   chmod +x ldflags-cli
   ```

## Commands

### flags:list

Lists all feature flags from a LaunchDarkly project.

```bash
./ldflags-cli flags:list [options]
```

Options:
- `--project=PROJECT_KEY` - LaunchDarkly project ID
- `--environment=ENV_KEY` - Specific environment to filter by
- `--tag=TAG` - Filter flags by tag
- `--json` - Output in JSON format
- `--debug` - Show detailed debug information

Examples:
```bash
# List all flags in the default project
./ldflags-cli flags:list

# List flags in the production environment
./ldflags-cli flags:list --environment=production

# List flags with a specific tag
./ldflags-cli flags:list --tag=api-migration
```

### flags:show

Shows detailed information about a specific feature flag.

```bash
./ldflags-cli flags:show FLAG_KEY [options]
```

Options:
- `--project=PROJECT_KEY` - LaunchDarkly project ID
- `--environment=ENV_KEY` - Specific environment in LaunchDarkly
- `--json` - Output in JSON format

Examples:
```bash
# Show detailed information for a flag
./ldflags-cli flags:show my-feature-flag

# Show flag details for a specific environment
./ldflags-cli flags:show my-feature-flag --environment=staging

# Get raw JSON data for a flag
./ldflags-cli flags:show my-feature-flag --json
```

### flags:status

Shows the status of a feature flag across all environments.

```bash
./ldflags-cli flags:status FLAG_KEY [options]
```

Options:
- `--project=PROJECT_KEY` - LaunchDarkly project ID
- `--environment=ENV_KEY` - Filter by specific environment
- `--json` - Output in JSON format

Examples:
```bash
# Check flag status across all environments
./ldflags-cli flags:status my-feature-flag

# Check flag status in a specific environment
./ldflags-cli flags:status my-feature-flag --environment=production
```

### flags:add-rule

Adds a targeting rule to a feature flag for specific endpoint patterns. Particularly useful for API migrations.

```bash
./ldflags-cli flags:add-rule NAME PATTERN [options]
```

Arguments:
- `NAME` - Name of the targeting rule
- `PATTERN` - Endpoint pattern in the format "METHOD /path"

Options:
- `--project=PROJECT_KEY` - LaunchDarkly project ID
- `--environment=ENV_KEY` - Environment to add the rule to
- `--v5-percentage=100` - Percentage for v5 variation (0-100)
- `--v6-percentage=0` - Percentage for v6 variation (0-100)
- `--context-kind=request` - Context kind for the rule
- `--bucket-by=key` - Attribute to bucket by for percentage rollout
- `--position=0` - Position to insert the rule (0 is first)
- `--debug` - Show detailed debug information
- `--force` - Do not ask for permission to continue
- `--comment=COMMENT` - Comment to include with the change
- `--flag=api-v6-rollout-endpoints` - The feature flag key to add rules to
- `--no-track` - Disable event tracking for this rule
- `--json-patch` - Use JSON Patch format instead of semantic patch

Examples:
```bash
# Add a rule for a GET endpoint
./ldflags-cli flags:add-rule "Users API" "GET /api/v1/users" --environment=production

# Add a rule with traffic split
./ldflags-cli flags:add-rule "Products API" "GET /api/v1/products" --v5-percentage=80 --v6-percentage=20 --environment=production
```

### environments:list

Lists all environments for a LaunchDarkly project.

```bash
./ldflags-cli environments:list [options]
```

Options:
- `--project=PROJECT_KEY` - LaunchDarkly project ID
- `--json` - Output in JSON format

Examples:
```bash
# List environments for the default project
./ldflags-cli environments:list

# List environments for a specific project
./ldflags-cli environments:list --project=my-project
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE.md).
