<?php

  /**
   * quarxConnect Events - DNS Resource Record
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
  
  class SOA extends DNS\Record {
    protected const DEFAULT_TYPE = 0x06;
    
    private $Nameserver = '';
    private $Mailbox = '';
    private $Serial = 0;
    private $Refresh = 0;
    private $Retry = 0;
    private $Expire = 0;
    private $Minimum = 0;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' SOA ' . $this->Nameserver . ' ' . $this->Mailbox . ' ' . $this->Serial . ' ' . $this->Refresh . ' ' . $this->Retry . ' ' . $this->Expire . ' ' . $this->Minimum;
    }
    // }}}
    
    public function setNameserver ($Nameserver) {
      $this->Nameserver = $Nameserver;
      
      return true;
    }
    
    public function setMailbox ($Mailbox) {
      $this->Mailbox = str_replace ('@', '.', $Mailbox);
      
      return true;
    }
    
    public function setSerial ($Serial) {
      $this->Serial = (int)$Serial;
      
      return true;
    }
    
    public function setRefresh ($Refresh) {
      $this->Refresh = (int)$Refresh;
      
      return true;
    }
    
    public function setRetry ($Retry) {
      $this->Retry = (int)$Retry;
      
      return true;
    }
    
    public function setExpire ($Expire) {
      $this->Expire = (int)$Expire;
      
      return true;
    }
    
    public function setMinimum ($Minimum) {
      $this->Minimum = $Minimum;
      
      return true;
    }
    
    
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
     * @throws \LengthException
     * @throws \UnexpectedValueException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if (!($Nameserver = DNS\Message::getLabel ($dnsData, $dataOffset)))
        throw new \UnexpectedValueException ('Failed to read Nameserver-Label of DNS-Record (SOA)');
      
      if (!($Mailbox = DNS\Message::getLabel ($dnsData, $dataOffset)))
        throw new \UnexpectedValueException ('Failed to read Mailbox-Label of DNS-Record (SOA)');
      
      if ($dataLength < $dataOffset + 20)
        throw new \LengthException ('DNS-Record too short (SOA)');
      
      $this->Nameserver = $Nameserver;
      $this->Mailbox    = $Mailbox;
      $this->Serial     = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Refresh    = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Retry      = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Expire     = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Minimum    = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
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
      $Nameserver = DNS\Message::setLabel ($this->Nameserver, $Offset, $Labels);
      $Mailbox = DNS\Message::setLabel ($this->Mailbox, $Offset + strlen ($Nameserver), $Labels);
      
      return
        $Nameserver .
        $Mailbox .
        self::buildInt32 ($this->Serial) .
        self::buildInt32 ($this->Refresh) .
        self::buildInt32 ($this->Retry) .
        self::buildInt32 ($this->Expire) .
        self::buildInt32 ($this->Minimum);
    }
    // }}}
  }
