<?php

  /**
   * quarxConnect Events - DNS Client Result Event
   * Copyright (C) 2014-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Client\DNS\Event;

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Stream\DNS;

  class Timeout implements ABI\Event {
    /**
     * Instance of the original DNS-Query
     *
     * @var DNS\Message
     **/
    private DNS\Message $dnsQuery;

    // {{{ __construct
    /**
     * Create a new DNS-Timeout-Event
     *
     * @param DNS\Message $dnsQuery
     *
     * @access friendly
     * @return void
     **/
    public function __construct (DNS\Message $dnsQuery) {
      $this->dnsQuery = $dnsQuery;
    }
    // }}}

    // {{{ getHostname
    /**
     * Retrieve the hostname that was asked for
     *
     * @access public
     * @return string|null
     **/
    public function getHostname (): ?string {
      $queriedHostnames = $this->dnsQuery->getQuestions ();

      if (count ($queriedHostnames) === 0)
        return null;

      $firstHostname = array_shift ($queriedHostnames);

      return $firstHostname->getLabel ();
    }
    // }}}
  }
