<?php

  /**
   * quarxConnect Events - DNS MX Resource Record
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\DNS\Record;

  use InvalidArgumentException;
  use quarxConnect\Events\Stream\DNS;
  use RangeException;

  class MX extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x0F;

    /* Priority of this record */
    private int $Priority = 0;

    /* The hostname assigned to this record */
    private DNS\Label|null $destinationHost = null;

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string  
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' MX ' . $this->Priority . ' ' . $this->destinationHost;
    }
    // }}}

    // {{{ getPriority
    /**
     * Retrieve a priority assigned to this record
     *
     * @access public
     * @return int   
     **/
    public function getPriority (): int
    {
      return $this->Priority;
    }
    // }}}

    // {{{ setPriority
    /**
     * Set the priority of this record
     *
     * @param int $Priority
     * 
     * @access public
     * @return void
     **/
    public function setPriority (int $Priority): void
    {
      if (($Priority < 1) || ($Priority > 0xFFFF))
        throw new RangeException ('Must be an 16-bit integer');

      $this->Priority = $Priority;
    }
    // }}}

    // {{{ getHostname
    /**
     * Retrieve the hostname assigned to this record
     *
     * @access public
     * @return DNS\Label
     **/
    public function getHostname (): DNS\Label
    {
      return $this->destinationHost;
    }
    // }}}

    // {{{ setHostname
    /**
     * Store a hostname on this record
     *
     * @param DNS\Label $destinationHost
     *
     * @access public
     * @return void
     **/
    public function setHostname (DNS\Label $destinationHost): void
    {
      $this->destinationHost = $destinationHost;
    }
    // }}}

    // {{{ parsePayload
    /**
     * Parse a given payload
     *
     * @param string $dnsData
     * @param int $dataOffset
     * @param int|null $dataLength (optional)
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      // Check for an empty record
      if ($dataLength - $dataOffset > 0) {
        $Priority = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $destinationHost = DNS\Message::getLabel ($dnsData, $dataOffset);

        $this->Priority = $Priority;
        $this->destinationHost = $destinationHost;
      } else {
        $this->Priority = 0;
        $this->destinationHost = null;
      }
    }
    // }}}

    // {{{ buildPayload
    /**
     * Retrieve the payload of this record
     *
     * @param int $dataOffset
     * @param array &$dnsLabels
     *
     * @access public
     * @return string
     **/
    public function buildPayload (int $dataOffset, array &$dnsLabels): string
    {
      if ($this->destinationHost === null)
        return '';

      return
        self::buildInt16 ($this->Priority) .
        DNS\Message::setLabel ($this->destinationHost, $dataOffset + 2, $dnsLabels);
    }
    // }}}
  }
