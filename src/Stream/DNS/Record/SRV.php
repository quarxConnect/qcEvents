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

  use LengthException;
  use quarxConnect\Events\Stream\DNS;
  use quarxConnect\Events\Stream\DNS\Label;
  use RangeException;
  use UnexpectedValueException;

  class SRV extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x21;

    /* Priority of this record */
    private int $Priority = 0;

    /* Weight of this record */
    private int $Weight = 0;

    /* Any Port assigned to this record */
    private int $Port = 0;

    /* The hostname assigned to this record */
    private ?DNS\Label $destinationHost = null;

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' SRV ' . $this->Priority . ' ' . $this->Weight . ' ' . $this->Port . ' ' . $this->destinationHost;
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
     *
     * @throws RangeException
     **/
    public function setPriority (int $Priority): void
    {
      if (($Priority < 1) || ($Priority > 0xFFFF))
        throw new RangeException ('Value must be an unsigned 16-bit integer');

      $this->Priority = $Priority;
    }
    // }}}

    // {{{ getWeight
    /**
     * Retrieve the weight of this record
     *
     * @access public
     * @return int
     **/
    public function getWeight (): int
    {
      return $this->Weight;
    }
    // }}}

    // {{{ setWeight
    /**
     * Set the weight of this record
     *
     * @param int $Weight
     *
     * @access public
     * @return void
     *
     * @throws RangeException
     **/
    public function setWeight (int $Weight): void
    {
      if (($Weight < 1) || ($Weight > 0xFFFF))
        throw new RangeException ('Value must be an unsigned 16-bit integer');

      $this->Weight = $Weight;
    }
    // }}}

    // {{{ getPort
    /**
     * Retrieve a port assigned to this record
     *
     * @access public
     * @return int   
     **/
    public function getPort (): int
    {
      return $this->Port;
    }
    // }}}

    // {{{ setPort
    /**
     * Assign a new port to this record
     *
     * @param int $Port
     *
     * @access public
     * @return void
     *
     * @throws RangeException
     **/
    public function setPort (int $Port): void
    {
      if (($Port < 1) || ($Port > 0xFFFF))
        throw new RangeException ('Value must be an unsigned 16-bit integer');

      $this->Port = $Port;
    }
    // }}}

    // {{{ getHostname
    /**
     * Retrieve the hostname assigned to this record
     *
     * @access public
     * @return Label
     **/
    public function getHostname (): Label
    {
      return $this->destinationHost;
    }  
    // }}}

    // {{{ setHostname
    /**
     * Store a hostname on this record
     *
     * @param Label|string $destinationHost
     * 
     * @access public
     * @return void
     **/
    public function setHostname (Label|string $destinationHost): void
    {
      if (!($destinationHost instanceof Label))
        $destinationHost = new Label (explode ('.', $destinationHost));

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
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      // Make sure we have enough data to read
      if ($dataLength < $dataOffset + 6) {
        $Priority = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Weight   = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Port     = self::parseInt16 ($dnsData, $dataOffset, $dataLength);

        if (!($destinationHost = DNS\Message::getLabel ($dnsData, $dataOffset)))
          throw new UnexpectedValueException ('Failed to read label of DNS-Record (SRV)');

        $this->Priority = $Priority;
        $this->Weight = $Weight;
        $this->Port = $Port;
        $this->destinationHost = $destinationHost;

      // Check for empty record
      } elseif ($dataLength == $dataOffset) {
        $this->destinationHost = null;
        $this->Priority = 0;
        $this->Weight = 0;
        $this->Port = 0;
      } else
        throw new LengthException ('DNS-Record has invalid size (SRV)');
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
        self::buildInt16 ($this->Weight) .
        self::buildInt16 ($this->Port) .
        DNS\Message::setLabel ($this->destinationHost, $dataOffset + 6, $dnsLabels);
    }
    // }}}
  }
