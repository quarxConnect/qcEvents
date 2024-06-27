<?php

  /**
   * quarxConnect Events - EDNS Resource Record
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

  class EDNS extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x29;

    private int $RCode     = 0x00;
    private int $Version   = 0x00;
    private int $Flags     = 0x0000;
    private array $Options = [];

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string  
     **/
    public function __toString (): string
    {
      return 'EDNS Version ' . $this->Version . ', Flags ' . $this->Flags . ', UDP ' . $this->getDatagramSize ();
    }
    // }}}

    // {{{ getReturnCode
    /**
     * Retrieve the extended Return-Code
     *
     * @access public
     * @return int
     **/
    public function getReturnCode (): int
    {
      return $this->RCode;
    }
    // }}}

    // {{{ setReturnCode
    /**
     * Set the extended return-code
     *
     * @param int $Code
     *
     * @access public
     * @return void
     **/
    public function setReturnCode (int $Code): void
    {
      $this->RCode = $Code;

      $this->setTTL (($Code << 24) | ($this->getTTL () & 0xFFFFFF));
    }
    // }}}

    // {{{ getFlags
    /**
     * Retrieve extended flags of this DNS-Message
     *
     * @access public
     * @return int
     **/
    public function getFlags (): int
    {
      return $this->Flags;
    }
    // }}}

    // {{{ setFlags
    /**
     * Set the extended flags of this DNS-Message
     *
     * @param int $Flags
     *
     * @access public
     * @return void
     **/
    public function setFlags (int $Flags): void
    {
      $this->Flags = $Flags;

      $this->setTTL (($this->getTTL () & 0xFFFF0000) | ($Flags & 0xFFFF));
    }
    // }}}

    // {{{ getDatagramSize
    /**
     * Retrieve the desired maximum size of datagrams
     *
     * @access public
     * @return int   
     **/
    public function getDatagramSize (): int
    {
      return $this->getClass ();
    }
    // }}}

    // {{{ setDatagramSize
    /**
     * Set the maximum size of datagrams for this message
     *
     * @param int $Size
     *
     * @access public
     * @return void
     **/
    public function setDatagramSize (int $Size): void
    {
      $this->setClass ($Size);
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
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Parse option-data
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      $Options = [];

      while ($dataOffset + 4 <= $dataLength) {
        $Option = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Length = self::parseInt16 ($dnsData, $dataOffset, $dataLength);

        $Options [$Option] = substr ($dnsData, $dataOffset, $Length);
        $dataOffset += $Length;
      }

      if ($dataOffset !== $dataLength)
        throw new LengthException ('Garbage data on DNS-Record (EDNS)');

      $this->Options = $Options;

      // Parse information from meta-data
      $TTL = $this->getTTL ();

      $this->RCode = (($TTL >> 24) & 0xFF);  
      $this->Version = (($TTL >> 16) & 0xFF);
      $this->Flags = ($TTL & 0xFFFF);
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
      $Payload = '';

      foreach ($this->Options as $ID=>$Data)
        $Payload .=
          self::buildInt16 ($ID) .
          self::buildInt16 (strlen ($Data)) .
          $Data;

      return $Payload;
    }
    // }}}
  }
