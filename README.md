<img src="https://static.germania-kg.com/logos/ga-logo-2016-web.svgz" width="250px">

------



# Germania KG · JsonAPI Client

**Server-side PHP wrapper for simple retrievals from Germania's JSON APIs with Cache support.**




## Installation

```bash
$ composer require germania-kg/jsonapi-client
```



## Usage

### The Guzzle Factory

The *JsonApiClient* requires a **Guzzle Client** which will perform the API requests. You may either bring your own Guzzle instance or use the **GuzzleFactory**, which is useful for *API endpoints* that require a “bearer” *Access token*. 

```php
<?php
use Germania\JsonApiClient\GuzzleFactory;

// Have your Web API endpoint and Access token at hand
$api = "https://api.example.com/"
$token = "manymanyletters"; 

// Setup a Guzzle Client that will ask the Web API
$guzzle = (new GuzzleFactory)( $api, $token);
```



### The JsonApiClient

The **JsonApiClient** requires the above *Guzzle Client* as well as a *PSR-6 Cache ItemPool.* It optionally accepts a *PSR-3 Logger* and/or a PSR-3 *Loglevel name* for errors.

```php
<?php
use Germania\JsonApiClient\JsonApiClient;
use Germania\JsonApiClient\GuzzleFactory;
use GuzzleHttp\Client;

$guzzle = new Client( ... );
$guzzle = (new GuzzleFactory)( $api, $token);

$cache  = new \Stash\Pool( ... );
$logger = new \Monolog\Logger( ... );

$api_client = new JsonApiClient($guzzle, $cache );
$api_client = new JsonApiClient($guzzle, $cache, $logger );
$api_client = new JsonApiClient($guzzle, $cache, $logger, "alert" );
```

#### Customizing

**Error loglevel:** Loglevel name to use when Guzzle fails. Defaults to *error*.

```php
$api_client->error_loglevel = "error";
```

**Request method:** This library is built for GET and is not intended or tested for POST, DELETE and such.

```php
$api_client->request_method = "GET";
```

**Cache life time:** The cache lifetime will be taken from the API response and fallback to this value:

```php
$api_client->cache_lifetime_default = 3600;  
```



### Security considerations: The caching engine

Results are stored in the PSR-6 cache passed to the *JsonApiClient* constructor, using a *cache key* to look up the results next time. 

This *cache key* contains a fast-to-compute **sha256 hash** of the authorization header. The downside is, your auth tokens are vulnerable to *hash collision* attacks, when two different string produce the same hash. 

The auth token hopefully has a baked-in lifetime. Once this lifetime is reached, the auth token is worthless anyway. And, when an attacker has file access to your cache, he will have all results, regardless if he has your auth tokens or not. 

**Security tips:**

- Consider to pass an “Always-empty-cache” or one with very short lifetime, such as [Stash's Ephemeral](http://www.stashphp.com/Drivers.html#ephemeral) driver.
- Store your cache securely. This is not responsibility of this library.
- Clean your items cache often. This is not responsibility of this library.





### Retrieve things

The **JsonApiClient** class is callable; invoking with a URL path and an optional filter values array returns an ***ArrayIterator*** with the documents provided by the API. 

**Caching:** The results are cached in the given *PSR-6 Cache Item Pool*. The cache item TTL depends on the `Cache-Control: max-age=SECONDS` header that came along with the response to the *Guzzle Client* request. The default TTL is 3600 seconds. 

```php
$items = $downloads_client("some/url/path");

foreach( $items as $item):
	print_r( $item );
endforeach;
```

#### Example record

The `print_r( $item )` could reveal something like this:

```text
Array (
    [company] => ACME Corp.
    [brand] => ACME's Best
    [title] => ACME Super Thingy
    ...
)
```



### Filtering results

To narrow down the results, both the *all* and *latest* methods accept an **array with filter values.** The fiter values may contain multiple values, separated with comma. 

Think of the filter array items as `WHERE … AND…` clauses, and comma-separated values as `'a' OR 'b'`

```php
$filters = array(
  'company' => "ACME",
  'category' => "brochure",
  'language' => "en"
);

$items = $downloads_client("some/url/path", $filters);
```





## Errors and Exceptions

Any error or exception during the ask-cache / request / response / results-building phases will first be logged to the PSR-3 Logger passed to the constrcutor. The error caught will be re-thrown as ***JsonApiClientExceptionInterface*** exception instances.

```php
<?php
use Germania\JsonApiClient\JsonApiClientExceptionInterface;

use Germania\JsonApiClient\{
  JsonApiClientCacheException,
  JsonApiClientRequestException,
  JsonApiClientResponseException,
  JsonApiClientResultsException
};
```



### Cache problems

Before the request is actually sent, the *Cache ItemPool* will be asked. In case of any error, a **JsonApiClientCacheException** will be thrown This class implements `JsonApiClientExceptionInterface` and extends `\RuntimeException`. 

### Request problems

When the *JsonApiClient* catches a *Guzzle [**RequestException**](http://docs.guzzlephp.org/en/stable/quickstart.html#exceptions)* or [**TransferExceptions**](http://docs.guzzlephp.org/en/stable/quickstart.html#exceptions), i.e. something wrong with the request or on the server, a **JsonApiClientRequestException** will be thrown. This class implements `JsonApiClientExceptionInterface` and extends `\RuntimeException`. 

### Response problems

The response is expected to be a valid *JsonAPI* response. Whenever the response can't be decoded to a useful array, a  **JsonApiClientResponseException** will be thrown. This class implements `JsonApiClientExceptionInterface` and extends `\UnexpectedValueException`. 

### Result building problems

When there's a problem building the result array, or writing to the cache, a **JsonApiClientResultsException** will be thrown. This class implements `JsonApiClientExceptionInterface` and extends `\RuntimeException`. 



## Unit tests

Copy `phpunit.xml.dist` to `phpunit.xml` and adapt the **API_BASE_URL** and **AUTH_TOKEN** environment variables. Then run [PhpUnit](https://phpunit.de/) like this:

```bash
$ composer test
# or
$ vendor/bin/phpunit
```

