<?php

namespace Sirius\Broadcast\Broadcasters;

use Pusher\Pusher;
use Sirius\Support\Arr;
use Sirius\Support\Str;
use Sirius\Broadcast\Exceptions\BroadcastException;
use Sirius\Broadcast\Exceptions\AccessDeniedHttpException;

class PusherBroadcaster extends Broadcaster
{
    /**
     * The Pusher SDK instance.
     *
     * @var \Pusher\Pusher
     */
    protected $pusher;

    /**
     * Create a new broadcaster instance.
     *
     * @param  \Pusher\Pusher  $pusher
     *
     */
    public function __construct(Pusher $pusher)
    {
        $this->pusher = $pusher;
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
        if (Str::startsWith($request->channel_name, 'private')) {
            return $this->decodePusherResponse(
                $this->pusher->socket_auth($request->channel_name, $request->socket_id)
            );
        }

        return $this->decodePusherResponse(
            $this->pusher->presence_auth(
                $request->channel_name, $request->socket_id, $request->user()->getAuthIdentifier(), $result)
        );
    }

    /**
     * Decode the given Pusher response.
     *
     * @param  mixed  $response
     * @return array
     */
    protected function decodePusherResponse($response)
    {
        return json_decode($response, true);
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
        $socket = Arr::pull($payload, 'socket');

        $response = $this->pusher->trigger(
            $this->formatChannels($channels), $event, $payload, $socket, true
        );

        if ((is_array($response) && $response['status'] >= 200 && $response['status'] <= 299)
            || $response === true) {
            return;
        }

        throw new BroadcastException(
            is_bool($response) ? 'Failed to connect to Pusher.' : $response['body']
        );
    }

    /**
     * Get the Pusher SDK instance.
     *
     * @return \Pusher\Pusher
     */
    public function getPusher()
    {
        return $this->pusher;
    }
}
