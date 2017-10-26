<?php

namespace Sirius\Broadcast;

use Closure;
use Pusher\Pusher;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use Sirius\Broadcast\Broadcasters\LogBroadcaster;
use Sirius\Broadcast\Broadcasters\NullBroadcaster;
use Sirius\Broadcast\Contracts\ShouldBroadcastNow;
use Sirius\Broadcast\Broadcasters\RedisBroadcaster;
use Sirius\Broadcast\Broadcasters\PusherBroadcaster;
use Sirius\Broadcast\Contracts\Factory as FactoryContract;
use Sirius\Redis\RedisManager;
use Sirius\Event\Dispatcher;
use Sirius\Support\Contracts\Repository;
use Sirius\Support\Repository as Config;

/**
 * @mixin \Sirius\Broadcast\Contracts\Broadcaster
 */
class BroadcastManager implements FactoryContract
{
    /**
     * The application instance.
     *
     * @var \Sirius\Container\Container
     */
    protected $container;

  /**
   * Config（Repository）
   *
   * @var \Sirius\Support\Contracts\Repository|null
   */
    protected $config=null;

  /**
   * 广播 管理器 实例
   *
   * @var null|self
   */
    private static $instance=null;

    /**
     * The array of resolved broadcast drivers.
     *
     * @var array
     */
    protected $drivers = [];

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators = [];

    /**
     * Create a new manager instance.
     *
     * @param  \Sirius\Container\Container  $container
     * @param  \Sirius\Support\Contracts\Repository|array
     *
     */
    public function __construct($container,$config=[])
    {
//      加载默认配置
      $defaults=require __DIR__.'/config.php';
//      配置 数组化
      if ($config instanceof Repository){
        $config=$config->all();
      }else{
        $config=(array) $config;
      }
//      合并配置
      $config=array_merge( $defaults,$config);

      $this->config=new Config($config);

        $this->container = $container;

    }

  /**
   * 获取 广播 管理器 实例
   *
   * @param $container
   * @param \Sirius\Support\Contracts\Repository|array $config
   * @param bool $force
   *
   * @return null|BroadcastManager
   */
    public static function getInstance( $container, $config = [],$force=false){
        if (is_null( self::$instance) || $force===true){
          self::$instance=new self( $container, $config);
        }

        return self::$instance;
    }


    /**
     * Get the socket ID for the given request.
     *
     * @param  \Psr\Http\Message\RequestInterface|null  $request
     * @return string|null
     */
    public function socket($request = null)
    {
        if (! $request && ! $this->container->bound('request')) {
            return null;
        }

        $request = $request ?: $this->container['request'];

        return $request->header('X-Socket-ID');
    }

    /**
     * Begin broadcasting an event.
     *
     * @param  mixed|null  $event
     * @return \Sirius\Broadcast\PendingBroadcast
     */
    public function event($event = null)
    {
        return new PendingBroadcast(new Dispatcher(), $event);
    }

    /**
     * Queue the given event for broadcast.
     *
     * @param  mixed  $event
     * @return void
     */
    public function queue($event)
    {
        $connection = $event instanceof ShouldBroadcastNow ? 'sync' : null;

        if (is_null($connection) && isset($event->connection)) {
            $connection = $event->connection;
        }

        $queue = null;

        if (method_exists($event, 'broadcastQueue')) {
            $queue = $event->broadcastQueue();
        } elseif (isset($event->broadcastQueue)) {
            $queue = $event->broadcastQueue;
        } elseif (isset($event->queue)) {
            $queue = $event->queue;
        }

        $this->container->make('queue')->connection($connection)->pushOn(
            $queue, new BroadcastEvent(clone $event)
        );
    }

    /**
     * Get a driver instance.
     *
     * @param  string  $driver
     * @return mixed
     */
    public function connection($driver = null)
    {
        return $this->driver($driver);
    }

    /**
     * Get a driver instance.
     *
     * @param  string  $name
     * @return mixed
     */
    public function driver($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->drivers[$name] = $this->get($name);
    }

    /**
     * Attempt to get the connection from the local cache.
     *
     * @param  string  $name
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     */
    protected function get($name)
    {
        return $this->drivers[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * @param  string  $name
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Broadcaster [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (! method_exists($this, $driverMethod)) {
            throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
        }

        return $this->{$driverMethod}($config);
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array  $config
     * @return mixed
     */
    protected function callCustomCreator(array $config)
    {
        return $this->customCreators[$config['driver']]($this->container, $config);
    }

    /**
     * Create an instance of the driver.
     *
     * @param  array  $config
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     */
    protected function createPusherDriver(array $config)
    {
        return new PusherBroadcaster(
            new Pusher($config['key'], $config['secret'],
            $config['app_id'], $config['options'] ?? [])
        );
    }

    /**
     * Create an instance of the driver.
     *
     * @param  array  $config
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     */
    protected function createRedisDriver(array $config)
    {
        return new RedisBroadcaster(
            new RedisManager( 'phpredis', $config), $config['connection'] ?? null
        );
    }

    /**
     * Create an instance of the driver.
     *
     * @param  array  $config
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     */
    protected function createLogDriver(array $config)
    {
        return new LogBroadcaster(
            $this->container->make(LoggerInterface::class)
        );
    }

    /**
     * Create an instance of the driver.
     *
     * @param  array  $config
     * @return \Sirius\Broadcast\Contracts\Broadcaster
     */
    protected function createNullDriver(array $config)
    {
        return new NullBroadcaster;
    }

    /**
     * Get the connection configuration.
     *
     * @param  string  $name
     * @return array
     */
    protected function getConfig($name)
    {
        return $this->config["connections.{$name}"];
    }

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->config['default'];
    }

    /**
     * Set the default driver name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultDriver($name)
    {
        $this->config['default'] = $name;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string    $driver
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->driver()->$method(...$parameters);
    }
}
