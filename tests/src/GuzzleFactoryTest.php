<?php
namespace tests;

use Germania\JsonApiClient\GuzzleFactory;
use GuzzleHttp\Client;

class GuzzleFactoryTest extends \PHPUnit\Framework\TestCase
{

	public function testFactory()
	{
		$api = "http://httpbin.org/bearer";
		$token = "FooBar";

		$sut = new GuzzleFactory;
		$client = $sut($api, $token);

		$this->assertInstanceOf( Client::class, $client);

		// Perform test using httpbin.org:
		// http://httpbin.org/#/Auth/get_bearer
		$response = $client->get("");
		$this->assertEquals(200, $response->getStatusCode());
			
		$response_decoded = json_decode($response->getBody()->__toString());
		
		$this->assertObjectHasAttribute("authenticated", $response_decoded);
		$this->assertTrue($response_decoded->authenticated);

		$this->assertObjectHasAttribute("token", $response_decoded);
		$this->assertEquals($token, $response_decoded->token);
	}
}