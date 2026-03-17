<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PackagistPublish;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Toolkit providing Packagist package publishing tools (submit, edit, update).
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Requires PACKAGIST_USERNAME and PACKAGIST_API_TOKEN credentials.
 *
 * The credential guard system automatically blocks tool execution until
 * credentials are provided — this toolkit does not need its own missing-key UX.
 *
 * @see https://packagist.org/apidoc
 */
final class PackagistPublishToolkit implements ToolkitInterface
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $username = '',
        private readonly string $apiToken = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'headers' => [
                'User-Agent' => 'Coqui/1.0 (https://github.com/AgentCoqui/coqui)',
            ],
        ]);
    }

    /**
     * Factory method for ToolkitDiscovery — reads credentials from environment.
     */
    public static function fromEnv(): self
    {
        $username = getenv('PACKAGIST_USERNAME');
        $apiToken = getenv('PACKAGIST_API_TOKEN');

        return new self(
            username: $username !== false ? $username : '',
            apiToken: $apiToken !== false ? $apiToken : '',
        );
    }

    public function tools(): array
    {
        return [
            new PackagistPublishTool(
                httpClient: $this->httpClient,
                usernameResolver: $this->resolveUsername(...),
                apiTokenResolver: $this->resolveApiToken(...),
            ),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <PACKAGIST-PUBLISH-TOOLKIT-GUIDELINES>
            Use the `packagist_publish` tool to submit, edit, or update packages on Packagist.org.

            Recommended workflow for publishing a new package:
            1. Ensure the package has a valid `composer.json` with `name`, `description`, and `license`
            2. Push the package to a public Git repository (GitHub, GitLab, Bitbucket, etc.)
            3. `packagist_publish` submit → register the package on Packagist by repository URL
            4. `packagist_publish` update → trigger an immediate crawl to index the latest version

            Available actions:
            - submit: Register a new package on Packagist by its repository URL
            - edit: Change the repository URL for an existing package
            - update: Trigger Packagist to re-crawl a package and pick up new releases

            IMPORTANT:
            - The `submit` and `edit` actions require the MAIN API token (not the SAFE token)
            - The `update` action works with either the MAIN or SAFE token
            - The repository URL must be publicly accessible for Packagist to crawl it
            - Use the read-only `packagist` tool to verify the package after submission

            Credentials required: PACKAGIST_USERNAME, PACKAGIST_API_TOKEN
            </PACKAGIST-PUBLISH-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }

    /**
     * Resolve the username lazily — checks constructor value, then process environment.
     *
     * This enables hot-reload: after CredentialTool saves a value via putenv(),
     * the next tool call picks it up without restarting.
     */
    private function resolveUsername(): string
    {
        if ($this->username !== '') {
            return $this->username;
        }

        $env = getenv('PACKAGIST_USERNAME');

        return $env !== false ? $env : '';
    }

    /**
     * Resolve the API token lazily — checks constructor value, then process environment.
     *
     * This enables hot-reload: after CredentialTool saves a value via putenv(),
     * the next tool call picks it up without restarting.
     */
    private function resolveApiToken(): string
    {
        if ($this->apiToken !== '') {
            return $this->apiToken;
        }

        $env = getenv('PACKAGIST_API_TOKEN');

        return $env !== false ? $env : '';
    }
}
