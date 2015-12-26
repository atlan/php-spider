<?php
namespace VDB\Spider;

use Exception;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use VDB\Spider\Discoverer\Discoverer;
use VDB\Spider\Event\SpiderEvents;
use VDB\Spider\Exception\QueueException;
use VDB\Spider\Filter\PostFetchFilter;
use VDB\Spider\Filter\PreFetchFilter;
use VDB\Spider\PersistenceHandler\MemoryPersistenceHandler;
use VDB\Spider\PersistenceHandler\PersistenceHandler;
use VDB\Spider\RequestHandler\GuzzleRequestHandler;
use VDB\Spider\RequestHandler\RequestHandler;
use VDB\Spider\QueueManager\QueueManager;
use VDB\Spider\QueueManager\InMemoryQueueManager;
use VDB\Spider\Uri\FilterableUri;
use VDB\Uri\UriInterface;

/**
 *
 */
class Spider
{
    /** @var RequestHandler */
    private $requestHandler;

    /** @var PersistenceHandler */
    private $persistenceHandler;

    /** @var QueueManager */
    private $queueManager;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    /** @var Discoverer[] */
    private $discoverers = array();

    /** @var PreFetchFilter[] */
    private $preFetchFilters = array();

    /** @var PostFetchFilter[] */
    private $postFetchFilters = array();

    /** @var FilterableUri The URI of the site to spider */
    private $seed = array();

    /** @var string the unique id of this spider instance */
    private $spiderId;

    /** @var array the list of already visited URIs with the depth they were discovered on as value */
    private $alreadySeenUris = array();

    /** @var the maximum number of downloaded resources. 0 means no limit */
    public $downloadLimit = 0;

    /**
     * @param string $seed the URI to start crawling
     * @param string $spiderId
     */
    public function __construct($seed, $spiderId = null)
    {
        $this->setSeed($seed);
        if (null !== $spiderId) {
            $this->spiderId = $spiderId;
        } else {
            $this->spiderId = md5($seed . microtime(true));
        }

        // This makes the spider handle signals gracefully and allows us to do cleanup
        if(php_sapi_name() == 'cli'){
            declare(ticks = 1);
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGTERM, array($this, 'handleSignal'));
                pcntl_signal(SIGINT, array($this, 'handleSignal'));
                pcntl_signal(SIGHUP, array($this, 'handleSignal'));
                pcntl_signal(SIGQUIT, array($this, 'handleSignal'));
            }
        }
    }

    /**
     * Starts crawling the URI provided on instantiation
     *
     * @return array
     */
    public function crawl()
    {
        $this->getQueueManager()->addUri($this->seed);
        $this->getPersistenceHandler()->setSpiderId($this->spiderId);

        $this->doCrawl();
    }

    /**
     * @param Discoverer $discoverer
     */
    public function addDiscoverer(Discoverer $discoverer)
    {
        array_push($this->discoverers, $discoverer);
    }

    /**
     * @param PreFetchFilter $filter
     */
    public function addPreFetchFilter(PreFetchFilter $filter)
    {
        $this->preFetchFilters[] = $filter;
    }

    /**
     * @param PostFetchFilter $filter
     */
    public function addPostFetchFilter(PostFetchFilter $filter)
    {
        $this->postFetchFilters[] = $filter;
    }

    /**
     * @param RequestHandler $requestHandler
     */
    public function setRequestHandler(RequestHandler $requestHandler)
    {
        $this->requestHandler = $requestHandler;
    }

    /**
     * @return RequestHandler
     */
    public function getRequestHandler()
    {
        if (!$this->requestHandler) {
            $this->requestHandler = new GuzzleRequestHandler();
        }

        return $this->requestHandler;
    }

    /**
     * param QueueManager $queueManager
     */
    public function setQueueManager(QueueManager $queueManager)
    {
        $this->queueManager = $queueManager;
    }

    /**
     * @return QueueManager
     */
    public function getQueueManager()
    {
        if (!$this->queueManager) {
            $this->queueManager = new InMemoryQueueManager();
        }

        return $this->queueManager;
    }

    /**
     * @param PersistenceHandler $persistenceHandler
     */
    public function setPersistenceHandler(PersistenceHandler $persistenceHandler)
    {
        $this->persistenceHandler = $persistenceHandler;
    }

    /**
     * @return PersistenceHandler
     */
    public function getPersistenceHandler()
    {
        if (!$this->persistenceHandler) {
            $this->persistenceHandler = new MemoryPersistenceHandler();
        }

        return $this->persistenceHandler;
    }

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @return $this
     */
    public function setDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->dispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getDispatcher()
    {
        if (!$this->dispatcher) {
            $this->dispatcher = new EventDispatcher();
        }
        return $this->dispatcher;
    }

    public function handleSignal($signal)
    {
        switch ($signal) {
        case SIGTERM:
        case SIGKILL:
        case SIGINT:
        case SIGQUIT:
            $this->dispatch(SpiderEvents::SPIDER_CRAWL_USER_STOPPED);
        }
    }

    /**
     * @param Resource $resource
     * @return bool
     */
    private function matchesPostfetchFilter(Resource $resource)
    {
        foreach ($this->postFetchFilters as $filter) {
            if ($filter->match($resource)) {
                $this->dispatch(
                    SpiderEvents::SPIDER_CRAWL_FILTER_POSTFETCH,
                    new GenericEvent($this, array('uri' => $resource->getUri()))
                );
                return true;
            }
        }
        return false;
    }

    /**
     * @param FilterableUri $uri
     * @return bool
     */
    private function matchesPrefetchFilter(FilterableUri $uri)
    {
        foreach ($this->preFetchFilters as $filter) {
            if ($filter->match($uri)) {
                $this->dispatch(
                    SpiderEvents::SPIDER_CRAWL_FILTER_PREFETCH,
                    new GenericEvent($this, array('uri' => $uri))
                );
                return true;
            }
        }
        return false;
    }

    private function isDownLoadLimitExceeded()
    {
        if ($this->downloadLimit !== 0 && $this->getPersistenceHandler()->count() >= $this->downloadLimit) {
            return true;
        }
        return false;
    }
    /**
     * Function that crawls each provided URI
     * It applies all processors and listeners set on the Spider
     *
     * This is a either depth first algorithm as explained here:
     *  https://en.wikipedia.org/wiki/Depth-first_search#Example
     * Note that because we don't do it recursive, but iteratively,
     * results will be in a different order from the example, because
     * we always take the right-most child first, whereas a recursive
     * variant would always take the left-most child first
     *
     * or
     *
     * a breadth first algorithm
     *
     * @return void
     */
    private function doCrawl()
    {
        while ($currentUri = $this->getQueueManager()->next()) {
            if ($this->isDownLoadLimitExceeded()) {
                break;
            }

            // Fetch the document
            if (!$resource = $this->fetchResource($currentUri)) {
                continue;
            }

            $this->getPersistenceHandler()->persist($resource);

            $this->dispatch(
                SpiderEvents::SPIDER_CRAWL_RESOURCE_PERSISTED,
                new GenericEvent($this, array('uri' => $currentUri))
            );

            $nextLevel = $resource->depthFound + 1;
            if ($nextLevel > $this->getQueueManager()->maxDepth) {
                continue;
            }

            // Once the document is enqueued, apply the discoverers to look for more links to follow
            $discoveredUris = $this->executeDiscoverers($resource);

            foreach ($discoveredUris as $uri) {
                // normalize the URI
                $uri->normalize();

                // Decorate the link to make it filterable
                $uri = new FilterableUri($uri->toString());

                // Always skip nodes we already visited
                if (array_key_exists($uri->toString(), $this->alreadySeenUris)) {
                    continue;
                }

                if (!$this->matchesPrefetchFilter($uri)) {
                    // The URI was not matched by any filter: add to queue
                    try {
                        $this->getQueueManager()->addUri($uri);
                    } catch (QueueException $e) {
                        // when the queue size is exceeded, we stop discovering
                        break;
                    }
                }

                // filtered or queued: mark as seen
                $this->alreadySeenUris[$uri->toString()] = $resource->depthFound + 1;
            }
        }
    }

    /**
     * @param Resource $resource
     * @return UriInterface[]
     */
    protected function executeDiscoverers(Resource $resource)
    {
        $discoveredUris = array();

        foreach ($this->discoverers as $discoverer) {
            $discoveredUris = array_merge($discoveredUris, $discoverer->discover($this, $resource));
        }

        $this->deduplicateUris($discoveredUris);

        return $discoveredUris;
    }

    /**
     * @param UriInterface $uri
     * @return bool|Resource
     */
    protected function fetchResource(UriInterface $uri)
    {
        $this->dispatch(SpiderEvents::SPIDER_CRAWL_PRE_REQUEST, new GenericEvent($this, array('uri' => $uri)));

        try {
            $resource = $this->getRequestHandler()->request($uri);
            $resource->depthFound = $this->alreadySeenUris[$uri->toString()];

            $this->dispatch(SpiderEvents::SPIDER_CRAWL_POST_REQUEST, new GenericEvent($this, array('uri' => $uri))); // necessary until we have 'finally'

            if ($this->matchesPostfetchFilter($resource)) {
                return false;
            }

            return $resource;
        } catch (\Exception $e) {
            $this->dispatch(
                SpiderEvents::SPIDER_CRAWL_ERROR_REQUEST,
                new GenericEvent($this, array('uri' => $uri, 'message' => $e->getMessage()))
            );

            $this->dispatch(SpiderEvents::SPIDER_CRAWL_POST_REQUEST, new GenericEvent($this, array('uri' => $uri))); // necessary until we have 'finally'

            return false;
        }
    }

    /**
     * A shortcut for EventDispatcher::dispatch()
     *
     * @param string $eventName
     * @param Event $event
     */
    private function dispatch($eventName, Event $event = null)
    {
        $this->getDispatcher()->dispatch($eventName, $event);
    }

    /**
     * @param UriInterface[] $discoveredUris
     */
    private function deduplicateUris(array &$discoveredUris)
    {
        // make sure there are no duplicates in the list
        $tmp = array();
        /** @var Uri $uri */
        foreach ($discoveredUris as $k => $uri) {
            $tmp[$k] = $uri->toString();
        }

        // Find duplicates in temporary array
        $tmp = array_unique($tmp);

        // Remove the duplicates from original array
        foreach ($discoveredUris as $k => $uri) {
            if (!array_key_exists($k, $tmp)) {
                unset($discoveredUris[$k]);
            }
        }
    }

    /**
     * @param string $uri
     */
    private function setSeed($uri)
    {
        $this->seed = new FilterableUri($uri);
        $this->alreadySeenUris[$this->seed->normalize()->toString()] = 0;
    }
}
