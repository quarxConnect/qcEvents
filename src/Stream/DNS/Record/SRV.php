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
  
  class SRV extends DNS\Record {
    protected const DEFAULT_TYPE = 0x21;
    
    /* Priority of this record */
    private $Priority = null;
    
    /* Weight of this record */
    private $Weight = null;
    
    /* Any Port assigned to this record */
    private $Port = null;
    
    /* The hostname assigned to this record */
    private $destinationHost = null;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' SRV ' . $this->Priority . ' ' . $this->Weight . ' ' . $this->Port . ' ' . $this->destinationHost;
    }
    // }}}
    
    // {{{ getPriority
    /**
     * Retrive a priority assigned to this record
     * 
     * @access public
     * @return int   
     **/
    public function getPriority () {
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
     * @return bool
     **/
    public function setPriority ($Priority) {
      if (($Priority < 1) || ($Priority > 0xFFFF))
        return false;
      
      $this->Priority = (int)$Priority;
      
      return true;
    }
    // }}}
    
    // {{{ getWeight
    /**
     * Retrive the weight of this record
     * 
     * @access public
     * @return int   
     **/
    public function getWeight () {
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
     * @return bool
     **/
    public function setWeight ($Weight) {
      if (($Weight < 1) || ($Weight > 0xFFFF))
        return false;
      
      $this->Weight = (int)$Weight;
      
      return true;
    }
    // }}}
    
    // {{{ getPort
    /**
     * Retrive a port assigned to this record
     * 
     * @access public
     * @return int   
     **/
    public function getPort () {
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
     * @return bool
     **/
    public function setPort ($Port) {
      if (($Port < 1) || ($Port > 0xFFFF))
        return false;
      
      $this->Port = (int)$Port;
      
      return true;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname assigned to this record
     * 
     * @access public
     * @return string
     **/
    public function getHostname () {
      return $this->destinationHost;
    }  
    // }}}
      
    // {{{ setHostname
    /**
     * Store a hostname on this record
     * 
     * @param string $destinationHost 
     * 
     * @access public
     * @return void
     **/
    public function setHostname ($destinationHost) {
      $this->destinationHost = $destinationHost;
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
     * @throws \LengthException
     * @throws \UnexpectedValueException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      // Make sure we have enough data to read
      if ($dataLength < $dataOffset + 6) {
        $Priority = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Weight   = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        $Port     = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
        
        if (!($destinationHost = DNS\Message::getLabel ($dnsData, $dataOffset)))
          throw new \UnexpectedValueException ('Failed to read label of DNS-Record (SRV)');
        
        $this->Priority = $Priority;
        $this->Weight = $Weight;
        $this->Port = $Port;
        $this->destinationHost = $destinationHost;
      
      // Check for empty record
      } elseif ($dataLength == $dataOffset)
        $this->destinationHost = $this->Priority = $this->Weight = $this->Port = null;
      
      else
        throw new \LengthException ('DNS-Record has invalid size (SRV)');
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
      if ($this->destinationHost === null)
        return '';
      
      return
        self::buildInt16 ($this->Priority) .
        self::buildInt16 ($this->Weight) .
        self::buildInt16 ($this->Port) .
        DNS\Message::setLabel ($this->destinationHost, $Offset + 6, $Labels);
    }
    // }}}
  }
