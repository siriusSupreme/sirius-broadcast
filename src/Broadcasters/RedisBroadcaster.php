<?php

namespace Sirius\Broadcast\Broadcasters;

use Sirius\Support\Arr;
use Sirius\Support\Str;
use Sirius\Redis\Contracts\Factory as Redis;
use Sirius\Broadcast\Exceptions\AccessDeniedHttpException;

class RedisBroadcaster extends Broadcaster
{
    /**
     * The Redis instance.
     *
     * @var \Sirius\Redis\Contracts\Factory
     */
    protected $redis;

    /**
     * The Redis connection to use for broadcasting.
     *
     * @var string
     */
    protected $connection;

    /**
     * Create a new broadcaster instance.
     *
     * @param  \Sirius\Redis\Contracts\Factory  $redis
     * @param  string  $connection
     *
     * @return void
     */
    public function __construct(Redis $redis, $connection = null)
    {
        $this->redis = $redis;
        $this->connection = $connection;
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Psr\Http\Message\RequestInterface  $request
     * @return mixed
     * @throws \Sirius\Broadcast\Exceptions\AccessDeniedHttpException
     */
    public function auth($request)
    {
        if (Str::startsWith($request->channel_name, ['private-', 'presence-']) &&
            ! $request->user()) {
            throw new AccessDeniedHttpException;
        }

        $channelName = Str::startsWith($request->channel_name, 'private-')
                            ? Str::replaceFirst('private-', '', $request->channel_name)
                            : Str::replaceFirst('presence-', '', $request->channel_name);

        return parent::verifyUserCanAccessChannel(
            $request, $channelName
        );
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Psr\Http\Message\RequestInterface  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        if (is_bool($result)) {
            return json_encode($result);
        }

        return json_encode(['channel_data' => [
            'user_id' => $request->user()->getAuthIdentifier(),
            'user_info' => $result,
        ]]);
    }

    /**
     * Broadcast the given event.
     *
     * @param  array  $channels
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        $connection = $this->redis->connection($this->connection);

        $payload = json_encode([
            'event' => $event,
            'data' => $payload,
            'socket' => Arr::pull($payload, 'socket'),
        ]);

        foreach ($this->formatChannels($channels) as $channel) {
            $connection->publish($channel, $payload);
        }
    }
}
