# Coqui Packagist Publish Toolkit

Packagist package publishing toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Submit, edit, and update packages on Packagist.org — the main Composer repository.

This is the **write** companion to [coqui-toolkit-packagist](https://github.com/AgentCoqui/coqui-toolkit-packagist) (read-only search/discovery). Separated into its own package so that anonymous read operations are never blocked by credential guards.

## Requirements

- PHP 8.4+
- `symfony/http-client`
- Packagist account with API token

## Installation

```bash
composer require coquibot/coqui-toolkit-packagist-publish
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Credentials

This toolkit requires two credentials, managed automatically by Coqui's credential system:

| Credential | Description |
|-----------|-------------|
| `PACKAGIST_USERNAME` | Your Packagist username |
| `PACKAGIST_API_TOKEN` | Your Packagist **MAIN** API token (not the SAFE token) |

Get both from your [Packagist profile page](https://packagist.org/profile/).

The **MAIN** token is required for `submit` and `edit` actions. The `update` action works with either the MAIN or SAFE token.

When running inside Coqui, the credential guard automatically prompts for these values before any tool execution. You can also set them manually:

```bash
# In your .env file
PACKAGIST_USERNAME=your-username
PACKAGIST_API_TOKEN=your-main-api-token
```

## Tools Provided

### `packagist_publish`

Submit, edit, and update packages on Packagist.org.

| Parameter    | Type   | Required | Description |
|-------------|--------|----------|-------------|
| `action`     | enum   | Yes      | `submit`, `edit`, `update` |
| `repository` | string | Varies   | Full repository URL. Required for submit and update. For edit, the new URL to set. |
| `package`    | string | Varies   | Full package name (`vendor/package`). Required for edit only. |

#### Actions

**submit** — Register a new package on Packagist by its repository URL.

```
packagist_publish(action: "submit", repository: "https://github.com/vendor/my-package")
```

The repository must be publicly accessible and contain a valid `composer.json` with a unique package name.

**edit** — Change the repository URL for an existing package.

```
packagist_publish(action: "edit", package: "vendor/my-package", repository: "https://github.com/vendor/new-repo")
```

**update** — Trigger Packagist to re-crawl a package and pick up new releases.

```
packagist_publish(action: "update", repository: "https://github.com/vendor/my-package")
```

Returns job IDs for the crawl tasks. You can also pass a Packagist URL (`https://packagist.org/vendor/package`).

## Recommended Workflow

1. Ensure the package has a valid `composer.json` with `name`, `description`, and `license`
2. Push to a public Git repository
3. Use `packagist` search/details (from the read-only toolkit) to verify the name isn't taken
4. `packagist_publish` submit → register the package
5. `packagist_publish` update → trigger immediate crawl
6. `packagist` details → verify the package appears on Packagist

## Standalone Usage

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\PackagistPublish\PackagistPublishToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = new PackagistPublishToolkit(
    username: 'your-username',
    apiToken: 'your-main-api-token',
);

$tool = $toolkit->tools()[0];

// Submit a new package
$result = $tool->execute([
    'action' => 'submit',
    'repository' => 'https://github.com/vendor/my-package',
]);
echo $result->content;

// Trigger an update
$result = $tool->execute([
    'action' => 'update',
    'repository' => 'https://github.com/vendor/my-package',
]);
echo $result->content;
```

## Development

```bash
git clone https://github.com/AgentCoqui/coqui-toolkit-packagist-publish.git
cd coqui-toolkit-packagist-publish
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
