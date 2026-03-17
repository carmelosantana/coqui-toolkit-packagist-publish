<?php

declare(strict_types=1);

use CoquiBot\Toolkits\PackagistPublish\PackagistPublishToolkit;
use Symfony\Component\HttpClient\MockHttpClient;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new PackagistPublishToolkit();

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns packagist_publish tool', function () {
    $toolkit = new PackagistPublishToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->name())->toBe('packagist_publish');
});

test('guidelines returns non-empty string', function () {
    $toolkit = new PackagistPublishToolkit();

    expect($toolkit->guidelines())->toBeString()->not->toBeEmpty();
});

test('guidelines mentions required actions', function () {
    $toolkit = new PackagistPublishToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('submit');
    expect($guidelines)->toContain('edit');
    expect($guidelines)->toContain('update');
});

test('fromEnv creates instance', function () {
    $toolkit = PackagistPublishToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(PackagistPublishToolkit::class);
});

test('fromEnv reads credentials from environment', function () {
    $origUser = getenv('PACKAGIST_USERNAME');
    $origToken = getenv('PACKAGIST_API_TOKEN');

    putenv('PACKAGIST_USERNAME=test-user');
    putenv('PACKAGIST_API_TOKEN=test-token');
    $toolkit = PackagistPublishToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(PackagistPublishToolkit::class);
    expect($toolkit->tools())->toHaveCount(1);

    // Restore
    if ($origUser !== false) {
        putenv("PACKAGIST_USERNAME={$origUser}");
    } else {
        putenv('PACKAGIST_USERNAME');
    }
    if ($origToken !== false) {
        putenv("PACKAGIST_API_TOKEN={$origToken}");
    } else {
        putenv('PACKAGIST_API_TOKEN');
    }
});

test('custom http client is accepted', function () {
    $mockClient = new MockHttpClient();
    $toolkit = new PackagistPublishToolkit(httpClient: $mockClient);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->name())->toBe('packagist_publish');
});
