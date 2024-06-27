<?php

  /**
   * quarxConnect Events - DNS Resource Record
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
  use UnexpectedValueException;

  class PTR extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x0C;

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
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' PTR ' . $this->destinationHost;
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
    public function setHostname (DNS\Label $destinationHost): void {
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
     * @throws UnexpectedValueException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      // Check for empty record
      if ($dataLength === $dataOffset)
        $this->destinationHost = null;
      elseif ($destinationHost = DNS\Message::getLabel ($dnsData, $dataOffset))
        $this->destinationHost = $destinationHost;
      else
        throw new UnexpectedValueException ('Failed to read label of DNS-Record (PTR)');
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

      return DNS\Message::setLabel ($this->destinationHost, $dataOffset, $dnsLabels);
    }
    // }}}
  }
