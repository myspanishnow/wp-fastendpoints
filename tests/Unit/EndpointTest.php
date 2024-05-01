<?php

/**
 * Holds tests for the Endpoint class.
 *
 * @since 1.0.0
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests\Wp\FastEndpoints\Unit\Schemas;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Exception;
use Mockery;
use org\bovigo\vfs\vfsStream;
use Tests\Wp\FastEndpoints\Helpers\Helpers;
use Wp\FastEndpoints\Endpoint;
use Wp\FastEndpoints\Helpers\WpError;
use Wp\FastEndpoints\Schemas\Response;
use Wp\FastEndpoints\Schemas\Schema;

beforeEach(function () {
    Monkey\setUp();
});

afterEach(function () {
    Monkey\tearDown();
    vfsStream::setup();
});

// Constructor

test('Creating Endpoint instance', function () {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-args'], false);
    expect($endpoint)
        ->toBeInstanceOf(Endpoint::class)
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'method'))->toBe('GET')
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'route'))->toBe('/my-endpoint')
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'handler'))->toBe('__return_false')
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'args'))->toEqual(['my-args'])
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'override'))->toBeFalse();
})->group('endpoint', 'constructor');

// Register

test('Registering an endpoint', function (bool $withSchema, bool $withResponseSchema, $permissionCallback) {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    $expectedArgs = [
        'methods' => 'GET',
        'callback' => [$endpoint, 'callback'],
        'permission_callback' => '__return_true',
        'my-custom-arg' => true,
    ];
    expect($endpoint->schema)->toBeNull()
        ->and($endpoint->responseSchema)->toBeNull();
    if ($withSchema) {
        $mockedSchema = Mockery::mock(Schema::class)
            ->shouldReceive('appendSchemaDir')
            ->with(['my-schema-dir'])
            ->getMock();
        Helpers::setNonPublicClassProperty($endpoint, 'schema', $mockedSchema);
        $expectedArgs['schema'] = [$mockedSchema, 'getContents'];
    }
    if ($withResponseSchema) {
        $mockedResponseSchema = Mockery::mock(Response::class)
            ->shouldReceive('appendSchemaDir')
            ->with(['my-schema-dir'])
            ->getMock();
        Helpers::setNonPublicClassProperty($endpoint, 'responseSchema', $mockedResponseSchema);
    }
    if (! is_null($permissionCallback)) {
        $endpoint->permission($permissionCallback);
        $expectedArgs['permission_callback'] = [$endpoint, 'permissionCallback'];
    }
    Filters\expectApplied('fastendpoints_endpoint_args')
        ->once()
        ->with(Mockery::any(), 'my-namespace', 'v1/users', $endpoint)
        ->andReturnUsing(function ($givenArgs, $givenNamespace, $givenBase, $givenEndpoint) use ($expectedArgs) {
            expect($givenArgs)->toMatchArray($expectedArgs);

            return $givenArgs;
        });
    Functions\expect('register_rest_route')
        ->once()
        ->with('my-namespace', 'v1/users/my-endpoint', Mockery::any(), false)
        ->andReturnUsing(function ($givenNamespace, $givenBase, $givenArgs, $givenOverride) use ($expectedArgs) {
            expect($givenArgs)->toMatchArray($expectedArgs);

            return true;
        });
    expect($endpoint->register('my-namespace', 'v1/users', ['my-schema-dir']))->toBeTrue();
})->with([true, false])->with([true, false])->with([null, '__return_false'])->group('endpoint', 'register');

test('Skipping registering endpoint if no args specified', function () {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    Filters\expectApplied('fastendpoints_endpoint_args')
        ->once()
        ->with(Mockery::any(), 'my-namespace', 'v1/users', $endpoint)
        ->andReturn(false);
    Functions\expect('register_rest_route')
        ->times(0);
    expect($endpoint->register('my-namespace', 'v1/users', ['my-schema-dir']))->toBeFalse();
})->group('endpoint', 'register');

// hasCap

test('User with valid permissions', function (string $capability, ...$args) {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect(Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers'))->toBeEmpty();
    $endpoint->hasCap($capability, ...$args);
    $permissionHandlers = Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers');
    expect($permissionHandlers)->toHaveCount(1);
    $mockedRequest = Mockery::mock(\WP_REST_Request::class);
    $expectedParams = [];
    foreach ($args as $arg) {
        if (! is_string($arg) || ! str_starts_with($arg, '{')) {
            $expectedParams[] = $arg;

            continue;
        }

        $paramName = substr($arg, 1, -1);
        $isArgumentMissing = $arg === '{argument-missing}';
        $mockedRequest
            ->shouldReceive('has_param')
            ->once()
            ->with($paramName)
            ->andReturn(! $isArgumentMissing);
        if ($isArgumentMissing) {
            $expectedParams[] = $arg;

            continue;
        }
        $mockedRequest
            ->shouldReceive('get_param')
            ->once()
            ->with($paramName)
            ->andReturnUsing(function ($paramName) {
                return 'req_'.$paramName;
            });
        $expectedParams[] = 'req_'.$paramName;
    }
    Functions\expect('current_user_can')
        ->once()
        ->with($capability, ...$expectedParams)
        ->andReturn(true);
    expect($permissionHandlers[0]($mockedRequest))->toBeTrue();
})->with([
    'create_users', ['edit_plugins', 'delete_plugins', 98],
    ['create_users', '{post_id}', '{another_var}', false],
    ['edit_posts', '{argument-missing}'], '{custom-cap}',
])->group('endpoint', 'hasCap');

test('User not having enough permissions', function (string $capability, ...$args) {
    Functions\when('esc_html__')->returnArg();
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect(Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers'))->toBeEmpty();
    $endpoint->hasCap($capability, ...$args);
    $permissionHandlers = Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers');
    expect($permissionHandlers)->toHaveCount(1);
    $mockedRequest = Mockery::mock(\WP_REST_Request::class);
    $expectedParams = [];
    foreach ($args as $arg) {
        if (! str_starts_with($arg, '{')) {
            $expectedParams[] = $arg;

            continue;
        }

        $paramName = substr($arg, 1, -1);
        $isArgumentMissing = $arg === '{argument-missing}';
        $mockedRequest
            ->shouldReceive('has_param')
            ->once()
            ->with($paramName)
            ->andReturn(! $isArgumentMissing);
        if ($isArgumentMissing) {
            $expectedParams[] = $arg;

            continue;
        }

        $mockedRequest
            ->shouldReceive('get_param')
            ->once()
            ->with($paramName)
            ->andReturnUsing(function ($paramName) {
                return 'req_'.$paramName;
            });
        $expectedParams[] = 'req_'.$paramName;
    }
    Functions\expect('current_user_can')
        ->once()
        ->with($capability, ...$expectedParams)
        ->andReturn(false);
    expect($permissionHandlers[0]($mockedRequest))
        ->toBeInstanceOf(WpError::class)
        ->toHaveProperty('code', 403)
        ->toHaveProperty('message', 'Not enough permissions')
        ->toHaveProperty('data', ['status' => 403]);
})->with([
    'create_users', ['edit_plugins', 'delete_plugins'],
    '{custom_capability}', ['create_users', '{post_id}', '{another_var}'],
    ['create_users', '{post_id}', '{argument-missing}'],
])->group('endpoint', 'hasCap');

test('Missing capability', function () {
    Functions\when('esc_html__')->returnArg();
    Functions\when('wp_die')->alias(function ($msg) {
        throw new \Exception($msg);
    });
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect(function () use ($endpoint) {
        $endpoint->hasCap('');
    })->toThrow(Exception::class, 'Invalid capability. Empty capability given')
        ->and(Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers'))->toBeEmpty();
})->group('endpoint', 'hasCap');

// schema

test('Adding request validation schema', function ($schema) {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect($endpoint->schema)->toBeNull()
        ->and($endpoint->schema($schema))->toBe($endpoint)
        ->and($endpoint->schema)->toBeInstanceOf(Schema::class);
    $expectedVarName = is_string($schema) ? 'filepath' : 'contents';
    $expectedVar = Helpers::getNonPublicClassProperty($endpoint->schema, $expectedVarName);
    expect($expectedVar)->toBe($schema);
    $validationHandlers = Helpers::getNonPublicClassProperty($endpoint, 'validationHandlers');
    expect($validationHandlers)
        ->toHaveCount(1)
        ->and($validationHandlers[0])->toMatchArray([$endpoint->schema, 'validate']);
})->with([[['my-schema']], 'Basics/Array.json'])->group('endpoint', 'schema');

// returns

test('Adding response validation schema', function ($schema) {
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect($endpoint->responseSchema)->toBeNull()
        ->and($endpoint->returns($schema))->toBe($endpoint)
        ->and($endpoint->responseSchema)->toBeInstanceOf(Response::class);
    $expectedVarName = is_string($schema) ? 'filepath' : 'contents';
    $expectedVar = Helpers::getNonPublicClassProperty($endpoint->responseSchema, $expectedVarName);
    expect($expectedVar)->toBe($schema);
    $postHandlers = Helpers::getNonPublicClassProperty($endpoint, 'postHandlers');
    expect($postHandlers)
        ->toHaveCount(1)
        ->and($postHandlers[0])->toMatchArray([$endpoint->responseSchema, 'returns']);
})->with([[['response-schema']], 'Basics/Boolean.json'])->group('endpoint', 'returns');

// middleware

test('Adding middleware before handling request', function () {
    $middleware = function () {
        return true;
    };
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect(Helpers::getNonPublicClassProperty($endpoint, 'middlewareHandlers'))->toBeEmpty();
    $endpoint->middleware($middleware);
    $middlewareHandlers = Helpers::getNonPublicClassProperty($endpoint, 'middlewareHandlers');
    expect($middlewareHandlers)->toHaveCount(1)
        ->and($middlewareHandlers[0])->toBe($middleware);
})->group('endpoint', 'middleware');

// permission

test('Adding permission callable', function () {
    $permissionCallable = function () {
        return true;
    };
    $endpoint = new Endpoint('GET', '/my-endpoint', '__return_false', ['my-custom-arg' => true], false);
    expect(Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers'))->toBeEmpty();
    $endpoint->permission($permissionCallable);
    $permissionHandlers = Helpers::getNonPublicClassProperty($endpoint, 'permissionHandlers');
    expect($permissionHandlers)->toHaveCount(1)
        ->and($permissionHandlers[0])->toBe($permissionCallable);
})->group('endpoint', 'permission');

// permissionCallback

test('Running permission handlers in permission callback', function ($returnValue) {
    Functions\when('esc_html__')->returnArg();
    if (is_string($returnValue)) {
        $returnValue = new $returnValue(123, 'testing-error');
    }
    $req = Mockery::mock(\WP_REST_Request::class);
    $mockedEndpoint = Mockery::mock(Endpoint::class)
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
    Helpers::setNonPublicClassProperty($mockedEndpoint, 'permissionHandlers', ['test-permission-handler']);
    $mockedEndpoint->shouldReceive('runHandlers')
        ->once()
        ->with(['test-permission-handler'], $req)
        ->andReturn($returnValue);
    expect($mockedEndpoint->permissionCallback($req))
        ->toBe($returnValue);
})->with([true, WpError::class])->group('endpoint', 'permission', 'permissionCallback');

// callback

test('Endpoint request handler', function (bool $hasValidationCb, bool $hasMiddlewareCb, bool $hasOnResponseCb) {
    Functions\expect('rest_ensure_response')
        ->once()
        ->with('my-response')
        ->andReturn(new \WP_REST_Response('my-response'));
    $endpoint = new Endpoint('GET', '/my-endpoint', function () {
        return 'my-response';
    }, ['my-custom-arg' => true], true);
    $req = Mockery::mock(\WP_REST_Request::class);
    if ($hasValidationCb) {
        $validationCallers = [function ($req) {
            return false;
        }];
        Helpers::setNonPublicClassProperty($endpoint, 'validationHandlers', $validationCallers);
    }
    if ($hasMiddlewareCb) {
        $middlewareCallers = [function ($req) {
            return 123;
        }];
        Helpers::setNonPublicClassProperty($endpoint, 'middlewareHandlers', $middlewareCallers);
    }
    if ($hasOnResponseCb) {
        $onResponseCallers = [function ($req, $result) {
            return $result;
        }];
        Helpers::setNonPublicClassProperty($endpoint, 'postHandlers', $onResponseCallers);
    }
    expect($endpoint->callback($req))
        ->toBeInstanceOf(\WP_REST_Response::class)
        ->toHaveProperty('data', 'my-response');
})->with([true, false])->with([true, false])->with([true, false])->group('endpoint', 'callback');

test('Handling request and a WpError is returned', function ($validationReturnVal, $middlewareReturnVal, $handlerReturnVal, $responseReturnVal) {
    Functions\when('rest_ensure_response')->returnArg();
    Functions\when('esc_html__')->returnArg();
    $endpoint = new Endpoint('GET', '/my-endpoint', function () use ($handlerReturnVal) {
        return is_string($handlerReturnVal) ? new $handlerReturnVal(123, 'my-error-msg') : $handlerReturnVal;
    }, ['my-custom-arg' => true], true);
    $req = Mockery::mock(\WP_REST_Request::class);
    $validationCallers = [function ($req) use ($validationReturnVal) {
        return is_string($validationReturnVal) ? new $validationReturnVal(123, 'my-error-msg') : $validationReturnVal;
    }];
    Helpers::setNonPublicClassProperty($endpoint, 'validationHandlers', $validationCallers);
    $middlewareCallers = [function ($req) use ($middlewareReturnVal) {
        return is_string($middlewareReturnVal) ? new $middlewareReturnVal(123, 'my-error-msg') : $middlewareReturnVal;
    }];
    Helpers::setNonPublicClassProperty($endpoint, 'middlewareHandlers', $middlewareCallers);
    $onResponseCallers = [function ($req, $result) use ($responseReturnVal) {
        return is_string($responseReturnVal) ? new $responseReturnVal(123, 'my-error-msg') : $responseReturnVal;
    }];
    Helpers::setNonPublicClassProperty($endpoint, 'postHandlers', $onResponseCallers);
    expect($endpoint->callback($req))
        ->toBeInstanceOf(WpError::class)
        ->toHaveProperty('code', 123)
        ->toHaveProperty('message', 'my-error-msg')
        ->toHaveProperty('data', ['status' => 123]);
})->with([
    [WpError::class, true, true, true],
    [true, WpError::class, true, true],
    [true, true, WpError::class, true],
    [true, true, true, WpError::class],
])->group('endpoint', 'callback');

// getRoute

test('Getting endpoint route', function (string $route, string $expectedRoute) {
    $endpoint = new Endpoint('GET', $route, '__return_false');
    Filters\expectApplied('fastendpoints_endpoint_route')
        ->once()
        ->with($expectedRoute, $endpoint);
    expect(Helpers::invokeNonPublicClassMethod($endpoint, 'getRoute', '/my-base'))
        ->toBe($expectedRoute);
})->with([
    ['', '/my-base/'],
    ['/', '/my-base/'],
    ['/hello', '/my-base/hello'],
    ['hello', '/my-base/hello'],
    ['hello/another', '/my-base/hello/another'],
])->group('endpoint', 'getRoute');