## Overview

Welcome to the Prosper202 codebase. This repository contains the source code for Prosper202, an open-source PPC conversion tracking software. The project is structured to facilitate efficient tracking of online advertising campaigns, attribution, conversion data, split testing, and more.

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
4. **Environment Setup**:
   If a `setup.sh` script is present, execute it to prepare the development environment.

## Agent Interaction Guidelines

* **Context Exploration**: Review relevant files in `202-*` directories and configuration files before making changes.
* **Documentation**: Update or create documentation for significant code changes.
* **Pull Request Formatting**:

  * **Title**: `[Component] Brief Description`
  * **Description**:

    * Summary of changes and rationale.
    * Instructions for testing and validation.
    * References to related issues or discussions.

##
