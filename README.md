# Guzzle regexp mock plugin

The mock plugin is useful for testing Guzzle clients. The mock plugin allows you to queue an array of responses that will satisfy requests sent from a client by consuming the request queue in FIFO order. Each request may have optional regexp url match patter.

_Based on standard mock plugin - http://guzzle3.readthedocs.org/plugins/mock-plugin.html_

```php
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;
use Emoe\GuzzleRegexpMockPlugin\MockPlugin;

$client = new Client('http://www.test.com/');

$mock = new MockPlugin();
$mock->addResponse(new Response(200), '/(foo|bar)page/')
     ->addResponse(new Response(200), '/article\/\w+/')
     ->addResponse(new Response(404)); // regexp pattern is optional

// Add the mock plugin to the client object
$client->addSubscriber($mock);

// The following request will receive a 200 response from the plugin regexp queue
$client->get('/foopage')->send();

// The following request will receive a 404 response from the plugin, default behaviour
$client->get('notfound')->send();

// The following request will receive a 200 response from the plugin regexp queue
$client->get('/article/about')->send();
```