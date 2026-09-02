## Overview

Welcome to the Prosper202 codebase. This repository contains the source code for Prosper202, a self-hosted PPC conversion tracking platform licensed under the Business Source License 1.1 (BUSL-1.1). The project is structured to facilitate efficient tracking of online advertising campaigns, attribution, conversion data, split testing, and more.

**Directory Structure:**

* `202-account`, `202-charts`, `202-config`, etc.: Core modules handling various functionalities.
* `202-css`, `202-js`, `202-img`: Front-end assets including stylesheets, JavaScript files, and images.
* `202-cronjobs`: Scheduled tasks for maintenance and data processing.
* `api`: API endpoints for external integrations.
* `vendor`: Third-party dependencies managed via Composer.

Please focus your contributions within these directories, adhering to the project's coding standards and guidelines.

## Contribution & Style Guidelines

* **Coding Standards**: Follow PSR-12 coding standards for PHP.
* **Dependencies**: Manage PHP dependencies using Composer.
* **Testing**: Implement and run tests using PHPUnit.
* **Documentation**: Document public methods and classes with PHPDoc comments.
* **Version Control**: Avoid committing files in the `vendor` directory.
* **Legacy Code**: Refrain from modifying legacy scripts unless necessary.

## Migration/Refactor Notes

* **Modernization**: Efforts are underway to transition from procedural PHP to object-oriented programming.
* **Autoloading**: Utilize Composer's PSR-4 autoloading for new classes.
* **Namespace Usage**: Apply appropriate namespaces to new classes to maintain organization.

## Validation Procedures

To ensure code quality and functionality:

1. **Linting**:

   ```bash
   phpcs --standard=PSR12 .
   ```
2. **Testing**:

   ```bash
   vendor/bin/phpunit
   ```
3. **Dependency Installation**:

   ```bash
   composer install
   ```

## Go CLI (`go-cli/`)

The `p202` Go CLI is the intended interface for agents operating a Prosper202 instance.

* **Using it as an agent**: read `docs/cli-agent.md` (JSON output shapes, the error envelope and its `hint` field, exit codes, workflows, tool-use schema hints). Always pass `--json`.
* **Forecasting**: `documentation/cli/11-forecasting.md` explains `p202 forecast` end to end with real outputs (bands, ensemble, coherent metrics, seasonality, level shifts, transient masking).
* **Changing the CLI**: follow the error contract in `CLAUDE.md` ("Go CLI errors must be agent-actionable"): categorized validation errors, `%w` wrapping, a hint on every error that leaves a choice, centralized printing, and tests on exit code and hint. Keep `documentation/cli/10-go-cli.md` ("Errors") and `docs/cli-agent.md` in sync with any change to that contract.
* **Validation**: `cd go-cli && go vet ./... && go test ./...` (the forecast package's acceptance suites take ~20s; `-short` skips them).

## Agent Interaction Guidelines

* **Context Exploration**: Review relevant files in `202-*` directories and configuration files before making changes.
* **Documentation**: Update or create documentation for significant code changes.
* **Task Planning for Large Projects**: 
  * For complex or multi-step tasks, create a detailed task plan in markdown format
  * Break down the work into specific, actionable items
  * Save the plan as a `.md` file (e.g., `task-plan-feature-name.md`)
  * Check off each item as it's completed
  * It's acceptable to modify the task list as new requirements or issues are discovered
  * Keep the plan updated throughout the project to maintain visibility and track progress
* **Pull Request Formatting**:

  * **Title**: `[Component] Brief Description`
  * **Description**:

    * Summary of changes and rationale.
    * Instructions for testing and validation.
    * References to related issues or discussions.

##
