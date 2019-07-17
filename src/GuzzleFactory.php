<?php
namespace Germania\JsonApiClient;

use GuzzleHttp\Client;

/**
 * This callable factory creates a Guzzle Client
 * for usage with Germania KG's Web APIs.
 */
class GuzzleFactory
{

	/**
	 * @param  string $api   The Web API endpoint
	 * @param  string $token Optional: The AuthAPI Access token string
	 * @return Client        Guzzle Client
	 */
	public function __invoke( string $api, string $token = null)
	{

		$client_options = array(
		    'base_uri' => $api
		);

		if (!empty($token) and is_string($token)):
			$client_options['headers'] = array(
				'Authorization' => sprintf("Bearer %s", $token)
			);
		endif;

		return new Client( $client_options );

	}
}