<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\PackagistPublish\PackagistPublishTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createTool(
    ?MockHttpClient $httpClient = null,
    string $username = 'testuser',
    string $apiToken = 'test-token-123',
): PackagistPublishTool {
    return new PackagistPublishTool(
        httpClient: $httpClient ?? new MockHttpClient(),
        usernameResolver: fn(): string => $username,
        apiTokenResolver: fn(): string => $apiToken,
    );
}

// --- Tool metadata ---

test('tool name is packagist_publish', function () {
    $tool = createTool();

    expect($tool->name())->toBe('packagist_publish');
});

test('tool description is non-empty', function () {
    $tool = createTool();

    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('tool has action, repository, and package parameters', function () {
    $tool = createTool();
    $params = $tool->parameters();

    expect($params)->toHaveCount(3);

    $names = array_map(fn($p) => $p->name, $params);
    expect($names)->toContain('action');
    expect($names)->toContain('repository');
    expect($names)->toContain('package');
});

test('toFunctionSchema returns valid schema', function () {
    $tool = createTool();
    $schema = $tool->toFunctionSchema();

    expect($schema)->toHaveKey('type', 'function');
    expect($schema['function'])->toHaveKey('name', 'packagist_publish');
    expect($schema['function']['parameters']['properties'])->toHaveKeys(['action', 'repository', 'package']);
    expect($schema['function']['parameters']['required'])->toBe(['action']);
    expect($schema['function']['parameters']['properties']['action']['enum'])->toBe(['submit', 'edit', 'update']);
});

// --- Unknown action ---

test('unknown action returns error', function () {
    $tool = createTool();
    $result = $tool->execute(['action' => 'invalid']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Unknown action');
});

// --- Submit action ---

test('submit succeeds with valid repository', function () {
    $mockResponse = new MockResponse('{"status":"success"}', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('Submitted Successfully');
    expect($result->content)->toContain('https://github.com/vendor/repo');
});

test('submit sends correct authorization header', function () {
    $requestMethod = '';
    $requestUrl = '';
    $requestHeaders = [];

    $mockResponse = new MockResponse('{"status":"success"}', ['http_code' => 200]);
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestMethod, &$requestUrl, &$requestHeaders, $mockResponse) {
        $requestMethod = $method;
        $requestUrl = $url;
        $requestHeaders = $options['headers'] ?? $options['normalized_headers'] ?? [];

        return $mockResponse;
    });

    $tool = createTool(httpClient: $httpClient, username: 'myuser', apiToken: 'mytoken');
    $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($requestMethod)->toBe('POST');
    expect($requestUrl)->toContain('/api/create-package');
});

test('submit requires repository parameter', function () {
    $tool = createTool();
    $result = $tool->execute(['action' => 'submit']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('repository');
    expect($result->content)->toContain('required');
});

test('submit validates repository URL', function () {
    $tool = createTool();
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'not-a-url',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Invalid repository URL');
});

test('submit handles API error response', function () {
    $mockResponse = new MockResponse('{"status":"error","message":"Forbidden"}', ['http_code' => 403]);
    $httpClient = new MockHttpClient($mockResponse);

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Failed to Submit');
    expect($result->content)->toContain('Forbidden');
});

test('submit handles network exception', function () {
    $httpClient = new MockHttpClient(function () {
        throw new \RuntimeException('Connection timeout');
    });

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Connection timeout');
});

// --- Edit action ---

test('edit succeeds with valid package and repository', function () {
    $mockResponse = new MockResponse('{"status":"success"}', ['http_code' => 200]);
    $requestUrl = '';
    $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestUrl, $mockResponse) {
        $requestUrl = $url;

        return $mockResponse;
    });

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'edit',
        'package' => 'vendor/package',
        'repository' => 'https://github.com/vendor/new-repo',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('Repository Updated');
    expect($result->content)->toContain('vendor/package');
    expect($result->content)->toContain('https://github.com/vendor/new-repo');
    expect($requestUrl)->toContain('/api/packages/vendor/package');
});

test('edit requires package parameter', function () {
    $tool = createTool();
    $result = $tool->execute([
        'action' => 'edit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('package');
    expect($result->content)->toContain('required');
});

test('edit requires vendor/package format', function () {
    $tool = createTool();
    $result = $tool->execute([
        'action' => 'edit',
        'package' => 'no-slash',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('vendor/package');
});

test('edit requires repository parameter', function () {
    $tool = createTool();
    $result = $tool->execute([
        'action' => 'edit',
        'package' => 'vendor/package',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('repository');
    expect($result->content)->toContain('required');
});

// --- Update action ---

test('update succeeds and shows job IDs', function () {
    $mockResponse = new MockResponse('{"status":"success","jobs":["job-abc","job-def"]}', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'update',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('Update Triggered');
    expect($result->content)->toContain('job-abc');
    expect($result->content)->toContain('job-def');
});

test('update requires repository parameter', function () {
    $tool = createTool();
    $result = $tool->execute(['action' => 'update']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('repository');
    expect($result->content)->toContain('required');
});

test('update handles API error', function () {
    $mockResponse = new MockResponse('{"status":"error","message":"Package not found"}', ['http_code' => 404]);
    $httpClient = new MockHttpClient($mockResponse);

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'update',
        'repository' => 'https://github.com/vendor/nonexistent',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Failed to Update');
    expect($result->content)->toContain('Package not found');
});

// --- Credential handling ---

test('missing credentials returns error', function () {
    $tool = new PackagistPublishTool(
        httpClient: new MockHttpClient(),
        usernameResolver: fn(): string => '',
        apiTokenResolver: fn(): string => '',
    );

    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('credentials');
});

test('missing username only returns error', function () {
    $tool = new PackagistPublishTool(
        httpClient: new MockHttpClient(),
        usernameResolver: fn(): string => '',
        apiTokenResolver: fn(): string => 'some-token',
    );

    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'https://github.com/vendor/repo',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('credentials');
});

// --- URL validation ---

test('submit accepts git SSH URL', function () {
    $mockResponse = new MockResponse('{"status":"success"}', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $tool = createTool(httpClient: $httpClient);
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => 'git@github.com:vendor/repo.git',
    ]);

    expect($result->status->value)->toBe('success');
});

test('submit rejects empty string repository', function () {
    $tool = createTool();
    $result = $tool->execute([
        'action' => 'submit',
        'repository' => '   ',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('required');
});
