# MaibAPI
Maib online payments php SDK

## Installing

```bash
composer require fruitware/maib-api
```

## Usage

```php
namespace MyProject;
require_once(__DIR__ . '/vendor/autoload.php');

use Fruitware\MaibApi\MaibClient;
use Fruitware\MaibApi\MaibDescription;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Log\Formatter;
use GuzzleHttp\Subscriber\Log\LogSubscriber;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

//set options
$options = [
	'base_url' => 'https://ecomm.maib.md:4455',
	'debug'  => true,
	'verify' => false,
	'defaults' => [
		'verify' => __DIR__.'/cert/cacert.pem',
		'cert'    => [__DIR__.'/cert/pcert.pem', 'Pem_pass'],
		'ssl_key' => __DIR__.'/cert/key.pem',
		'config'  => [
			'curl'  =>  [
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => false,
			]
		]
	],
];

// init Client
$guzzleClient = new Client($options);

// create a log for client class, if you want (monolog/monolog required)
$log = new Logger('maib_guzzle_request');
$log->pushHandler(new StreamHandler(__DIR__.'/logs/maib_guzzle_request.log', Logger::DEBUG));
$subscriber = new LogSubscriber($log, Formatter::SHORT);

$client = new MaibClient($guzzleClient);
$client->getHttpClient()->getEmitter()->attach($subscriber);
// examples

//register sms transaction
var_dump($client->registerSmsTransaction('1', 978, '127.0.0.1', '', 'ru'));

//register dms authorization
var_dump($client->registerDmsAuthorization('1', 978, '127.0.0.1', '', 'ru'));

//execute dms transaction
var_dump($client->makeDMSTrans('1', '1', 978, '127.0.0.1', '', 'ru'));

//get transaction result
var_dump($client->getTransactionResult('1', '127.0.0.1'));

//revert transaction
var_dump($client->revertTransaction('1', '1'));

//close business day
var_dump($client->closeDay());


```