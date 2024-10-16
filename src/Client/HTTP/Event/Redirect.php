<?php

  /**
   * quarxConnect Events - Event when a HTTP-Redirect was received
   * Copyright (C) 2009-2024 Bernd Holzmueller <bernd@quarxconnect.de>
   *
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   *
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Client\HTTP\Event;

  use quarxConnect\Events\Client\HTTP\Event as BaseEvent;
  use quarxConnect\Events\Client\HTTP as HttpClient;
  use quarxConnect\Events\Stream\HTTP\Request as HttpRequest;
  use quarxConnect\Events\Stream\HTTP\Header as HttpHeader;
  
  class Redirect extends BaseEvent {
    /**
     * The location the request should be redirected to
     *
     * @var string
     **/
    protected string $redirectLocation;

    /**
     * Header received as response to our request
     *
     * @var HttpHeader
     **/
    protected HttpHeader $responseHeader;

    /**
     * Body received as response to our request
     *
     * @var string|null
     */
    protected string|null $responseBody;

    // {{{ __construct
    /**
     * Create a new HTTP-Client-Event
     *
     * @param HttpClient $httpClient The HTTP-Client this event was dispatched at
     * @param HttpRequest $httpRequest The HTTP-Request that caused the event
     * @param HttpHeader $responseHeader The Response-Header received from the server
     * @param string $responseBody The Response-Body received from the server
     *
     * @return void
     **/
    public function __construct (HttpClient $httpClient, HttpRequest $httpRequest, string $redirectLocation, HttpHeader $responseHeader, string $responseBody = null)
    {
      parent::__construct ($httpClient, $httpRequest);
      
      $this->redirectLocation = $redirectLocation;
      $this->responseHeader = $responseHeader;
      $this->responseBody = $responseBody;
    }
    // }}}
  }
