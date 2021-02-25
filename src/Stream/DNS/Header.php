<?php

  /**
   * quarxConnect Events - DNS Header
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

  namespace quarxConnect\Events\Stream\DNS;
  
  class Header {
    /**
     * [ID] ID of the query
     **/
    public $ID = 0x0000;
    
    /**
     * [QR] This query is an response
     **/
    public $isResponse = false;
    
    /**
     * [Opcode] Kind of this query
     **/
    public $Opcode = Message::OPCODE_QUERY;
    
    /**
     * [AA] This query is an authoritativ answer
     **/
    public $Authoritative = false;
    
    /**
     * [TC] This query was truncated
     **/
    public $Truncated = false;
    
    /**
     * [RD] Recursive query is desired
     **/
    public $recursionDesired = false;
    
    /**
     * [RA] Recursive query is available
     **/
    public $recursionAvailable = false;
    
    /**
     * [AD] Authentic/Authenticated data
     **/
    public $authenticData = false;
    
    /**
     * [CD] Checking Disabled
     **/
    public $checkingDisabled = false;
    
    /**
     * [RCode] Response-Code on this query
     **/
    public $RCode = Message::ERROR_NONE;
    
    /**
     * [QDCount] Number of questions on payload
     **/
    public $Questions = 0;
    
    /**
     * [ANCount] Number of answers on payload
     **/
    public $Answers = 0;
    
    /**
     * [NSCount] Number of authoritative records on payload
     **/
    public $Authorities = 0;
    
    /**
     * [ARCount] Number of additional records on the payload
     **/
    public $Additionals = 0;
    
    // {{{ getOpcode
    /**
     * Retrive the opcode of this DNS-Header
     * 
     * @access public
     * @return enum
     **/
    public function getOpcode () {
      return $this->Opcode;
    }
    // }}}
    
    // {{{ setOpcode
    /**
     * Set the opcode of this DNS-header
     * 
     * @param enum $Opcode
     * 
     * @access public
     * @return bool
     **/
    public function setOpcode ($Opcode) {
      $this->Opcode = (int)$Opcode;
      
      return true;
    }
    // }}}
    
    // {{{ getFlags
    /**
     * Retrive the flags set on this DNS-Header
     * 
     * @access public
     * @return int
     **/
    public function getFlags () {
      return 
        ($this->Authoritative      ? Message::FLAG_AUTHORITATIVE : 0) |
        ($this->Truncated          ? Message::FLAG_TRUNCATED : 0) |
        ($this->recursionDesired   ? Message::FLAG_RECURSION_DESIRED : 0) |
        ($this->recursionAvailable ? Message::FLAG_RECURSION_AVAILABLE : 0) |
        ($this->authenticData      ? Message::FLAG_AUTHENTIC_DATA : 0) |
        ($this->checkingDisabled   ? Message::FLAG_CHECKING_DISABLED : 0);
    }
    // }}}
    
    // {{{ setFlags
    /**
     * Set flags on this header
     * 
     * @param int $Flags
     * 
     * @access public
     * @return bool
     **/
    public function setFlags ($Flags) {
      $this->Authoritative      = (($Flags & Message::FLAG_AUTHORITATIVE) > 0);
      $this->Truncated          = (($Flags & Message::FLAG_TRUNCATED) > 0);
      $this->recursionDesired   = (($Flags & Message::FLAG_RECURSION_DESIRED) > 0);
      $this->recursionAvailable = (($Flags & Message::FLAG_RECURSION_AVAILABLE) > 0);
      $this->authenticData      = (($Flags & Message::FLAG_AUTHENTIC_DATA) > 0);
      $this->checkingDisabled   = (($Flags & Message::FLAG_CHECKING_DISABLED) > 0);
      
      return true;
    }
    // }}}
    
    // {{{ parse
    /**
     * Parse binary data into this object
     * 
     * @param string $Data
     * @param int $Offset
     * @param int $Length (optional)
     * 
     * @access public
     * @return void
     * @throws LengthException
     **/
    public function parse ($Data, &$Offset, $Length = null) {
      if ($Length === null)
        $Length = strlen ($Data);
      
      if ($Length < $Offset + 12)
        throw new \LengthException ('DNS-Header too short');
      
      $this->ID = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->isResponse = (($b = ord ($Data [$Offset++])) & 0x80) == 0x80;
      $this->Opcode = ($b & 0x78) >> 3;
      $this->Authoritative = ($b & 0x04) == 0x04;
      $this->Truncated = ($b & 0x02) == 0x02;
      $this->recursionDesired = ($b & 0x01) == 0x01;
      $this->recursionAvailable = (($b = ord ($Data [$Offset++])) & 0x80) == 0x80;
      $this->authenticData = ($b & 0x20) == 0x20;
      $this->checkingDisabled = ($b & 0x10) == 0x10;
      $this->RCode = ($b & 0x0F);
      
      $this->Questions = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Answers = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Authorities = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Additionals = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this header into a string
     * 
     * @access public
     * @return string
     **/
    public function toString () {
      return
        chr (($this->ID & 0xFF00) >> 8) . chr ($this->ID & 0xFF) . // Convert the ID
        chr (($this->isResponse ? 0x80 : 0x00) | ($this->Opcode << 3) | ($this->Authoritative ? 0x04 : 0x00) | ($this->Truncated ? 0x02 : 0x00) | ($this->recursionDesired ? 0x01 : 0x00)) .
        chr (($this->recursionAvailable ? 0x80 : 0x00) | ($this->authenticData ? 0x20 : 0x00) | ($this->checkingDisabled ? 0x10 : 0x00) | ($this->RCode & 0x0F)) .
        chr (($this->Questions & 0xFF00) >> 8) . chr ($this->Questions & 0xFF) .
        chr (($this->Answers & 0xFF00) >> 8) . chr ($this->Answers & 0xFF) .
        chr (($this->Authorities & 0xFF00) >> 8) . chr ($this->Authorities & 0xFF) .
        chr (($this->Additionals & 0xFF00) >> 8) . chr ($this->Additionals & 0xFF);
    }
    // }}}
  }
