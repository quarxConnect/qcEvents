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

  class Result implements ABI\Event {
    /**
     * Instance of the original DNS-Query
     *
     * @var DNS\Message
     **/
    private DNS\Message $dnsQuery;

    /**
     * Instance of the received DNS-Response
     *
     * @var DNS\Message
     **/
    private DNS\Message $dnsResponse;

    /**
     * DNS64-Prefix to emulate AAAA-Records on the response
     *
     * @var string|null
     **/
    private string|null $dns64Prefix;

    // {{{ __construct
    /**
     * Create a new DNS-Result-Event
     *
     * @param DNS\Message $dnsQuery
     * @param DNS\Message $dnsResponse
     * @param string|null $dns64Prefix
     *
     * @access friendly
     * @return void
     **/
    public function __construct (DNS\Message $dnsQuery, DNS\Message $dnsResponse, string $dns64Prefix = null) {
      $this->dnsQuery = $dnsQuery;
      $this->dnsResponse = $dnsResponse;
      $this->dns64Prefix = $dns64Prefix;
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

    // {{{ getAnswers
    /**
     * Retrieve the answers from this response
     *
     * @access public
     * @return DNS\Recordset
     **/
    public function getAnswers (): DNS\Recordset {
      $dnsAnswers = $this->dnsResponse->getAnswers ();

      if ($this->dns64Prefix === null)
        return $dnsAnswers;

      foreach ($dnsAnswers as $dnsAnswer)
        if ($dnsAnswer instanceof DNS\Record\A) {
          $dnsAnswers [] = $AAAA = new DNS\Record\AAAA (
            $dnsAnswer->getLabel (),
            $dnsAnswer->getTTL (),
            null,
            $dnsAnswer->getClass ()
          );

          $ipAddress = dechex (ip2long ($dnsAnswer->getAddress ()));

          $AAAA->setAddress ('[' . $this->dns64Prefix . (strlen ($ipAddress) > 4 ? substr ($ipAddress, 0, -4) . ':' : '') . substr ($ipAddress, -4, 4) . ']');
        }

      return $dnsAnswers;
    }
    // }}}
  }
