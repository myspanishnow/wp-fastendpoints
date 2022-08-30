<?php

/**
 * Holds tests for the Response class.
 *
 * @since 0.9.0
 *
 * @package wp-fastendpoints
 * @license MIT
 */

declare(strict_types=1);

namespace Tests\Unit\Schemas;

use WP\FastEndpoints\Schemas\Response;
use TypeError;
use Mockery;

afterEach(function () {
	Mockery::close();
});

test('Creating Response instance with $schema as a string', function () {
	expect(new Response('User/Get'))->toBeInstanceOf(Response::class);
});

test('Creating Response instance with $schema as an array', function () {
	expect(new Response([]))->toBeInstanceOf(Response::class);
});

test('Creating Response instance with an invalid $schema type', function () {
	expect(fn() => new Response(1))->toThrow(TypeError::class);
	expect(fn() => new Response(1.67))->toThrow(TypeError::class);
	expect(fn() => new Response(true))->toThrow(TypeError::class);
	expect(fn() => new Response(false))->toThrow(TypeError::class);
});

test('Validating if Response returns() ignores unnecessary properties', function () {
	$response = new Response('User/Get');
	$response->appendSchemaDir(\SCHEMAS_DIR);
	// Similar fields as a WP_User
	$user = [
		"data" => [
			"ID" => "1",
			"user_login" => "fake_username",
			"user_pass" => "fake_pass",
			"user_nicename" => "1",
			"user_email" => "fake@wpfastendpoints.com",
			"user_url" => "",
			"user_registered" => "2022-08-29 13 => 46 => 28",
			"user_activation_key" => "random_key",
			"user_status" => "0",
			"display_name" => "André Gil",
			"spam" => "0",
			"deleted" => "0",
			"first" => [
				"second" => [
					"third" => [
						"forth" => true,
					],
				],
			],
		],
		"ID" => 1,
		"caps" => ["administrator" => true],
		"cap_key" => "wp_capabilities",
		"roles" => ["administrator"],
		"filter" => null,
	];
	// Create WP_REST_Request mock
	$req = Mockery::mock('WP_REST_Request');
	$req->shouldReceive('get_route')
		->andReturn('user');
	// Validate response
	$data = (array) $response->returns($req, $user);
	expect($data)->toMatchArray([
		"data" => [
			"ID" => "1",
			"user_email" => "fake@wpfastendpoints.com",
			"user_url" => "",
			"display_name" => "André Gil",
		],
		"is_admin" => true,
	]);
});