<?php

  /**
   * qcEvents - SSH Global Request Message
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\SSH;
  
  class GlobalRequest extends Message {
    protected const MESSAGE_TYPE = 80;
    
    /* Name of this request */
    public $Name = '';
    
    /* Is a reply expected */
    public $wantReply = false;
    
    /* Unparsed payload of the request */
    public $Payload = null;
    
    /* Attributes for tcpip-listeners */
    public $Address = '';
    public $Port = 0;
    
    // {{{ unpack
    /**
     * Try to unpack data from a packet into this message-instance
     * 
     * @param string $packetData
     * 
     * @access public
     * @return bool
     **/
    public function unpack (string $packetData) : bool {
      // Read general header of packet
      $packetLength = strlen ($packetData);
      $packetOffset = 0;
      
      if (
        (($Name = self::readString ($packetData, $packetOffset, $packetLength)) === null) ||
        (($wantReply = self::readBoolean ($packetData, $packetOffset, $packetLength)) === null)
      )
        return false;
      
      // Get remaining payload
      $payloadData = substr ($packetData, $packetOffset);
      $packetLength -= $packetOffset;
      $packetOffset = 0;
      unset ($packetData);
      
      // Try to parse type-dependant stuff
      if (($Name == 'tcpip-forward') || ($Name == 'cancel-tcpip-forward')) {
        if (
          (($Address = self::readString ($payloadData, $packetOffset, $packetLength)) === null) ||
          (($Port = self::readUInt32 ($payloadData, $packetOffset, $packetLength)) === null)
        )
          return false;
        
        if ($packetLength != $packetOffset)
          return false;
        
        $this->Address = $Address;
        $this->Port = $Port;
      } else
        $this->Payload = $payloadData;
      
      // Push general header to instance
      $this->Name = $Name;
      $this->wantReply = $wantReply;
      
      return true;
    }
    // }}}
    
    // {{{ pack
    /**
     * Convert this message into binary
     * 
     * @access public
     * @return string
     **/
    public function pack () : string {
      $packetData =
        self::writeString ($this->Name) .
        self::writeBoolean ($this->wantReply);
      
      if (($this->Name == 'tcpip-forward') || ($this->Name == 'cancel-tcpip-forward'))
        $packetData .=
          self::writeString ($this->Address) .
          self::writeUInt32 ($this->Port);
      else
        $packetData .= $this->Payload;
      
      return $packetData;
    }
    // }}}
  }
