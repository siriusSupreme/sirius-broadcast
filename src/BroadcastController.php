<?php

namespace Sirius\Broadcast;

use Psr\Http\Message\RequestInterface;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Broadcast;

class BroadcastController extends Controller
{
    /**
     * Authenticate the request for channel access.
     *
     * @param  \Psr\Http\Message\RequestInterface  $request
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function authenticate( $request)
    {
        return Broadcast::auth($request);
    }
}
