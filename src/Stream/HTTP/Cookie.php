<?php

  /**
   * quarxConnect Events - HTTP Cookie
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
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types=1);

  namespace quarxConnect\Events\Stream\HTTP;

  class Cookie {
    public string $cookieName;
    public string $cookieValue;

    public bool $cookieEncodeValue = false;

    public int|null $cookieExpires = null;
    public string $cookieDomain = '';
    public bool $cookieOrigin = true;
    public string $cookiePath = '/';
    public bool $cookieSecure = false;

    // {{{ __construct
    /**
     * Create a new cookie-instance
     *
     * @param string $cookieName
     * @param string $cookieValue
     **/
    public function __construct (string $cookieName, string $cookieValue = '')
    {
      $this->cookieName = $cookieName;
      $this->cookieValue = $cookieValue;
    }
    // }}}

    // {{{ isExpired
    /**
     * Check if this cookie is expired
     *
     * @access public
     * @return boolean
     **/
    public function isExpired (): bool
    {
      return (
        ($this->cookieExpires !== null) &&
        ($this->cookieExpires < time ())
      );
    }
    // }}}

    // {{{ isDeleted
    /**
     * Check if the cookie is marked as deleted
     *
     * @access public
     * @return boolean
     **/
    public function isDeleted (): bool
    {
      return (strcmp ($this->cookieValue, 'deleted') === 0);
    }
    // }}}

    // {{{ compareId
    /**
     * Check if another cookie has the same id as this one
     *
     * @param Cookie $withCookie
     *
     * @access public
     * @return boolean
     **/
    public function compareId (Cookie $withCookie): bool
    {
      // Compare the names
      if (strcmp ($this->cookieName, $withCookie->cookieName) !== 0)
        return false;

      // Compare the path
      if (strcmp ($this->cookiePath, $withCookie->cookiePath) !== 0)
        return false;
      
      // Compare the domain
      if (
        (strcasecmp ($this->cookieDomain, $withCookie->cookieDomain) !== 0) ||
        ($this->cookieOrigin !== $withCookie->cookieOrigin)
      )
        return false;
      
      // Seems to be equal
      return true;
    }
    // }}}

    // {{{ compareValue
    /**
     * Check if another cookie has the same value as this one
     *
     * @param Cookie $withCookie
     *
     * @access public
     * @return boolean
     **/
    public function compareValue (Cookie $withCookie): bool
    {
      return (
        ($this->cookieValue === $withCookie->cookieValue) &&
        ($this->cookieSecure === $withCookie->cookieSecure) &&
        ($this->cookieExpires === $withCookie->cookieExpires)
      );
    }
    // }}}

    // {{{ matchDomain
    /**
     * Check if a given domain matches with the domain-spec of this cookie
     * 
     * @param string $matchDomain
     * 
     * @access public
     * @return boolean
     **/
    public function matchDomain (string $matchDomain): bool
    {
      return (
        (
          !$this->cookieOrigin &&
          (strcasecmp (substr ($matchDomain, -strlen ($this->cookieDomain), strlen ($this->cookieDomain)), $this->cookieDomain) === 0)
        ) || (
          $this->cookieOrigin &&
          (strcasecmp ($matchDomain, $this->cookieDomain) === 0)
        )
      );
    }
    // }}}

    // {{{ matchPath
    /**
     * Check if a given path is covered by this cookie
     *
     * @param string $matchPath
     *
     * @access public
     * @return boolean
     **/
    public function matchPath (string $matchPath): bool
    {
      return (strncmp ($this->cookiePath, $matchPath, strlen ($this->cookiePath)) === 0);
    }
    // }}}
  }
