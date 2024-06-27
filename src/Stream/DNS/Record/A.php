<?php

  /**
   * quarxConnect Events - DNS IPv4 Resource Record
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

  use quarxConnect\Events\Stream\DNS;

  class A extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x01;

    private ?string $ipAddress = null;

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string  
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' A ' . $this->ipAddress;
    }
    // }}}

    // {{{ getAddress
    /**
     * Retrieve any address assigned to this record
     *
     * @access public
     * @return string
     **/
    public function getAddress (): string
    {
      return $this->ipAddress ?? '0.0.0.0';
    }  
    // }}}

    // {{{ setAddress
    /**
     * Store an address for this record
     *
     * @param string $ipAddress
     *
     * @access public
     * @return void
     **/
    public function setAddress (string $ipAddress): void
    {
      # TODO: Check the address

      $this->ipAddress = $ipAddress;
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
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Make sure we know the length of our buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      // Allow to payload to be empty
      if ($dataLength - $dataOffset == 0)
        $this->ipAddress = null;
      else
        $this->ipAddress = long2ip (self::parseInt32 ($dnsData, $dataOffset, $dataLength));
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
      if ($this->ipAddress === null)
        return '';

      return self::writeInt32 (ip2long ($this->ipAddress));
    }
    // }}}
  }
