<?php

  /**
   * quarxConnect Events - Base-Event for Cookie-Events on HTTP-Client
   * Copyright (C) 2009-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2024 Bernd Holzmueller <bernd@innorize.gmbh>
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

  use InvalidArgumentException;

  use quarxConnect\Events\ABI\Event as EventInterface;
  use quarxConnect\Events\Client\HTTP as HttpClient;
  use quarxConnect\Events\Stream\HTTP\Cookie as HttpCookie;
  use quarxConnect\Events\Stream\HTTP\Header as HttpHeader;
  use quarxConnect\Events\Stream\HTTP\Request as HttpRequest;
  
  abstract class Cookie implements EventInterface {
    /**
     * HTTP-Client where the event was dispatched
     *
     * @var HttpClient
     **/
    protected HttpClient $httpClient;

    /**
     * HTTP-Request for the event
     *
     * @var HttpRequest
     **/
    protected HttpRequest $httpRequest;

    /**
     * HTTP-Response that contained the cookie
     *
     * @var HttpHeader
     **/
    protected HttpHeader $httpResponse;

    /**
     * The Cookie related to this event
     *
     * @var HttpCookie
     */
    protected HttpCookie $eventCookie;

    // {{{ __construct
    /**
     * Create a new HTTP-Client-Event
     *
     * @param HttpClient $httpClient The HTTP-Client this event was dispatched at
     * @param HttpRequest $httpRequest The HTTP-Request that caused the event
     * @param HttpHeader $httpResponse The HTTP-Response that contained the cookie
     * @param HttpCookie $eventCookie The cookie related to this event
     **/
    public function __construct (HttpClient $httpClient, HttpRequest $httpRequest, HttpHeader $httpResponse, HttpCookie $eventCookie)
    {
      $this->httpClient = $httpClient;
      $this->httpRequest = $httpRequest;
      $this->httpResponse = $httpResponse;
      $this->eventCookie = $eventCookie;
    }
    // }}}

    // {{{ __get
    /**
     * Access read-only properties of this event
     *
     * @param string $propertyName
     *
     * @access public
     * @return mixed
     **/
    public function __get (string $propertyName): mixed
    {
      if (!property_exists ($this, $propertyName))
        throw new InvalidArgumentException ('Invalid property: ' . $propertyName);

      return $this->$propertyName;
    }
    // }}}
  }
