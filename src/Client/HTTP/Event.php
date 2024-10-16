<?php

  /**
   * quarxConnect Events - Base-Event for HTTP-Client-Events
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
  
  namespace quarxConnect\Events\Client\HTTP;

  use InvalidArgumentException;

  use quarxConnect\Events\ABI\Event as EventInterface;
  use quarxConnect\Events\Client\HTTP as HttpClient;
  use quarxConnect\Events\Stream\HTTP\Request as HttpRequest;
  
  abstract class Event implements EventInterface {
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

    // {{{ __construct
    /**
     * Create a new HTTP-Client-Event
     *
     * @param HttpClient $httpClient The HTTP-Client this event was dispatched at
     * @param HttpRequest $httpRequest The HTTP-Request that caused the event
     *
     * @return void
     **/
    public function __construct (HttpClient $httpClient, HttpRequest $httpRequest)
    {
      $this->httpClient = $httpClient;
      $this->httpRequest = $httpRequest;
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
