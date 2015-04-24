<?php

namespace Emoe\GuzzleRegexpMockPlugin;

use Guzzle\Common\Event;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues mock responses or exceptions and delivers mock responses or exceptions in a fifo order.
 */
class MockPlugin extends AbstractHasDispatcher implements EventSubscriberInterface, \Countable
{
    const MATCH_ALL_URL_PATTERN = '/.*/';

    /** @var array Array of mock responses / exceptions */
    protected $queue = [];

    /** @var bool Whether or not to remove the plugin when the queue is empty */
    protected $temporary = false;

    /** @var array Array of requests that were mocked */
    protected $received = [];

    /** @var bool Whether or not to consume an entity body when a mock response is served */
    protected $readBodies;

    /**
     * @param array $items      Array of responses or exceptions to queue
     * @param bool  $temporary  Set to TRUE to remove the plugin when the queue is empty
     * @param bool  $readBodies Set to TRUE to consume the entity body when a mock is served
     */
    public function __construct(array $items = null, $temporary = false, $readBodies = false)
    {
        $this->readBodies = $readBodies;
        $this->temporary = $temporary;
        if ($items) {
            foreach ($items as $urlPattern => $response) {
                if ($response instanceof \Exception) {
                    $this->addException($urlPattern, $response);
                } else {
                    $this->addResponse($urlPattern, $response);
                }
            }
        }
    }

    public static function getSubscribedEvents()
    {
        // Use a number lower than the CachePlugin
        return ['request.before_send' => ['onRequestBeforeSend', -999]];
    }

    public static function getAllEvents()
    {
        return ['mock.request'];
    }

    /**
     * Get a mock response from a file.
     *
     * @param string $path File to retrieve a mock response from
     *
     * @return Response
     *
     * @throws InvalidArgumentException if the file is not found
     */
    public static function getMockFile($path)
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException('Unable to open mock file: '.$path);
        }

        return Response::fromMessage(file_get_contents($path));
    }

    /**
     * Set whether or not to consume the entity body of a request when a mock
     * response is used.
     *
     * @param bool $readBodies Set to true to read and consume entity bodies
     *
     * @return self
     */
    public function readBodies($readBodies)
    {
        $this->readBodies = $readBodies;

        return $this;
    }

    /**
     * Returns the number of remaining mock responses.
     *
     * @return int
     */
    public function count()
    {
        return count($this->queue, COUNT_RECURSIVE) - count($this->queue);
    }

    /**
     * Add a response to the end of the queue.
     *
     * @param string|Response $response   Response object or path to response file
     * @param string|null     $urlPattern
     *
     * @return MockPlugin
     *
     * @throws InvalidArgumentException if a string or Response is not passed
     */
    public function addResponse($response, $urlPattern = null)
    {
        if (!$urlPattern) {
            $urlPattern = self::MATCH_ALL_URL_PATTERN;
        }

        if (!($response instanceof Response)) {
            if (!is_string($response)) {
                throw new InvalidArgumentException('Invalid response');
            }
            $response = self::getMockFile($response);
        }

        $this->queue[$urlPattern][] = $response;

        return $this;
    }

    /**
     * Add an exception to the end of the queue.
     *
     * @param CurlException $e          Exception to throw when the request is executed
     * @param string|null   $urlPattern
     *
     * @return MockPlugin
     */
    public function addException(CurlException $e, $urlPattern = null)
    {
        if (!$urlPattern) {
            $urlPattern = self::MATCH_ALL_URL_PATTERN;
        }

        $this->queue[$urlPattern][] = $e;

        return $this;
    }

    /**
     * Clear the queue.
     *
     * @return MockPlugin
     */
    public function clearQueue()
    {
        $this->queue = [];

        return $this;
    }

    /**
     * Returns an array of mock responses remaining in the queue.
     *
     * @return array
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Check if this is a temporary plugin.
     *
     * @return bool
     */
    public function isTemporary()
    {
        return $this->temporary;
    }

    /**
     * Dequeue response from list by requested url.
     *
     * @param string $url
     *
     * @return Response|CurlException|void
     */
    protected function dequeue($url)
    {
        foreach ($this->queue as $pattern => $list) {
            if (preg_match($pattern, $url)) {
                $response = array_shift($this->queue[$pattern]);
                if (!count($this->queue[$pattern])) {
                    unset($this->queue[$pattern]);
                }

                return $response;
            }
        }

        return;
    }

    /**
     * Get a response from the front of the list and add it to a request.
     *
     * @param RequestInterface $request Request to mock
     *
     * @return self
     *
     * @throws CurlException When request.send is called and an exception is queued
     */
    public function proceed(RequestInterface $request)
    {
        $this->dispatch('mock.request', ['plugin' => $this, 'request' => $request]);

        $response = $this->dequeue($request->getUrl());

        if (!$response) {
            throw new \OutOfBoundsException('Mock queue for given url is empty');
        }

        if ($response instanceof Response) {
            if ($this->readBodies && $request instanceof EntityEnclosingRequestInterface) {
                $request->getEventDispatcher()->addListener('request.sent', $f = function (Event $event) use (&$f) {
                    // @codingStandardsIgnoreStart
                    while ($data = $event['request']->getBody()->read(8096));
                    // @codingStandardsIgnoreEnd
                    // Remove the listener after one-time use
                    $event['request']->getEventDispatcher()->removeListener('request.sent', $f);
                });
            }
            $request->setResponse($response);
        } elseif ($response instanceof CurlException) {
            // Emulates exceptions encountered while transferring requests
            $response->setRequest($request);
            $state = $request->setState(RequestInterface::STATE_ERROR, ['exception' => $response]);
            // Only throw if the exception wasn't handled
            if ($state == RequestInterface::STATE_ERROR) {
                throw $response;
            }
        }

        return $this;
    }

    /**
     * Clear the array of received requests.
     */
    public function flush()
    {
        $this->received = [];
    }

    /**
     * Get an array of requests that were mocked by this plugin.
     *
     * @return array
     */
    public function getReceivedRequests()
    {
        return $this->received;
    }

    /**
     * Called when a request is about to be sent.
     *
     * @param Event $event
     *
     * @throws \OutOfBoundsException When queue is empty
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];
        $this->received[] = $request;

        $this->proceed($request);
        // Detach the filter from the client so it's a one-time use
        if ($this->temporary && !$this->count() && $request->getClient()) {
            $request->getClient()->getEventDispatcher()->removeSubscriber($this);
        }
    }
}
