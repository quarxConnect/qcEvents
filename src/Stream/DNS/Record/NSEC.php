<?php

  /**
   * quarxConnect Events - DNS NSEC Resource Record
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class NSEC extends DNS\Record {
    protected const DEFAULT_TYPE = 0x2F;
    
    private $nextDomainname = '';
    private $Types = [ ];
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' NSEC ' . $this->nextDomainname . (count ($this->Types) > 0 ? ' TYPE' . implode (' TYPE', $this->Types) : '');
    }
    // }}}
    
    // {{{ parsePayload
    /**
     * Parse a given payload
     * 
     * @param string $dnsData
     * @param int $dataOffset
     * @param int $dataLength (optional)
     * 
     * @access public
     * @return void
     * @throws \UnexpectedValueException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if (($nextDomainname = DNS\Message::getLabel ($dnsData, $dataOffset, false)) === false)
        throw new \UnexpectedValueException ('Failed to read label of DNS-Record (NSEC)');
      
      while ($dataOffset + 2 < $dataLength) {
        $Window = ord ($dnsData [$dataOffset++]) * 0x0100;
        $Length = ord ($dnsData [$dataOffset++]);
        
        for ($b = 0; $b < $Length; $b++) {
          $v = ord ($dnsData [$dataOffset++]);
          
          if ($v & 0x80) $Types [$Window]     = $Window;
          if ($v & 0x40) $Types [$Window + 1] = $Window + 1;
          if ($v & 0x20) $Types [$Window + 2] = $Window + 2;
          if ($v & 0x10) $Types [$Window + 3] = $Window + 3;
          if ($v & 0x08) $Types [$Window + 4] = $Window + 4;
          if ($v & 0x04) $Types [$Window + 5] = $Window + 5;
          if ($v & 0x02) $Types [$Window + 6] = $Window + 6;
          if ($v & 0x01) $Types [$Window + 7] = $Window + 7;
          
          $Window += 8;
        }
      }
      
      $this->nextDomainname = $nextDomainname;
      $this->Types = $Type;
    }
    // }}}
    
    // {{{ buildPayload
    /**
     * Retrive the payload of this record
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function buildPayload ($Offset, &$Labels) {
      $Windows = [ ];
      $Bitmask = '';
      
      foreach ($this->Types as $Type) {
        $Window = (($Type >> 8) & 0xFF);
        $Bit    = ($Type & 0xFF);
        $Byte   = floor ($Bit / 8);
        $Bit   -= ($Byte * 8);
        
        // Make sure the byte exists
        if (!isset ($Windows [$Window]))
          $Windows [$Window] = str_repeat ("\x00", $Byte + 1);
        elseif (strlen ($Windows [$Window]) < $Byte + 1)
          $Windows [$Window] .= str_repeat ("\x00", $Byte + 1 - strlen ($Windows [$Window]));
        
        // Update the byte
        # TODO: This looks bad
        $Windows [$Window][$Byte] = chr (ord ($Windows [$Window][$Byte]) | (1 << (7 - $Bit)));
      }
      
      foreach ($Windows as $Window=>$Bytes)
        $Bitmask .= chr ($Window & 0xFF) . chr (strlen ($Bytes) & 0xFF) . $Bytes;
      
      return
        DNS\Message::setLabel ($this->SignersName) .
        $Bitmask;
    }
    // }}}
  }
