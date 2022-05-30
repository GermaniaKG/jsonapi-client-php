<?php
namespace tests;

use Germania\JsonApiClient\JsonApiClient;
use Germania\JsonApiClient\JsonApiClientExceptionInterface;
use Germania\JsonApiClient\JsonApiClientResponseException;
use Germania\JsonApiClient\JsonApiClientRequestException;
use Germania\JsonApiClient\JsonApiClientResultsException;
use Germania\JsonApiClient\JsonApiClientCacheException;
use GuzzleHttp\Client;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Http\Message\ResponseInterface;


use Cache\Adapter\Void\VoidCachePool;


class ApiClientTest extends \PHPUnit\Framework\TestCase
{
    use ProphecyTrait;

	public function testExceptionInAskCachePhase( )
	{
		$exception = $this->prophesize( \Exception::class );

		// Create Cache ItemPool
		$cache = $this->prophesize( CacheItemPoolInterface::class );
		$cache->getItem( Argument::any() )->willThrow( $exception->reveal() );
		$cache_itempool = $cache->reveal();


		// Mock Guzzle and API response
		$raw_response = array('data' => array());
		$response = new Response(200, array(), json_encode( $raw_response ));
		$guzzle = $this->createGuzzleClientStub( $response );


		// Setup JsonApiClient
		$sut = new JsonApiClient( $guzzle, $cache_itempool );
		$this->assertTrue( is_callable( $sut ));

		$this->expectException( JsonApiClientExceptionInterface::class );
		$this->expectException( JsonApiClientCacheException::class );
		$sut("some/url/path/", [ 
			"filter1" => "foo",
			"filter2" => "bar"
		]);
	}




	public function testExceptionInBuildResultsPhase( )
	{
		$exception = $this->prophesize( \Exception::class );

		// Create Cache ItemPool
		$cache_item = $this->createCacheItem( false);
		$cache = $this->createCacheItemPool( $cache_item, "do_not_reveal" );
		$cache->save( Argument::any() )->willThrow( $exception->reveal() );
		$cache_itempool = $cache->reveal();


		// Mock Guzzle and API response
		$raw_response = array('data' => array());
		$response = new Response(200, array(), json_encode( $raw_response ));
		$guzzle = $this->createGuzzleClientStub( $response );


		// Setup JsonApiClient
		$sut = new JsonApiClient( $guzzle, $cache_itempool );
		$this->assertTrue( is_callable( $sut ));

		$this->expectException( JsonApiClientExceptionInterface::class );
		$this->expectException( JsonApiClientResultsException::class );
		$sut("some/url/path/", [ 
			"filter1" => "foo",
			"filter2" => "bar"
		]);
	}





	/**
	 * @dataProvider provideCacheItemPools_CacheMiss
	 */
	public function testSimpleWithNothingInCache( $cache_itempool )
	{
		// Mock Guzzle and API response
		$raw_response = array('data' => array());
		$response = new Response(200, array(), json_encode( $raw_response ));
		$guzzle = $this->createGuzzleClientStub( $response );


		// Setup JsonApiClient
		$sut = new JsonApiClient( $guzzle, $cache_itempool );
		$this->assertTrue( is_callable( $sut ));

		$items = $sut("some/url/path/", [ 
			"filter1" => "foo",
			"filter2" => "bar"
		]);
		$this->assertInstanceOf( \Traversable::class, $items);
	}


	public function provideCacheItemPools_CacheMiss()
	{
		// Create "missing" cache item
		$cache_item = $this->createCacheItem( false, "do_not_reveal" );
		$cache_item->set( Argument::type("array") )->shouldBeCalled();
		$cache_item->expiresAfter( Argument::type("integer") )->shouldBeCalled();
		$cache_item_stub = $cache_item->reveal();

		// Create Cache ItemPool
		$cache = $this->createCacheItemPool( $cache_item->reveal(), "do_not_reveal" );
		$cache->save( Argument::any() )->shouldBeCalled();
		$cache_itempool_stub = $cache->reveal();

		// Tests run with these:
		return array(
			[ $cache_itempool_stub ],
			[ new VoidCachePool ]
		);	
	}







	public function testSimpleWithCacheHit()
	{
		$base_uri = $GLOBALS['API_BASE_URL'];
		$token = $GLOBALS['AUTH_TOKEN'];
		$auth_header = sprintf("Bearer %s", $token);

		// Mock Guzzle Client
		$client = new Client([
		    'base_uri' => $base_uri,
		    'headers'  => array('Authorization' => $auth_header)
		]);


		// Create Cache Pool and Item
		$cache_item = $this->createCacheItem( "isHit", "do_not_reveal" );
		$cache_item->get( )->willReturn( array("foo", "bar"));
		$cache_itempool_stub = $this->createCacheItemPool( $cache_item->reveal() );


		// Setup JsonApiClient
		$sut = new JsonApiClient( $client, $cache_itempool_stub );
		$this->assertTrue( is_callable( $sut ));

		$all = $sut("all", [ 
			"product" => "plissee",
			"category" => "montageanleitung" 
		]);
		$this->assertInstanceOf( \Traversable::class, $all);

		$latest = $sut("latest", [  "product" => "plissee" ]);
		$this->assertInstanceOf( \Traversable::class, $latest);
	}








	/**
	 * @dataProvider provideCacheItemPools_CacheMiss
	 */
	public function testExceptionOnRequestException( $cache_itempool_stub )
	{
		$exception = $this->prophesize( ClientException::class );

		// Mock Guzzle Client
		$client = $this->prophesize( Client::class );
		$client->getConfig( Argument::type("string"))->willReturn( array("Authorization" => "foobar") );
		$client->request( Argument::type("string"), Argument::type("string"), Argument::type("array"))->willThrow( $exception->reveal() );
		$client_stub = $client->reveal();

		// Setup JsonApiClient
		$sut = new JsonApiClient( $client_stub, $cache_itempool_stub );

		// Provoke
		$this->expectException( JsonApiClientExceptionInterface::class );
		$this->expectException( JsonApiClientRequestException::class );

		$all = $sut("all", [ 
			"product" => "plissee",
			"category" => "montageanleitung" 
		]);
	}




	/**
	 * @dataProvider provideVariousInvalidResonseBodies
	 */
	public function testExceptionOnWeirdResponseBody( $body )
	{
		$response = new Response( 200, array(), $body );

		// Mock Guzzle Client
		$client = $this->prophesize( Client::class );
		$client->getConfig( Argument::type("string"))->willReturn( array("Authorization" => "foobar") );		
		$client->request( Argument::type("string"), Argument::type("string"), Argument::type("array"))->willReturn( $response );
		$client_stub = $client->reveal();

		// Create Cache Pool and Item
		$cache_itempool_stub = new VoidCachePool;		

		// Setup JsonApiClient
		$sut = new JsonApiClient( $client_stub, $cache_itempool_stub );

		$this->expectException( JsonApiClientExceptionInterface::class );
		$this->expectException( JsonApiClientResponseException::class );
		$all = $sut("all");
	}


	public function provideVariousInvalidResonseBodies()
	{
		return array(
			[ "hello!" ],
			[ json_encode( array("foo" => "bar")) ],
			[ json_encode( array("data" => "bar")) ],
			[ json_encode( array("data" => 1)) ],
			[ json_encode( array("data" => false)) ],
			[ json_encode( array("data" => true)) ],
		);
	}






	public function createGuzzleClientStub( ResponseInterface $response )
	{
		// Mock Guzzle Client
		$client = $this->prophesize( Client::class );
		$client->request( Argument::type("string"), Argument::type("string"), Argument::type("array") )->willReturn( $response );
		$client->getConfig( Argument::type("string") )->willReturn( array('Authorization' => "foo"));

		return $client->reveal();
	}


	/**
	 * @return CacheItemInterface
	 */
	protected function createCacheItem( bool $is_hit, bool $do_not_reveal = false )
	{
		$cache_item = $this->prophesize(CacheItemInterface::class);
		$cache_item->set(Argument::any())->willReturn();
        $cache_item->expiresAfter(Argument::any())->willReturn();
        $cache_item->isHit()->willReturn( $is_hit );

		return ($do_not_reveal) ? $cache_item : $cache_item->reveal();
	}


	/**
	 * @return CacheItemPoolInterface
	 */
	protected function createCacheItemPool( CacheItemInterface $cache_item, bool $do_not_reveal = false )
	{
		$cache = $this->prophesize( CacheItemPoolInterface::class );
		$cache->getItem( Argument::type("string") )->willReturn( $cache_item );

		return ($do_not_reveal) ? $cache : $cache->reveal();
	}

} 
