<?php
namespace Germania\JsonApiClient;

use Germania\JsonDecoder\JsonDecoder;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferExceptions;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\Log\LoggerAwareTrait;
use Psr\Cache\CacheItemPoolInterface;

class JsonApiClient
{

	use LoggerAwareTrait;

	/**
	 * @var \GuzzleHttp\Client
	 */
	protected $client;

	/**
	 * @var string
	 */
	public $request_method = "GET";

	/**
	 * @var CacheItemPoolInterface
	 */
	protected $cache_itempool;

	/**
	 * @var string
	 */
	public $error_loglevel = "error";

	/**
	 * @var integer
	 */
	public $cache_lifetime_default = 3600;



	/**
	 * @param Client                 $client            Readily configured Guzzle Client
	 * @param CacheItemPoolInterface $cache_itempool    PSR-6 Cache ItemPool
	 * @param LoggerInterface|null   $logger            Optional PSR-3 Logger.
	 * @param string                 $error_loglevel    Optional PSR-3 Loglevel, defaults to `error `
	 */
	public function __construct(Client $client, CacheItemPoolInterface $cache_itempool, LoggerInterface $logger = null, string $error_loglevel = "error" )
	{
		$this->client = $client;
		$this->cache_itempool = $cache_itempool;
		$this->error_loglevel = $error_loglevel;
		$this->setLogger( $logger ?: new NullLogger);
	}


	/**
	 * @param   string $path    Request URL path
	 * @param   array  $filters Filters array
	 * 
	 * @return  ArrayIterator
	 * @throws  JsonApiClientExceptionInterface
	 */
	public function __invoke( string $path, array $filters = array() )
	{
		// For evaluation in logs
		$start_time = microtime("float");



		// ---------------------------------------------------
		// Ask Cache first
		// ---------------------------------------------------

		try {
			$cache_key  = $this->getCacheKey($path, $filters);
			$cache_item = $this->cache_itempool->getItem( $cache_key );		

			if ($cache_item->isHit()):
				$items = $cache_item->get();

				$this->logger->info( "Found results in cache", [
					'path' => $path,
					'count' => count($items),
					'time' => ((microtime("float") - $start_time) * 1000) . "ms"
				]);

				return $this->buildResult( $items );	
			endif;
		}
		catch (\Throwable $e) {
			$msg = sprintf("Cache problem: %s", $e->getMessage());
			$this->logger->log( $this->error_loglevel, $msg, [
				'exception' => get_class($e)
			]);
			throw new JsonApiClientCacheException("Problems with API client cache", 0, $e);
		}		



		// ---------------------------------------------------
		// Ask remote API
		// ---------------------------------------------------

		try {
			// Returns ResponseInterface!
			$verb = $this->request_method;
			$response = $this->client->request( $verb, $path, [
				'query' => ['filter' => $filters]
			]);
		}
		catch (\Throwable $e) {
			$msg = sprintf("Request problem: %s", $e->getMessage());
			$this->logger->log( $this->error_loglevel, $msg, [
				'exception' => get_class($e)
			]);
			throw new JsonApiClientRequestException("Problems with API request", 0, $e);
		}		



		// ---------------------------------------------------
		// Response validation
		// ---------------------------------------------------

		try {
			$response_body_decoded = $this->decodeResponse($response);
			$this->validateDecodedResponse( $response_body_decoded );	
		}
		catch (\Throwable $e) {
			$msg = sprintf("Response problem: %s", $e->getMessage());
			$this->logger->log( $this->error_loglevel, $msg, [
				'exception' => get_class($e)
			]);
			throw new JsonApiClientResponseException("Problems with API response", 0, $e);
		}



		// ---------------------------------------------------
		// Build result and store in cache
		// ---------------------------------------------------

		try {
			// Grab the attributes from each JSON API response's data element
			$items = array_column($response_body_decoded['data'], "attributes");
			$cache_item->set( $items );	

			$lifetime = $this->calculateCacheLifetime( $response );
	    	$cache_item->expiresAfter( $lifetime );

	    	$this->cache_itempool->save($cache_item);

			$this->logger->notice( "Stored results in cache", [
				'path' => $path,
				'count' => count($items),
				'time' => ((microtime("float") - $start_time) * 1000) . "ms"
			]);

			return $this->buildResult( $items );		
		}
		catch (\Throwable $e) {
			$msg = sprintf("Results problem: %s", $e->getMessage());
			$this->logger->log( $this->error_loglevel, $msg, [
				'exception' => get_class($e)
			]);
			throw new JsonApiClientResultsException("Could not build results", 0, $e);
		}


	}



	/**
	 * @param  array $items
	 * @return ArrayIterator
	 */
	protected function buildResult( array $items )
	{
		return new \ArrayIterator( $items );			
	}





	/**
	 * Grabs the TTL from the "Cache-Control" header.
	 * 
	 * @param  \Psr\Http\Message\ResponseInterface $response [description]
	 * @return int
	 */
	protected function calculateCacheLifetime( ResponseInterface $response ) : int
	{
		$cache_control = $response->getHeaderLine('Cache-Control');

		preg_match("/(max\-age=(\d+))/i", $cache_control, $matches);

		$max_age = $matches[2] ?? $this->cache_lifetime_default;
		return (int) $max_age;
	}


	/**
	 * Returns a cache key for the current call.
	 * 
	 * @param  string $path
	 * @param  array $filters
	 * @return string
	 */
	protected function getCacheKey(string $path, array $filters) : string
	{
		$client_headers = $this->client->getConfig('headers');
		$authorization_hash = hash("sha256", $client_headers['Authorization'] ?? "noauth" );

		return implode("-", [
			$authorization_hash,
			urlencode($path),
			md5(serialize($filters))
		]);
	}


	/**
	 * Converts the response into an associative array.
	 * 
	 * @param  ResponseInterface $response [description]
	 * @return array Decoded JSON
	 */
	protected function decodeResponse( ResponseInterface $response )
	{
		return (new JsonDecoder)($response, "associative");	
	}



	/**
	 * Validates the decoded response, throwing things in error case.
	 *
	 * "data" is quite common in JsonAPI responses, however, we need it as array.
	 * 
	 * @param  array  $response_body_decoded
	 * @return void
	 *
	 * @throws JsonApiClientResponseException
	 */
	protected function validateDecodedResponse( array $response_body_decoded )
	{

		if (!isset( $response_body_decoded['data'] )):
			throw new JsonApiClientResponseException("API's JSON response lacks 'data' element");
		endif;


		if (!is_array( $response_body_decoded['data'] )):
			throw new JsonApiClientResponseException("API's JSON response element 'data' is not array");
		endif;

	}

}