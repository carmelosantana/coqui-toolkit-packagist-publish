<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitPackagistPublish;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tool for publishing, editing, and updating packages on Packagist.org.
 *
 * Provides three authenticated actions:
 * - submit: Register a new package by repository URL (POST /api/create-package)
 * - edit: Change the repository URL for an existing package (PUT /api/packages/{name})
 * - update: Trigger Packagist to re-crawl a package (POST /api/update-package)
 *
 * All actions require authentication via PACKAGIST_USERNAME and PACKAGIST_API_TOKEN.
 * The submit and edit actions require the MAIN API token; update works with either token.
 *
 * @see https://packagist.org/apidoc
 */
final class PackagistPublishTool implements ToolInterface
{
    private const string BASE_URL = 'https://packagist.org';

    /**
     * @param \Closure(): string $usernameResolver
     * @param \Closure(): string $apiTokenResolver
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly \Closure $usernameResolver,
        private readonly \Closure $apiTokenResolver,
    ) {}

    public function name(): string
    {
        return 'packagist_publish';
    }

    public function description(): string
    {
        return <<<'DESC'
            Publish, edit, and update packages on Packagist.org (the main Composer repository).

            Use this tool to manage your packages on Packagist after pushing code to a
            public Git repository. Requires PACKAGIST_USERNAME and PACKAGIST_API_TOKEN.

            Available actions:
            - submit: Register a NEW package on Packagist. Provide the public Git repository
              URL. Packagist will crawl the repository and index the composer.json. The repo
              must contain a valid composer.json with a unique package name.
            - edit: Change the repository URL for an EXISTING package on Packagist. Useful
              when moving a repository to a new hosting provider or organization.
            - update: Trigger Packagist to re-crawl a package immediately. Use this after
              pushing a new release tag to have Packagist pick it up without waiting for
              the automatic webhook. Returns job IDs for the crawl tasks.

            The submit and edit actions require the MAIN API token (UNSAFE operations).
            The update action works with either the MAIN or SAFE token.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The publishing action to perform',
                values: ['submit', 'edit', 'update'],
                required: true,
            ),
            new StringParameter(
                name: 'repository',
                description: 'Full repository URL (e.g. "https://github.com/vendor/repo"). Required for submit and update actions. For edit, this is the NEW repository URL to set.',
                required: false,
            ),
            new StringParameter(
                name: 'package',
                description: 'Full package name (vendor/package). Required for edit action only.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'submit' => $this->submit($input),
            'edit' => $this->edit($input),
            'update' => $this->update($input),
            default => ToolResult::error("Unknown action: {$action}. Valid actions: submit, edit, update."),
        };
    }

    /**
     * Register a new package on Packagist by repository URL.
     *
     * POST /api/create-package {"repository": "..."}
     *
     * @param array<string, mixed> $input
     */
    private function submit(array $input): ToolResult
    {
        $repository = trim($input['repository'] ?? '');
        if ($repository === '') {
            return ToolResult::error(
                'The `repository` parameter is required for the submit action. '
                . 'Provide the full public Git repository URL (e.g. "https://github.com/vendor/repo").',
            );
        }

        if (!$this->isValidUrl($repository)) {
            return ToolResult::error(
                "Invalid repository URL: \"{$repository}\". Must be a valid HTTP(S) or Git URL.",
            );
        }

        $result = $this->apiRequest('POST', self::BASE_URL . '/api/create-package', [
            'repository' => $repository,
        ]);

        if ($result['error'] !== null) {
            return ToolResult::error(
                "## Failed to Submit Package\n\n"
                . "**Repository:** {$repository}\n"
                . "**Error:** {$result['error']}\n\n"
                . "Common causes:\n"
                . "- The repository URL is not publicly accessible\n"
                . "- The repository does not contain a valid `composer.json`\n"
                . "- A package with this name already exists on Packagist\n"
                . "- The API token is not the MAIN token (submit requires MAIN, not SAFE)\n",
            );
        }

        $status = $result['data']['status'] ?? 'unknown';

        return ToolResult::success(
            "## Package Submitted Successfully\n\n"
            . "**Repository:** {$repository}\n"
            . "**Status:** {$status}\n\n"
            . "The package has been registered on Packagist. It may take a few minutes for the\n"
            . "initial crawl to complete. Use `packagist` details to verify the package appears.\n"
            . "Use `packagist_publish` update to trigger an immediate re-crawl if needed.\n",
        );
    }

    /**
     * Edit the repository URL for an existing package.
     *
     * PUT /api/packages/{vendor}/{package} {"repository": "..."}
     *
     * @param array<string, mixed> $input
     */
    private function edit(array $input): ToolResult
    {
        $package = trim($input['package'] ?? '');
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error(
                'The `package` parameter (vendor/package) is required for the edit action.',
            );
        }

        $repository = trim($input['repository'] ?? '');
        if ($repository === '') {
            return ToolResult::error(
                'The `repository` parameter is required for the edit action. '
                . 'Provide the new repository URL to set for this package.',
            );
        }

        if (!$this->isValidUrl($repository)) {
            return ToolResult::error(
                "Invalid repository URL: \"{$repository}\". Must be a valid HTTP(S) or Git URL.",
            );
        }

        $result = $this->apiRequest('PUT', self::BASE_URL . "/api/packages/{$package}", [
            'repository' => $repository,
        ]);

        if ($result['error'] !== null) {
            return ToolResult::error(
                "## Failed to Edit Package\n\n"
                . "**Package:** {$package}\n"
                . "**New Repository:** {$repository}\n"
                . "**Error:** {$result['error']}\n\n"
                . "Common causes:\n"
                . "- The package does not exist on Packagist\n"
                . "- You are not a maintainer of this package\n"
                . "- The API token is not the MAIN token (edit requires MAIN, not SAFE)\n",
            );
        }

        $status = $result['data']['status'] ?? 'unknown';

        return ToolResult::success(
            "## Package Repository Updated\n\n"
            . "**Package:** {$package}\n"
            . "**New Repository:** {$repository}\n"
            . "**Status:** {$status}\n",
        );
    }

    /**
     * Trigger Packagist to re-crawl a package.
     *
     * POST /api/update-package {"repository": "..."}
     *
     * @param array<string, mixed> $input
     */
    private function update(array $input): ToolResult
    {
        $repository = trim($input['repository'] ?? '');
        if ($repository === '') {
            return ToolResult::error(
                'The `repository` parameter is required for the update action. '
                . 'Provide the repository URL or Packagist URL of the package to update.',
            );
        }

        if (!$this->isValidUrl($repository)) {
            return ToolResult::error(
                "Invalid URL: \"{$repository}\". Provide a valid repository or Packagist URL.",
            );
        }

        $result = $this->apiRequest('POST', self::BASE_URL . '/api/update-package', [
            'repository' => $repository,
        ]);

        if ($result['error'] !== null) {
            return ToolResult::error(
                "## Failed to Update Package\n\n"
                . "**Repository:** {$repository}\n"
                . "**Error:** {$result['error']}\n\n"
                . "Common causes:\n"
                . "- The repository URL does not match any package on Packagist\n"
                . "- The API token is invalid or expired\n",
            );
        }

        $status = $result['data']['status'] ?? 'unknown';
        $jobs = $result['data']['jobs'] ?? [];

        $output = "## Package Update Triggered\n\n"
            . "**Repository:** {$repository}\n"
            . "**Status:** {$status}\n";

        if ($jobs !== []) {
            $output .= "**Jobs:** " . implode(', ', $jobs) . "\n";
        }

        $output .= "\nPackagist will re-crawl the repository and index any new releases.\n";

        return ToolResult::success($output);
    }

    /**
     * Make an authenticated API request to Packagist.
     *
     * @param array<string, mixed> $body
     * @return array{data: ?array<string, mixed>, error: ?string}
     */
    private function apiRequest(string $method, string $url, array $body): array
    {
        $username = ($this->usernameResolver)();
        $apiToken = ($this->apiTokenResolver)();

        if ($username === '' || $apiToken === '') {
            return [
                'data' => null,
                'error' => 'Packagist credentials are not configured. '
                    . 'Set PACKAGIST_USERNAME and PACKAGIST_API_TOKEN using the credentials tool.',
            ];
        }

        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer {$username}:{$apiToken}",
                ],
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

                return ['data' => is_array($decoded) ? $decoded : [], 'error' => null];
            }

            // Try to extract a meaningful error message from the response
            $errorMessage = $this->extractErrorMessage($responseBody, $statusCode);

            return ['data' => null, 'error' => $errorMessage];
        } catch (\JsonException $e) {
            return ['data' => null, 'error' => "Invalid JSON response: {$e->getMessage()}"];
        } catch (\Throwable $e) {
            return ['data' => null, 'error' => "Request failed: {$e->getMessage()}"];
        }
    }

    /**
     * Extract a human-readable error message from an API error response.
     */
    private function extractErrorMessage(string $body, int $statusCode): string
    {
        $prefix = "HTTP {$statusCode}";

        if ($body === '') {
            return $prefix;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                // Packagist may return { "status": "error", "message": "..." }
                $message = $decoded['message'] ?? $decoded['error'] ?? $decoded['status'] ?? null;
                if (is_string($message)) {
                    return "{$prefix}: {$message}";
                }
            }
        } catch (\JsonException) {
            // Not JSON — use raw body
        }

        $truncated = mb_substr($body, 0, 500);

        return "{$prefix}: {$truncated}";
    }

    /**
     * Validate that a string looks like a plausible repository URL.
     */
    private function isValidUrl(string $url): bool
    {
        // Accept http(s) and git:// URLs, as well as git@ SSH URLs
        if (str_starts_with($url, 'git@')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https', 'git'], true);
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The publishing action to perform',
                            'enum' => ['submit', 'edit', 'update'],
                        ],
                        'repository' => [
                            'type' => 'string',
                            'description' => 'Full repository URL (e.g. "https://github.com/vendor/repo"). Required for submit and update. For edit, the new URL to set.',
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Full package name (vendor/package). Required for edit action only.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
