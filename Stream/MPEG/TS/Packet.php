<?PHP

  /**
   * qcEvents - MPEG TS Packet
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  class qcEvents_Stream_MPEG_TS_Packet {
    private $PID = 0x0000;
    
    private $transportError = false;
    private $payloadStart = false;
    private $hasPriority = false;
    private $scrambleInfo = 0x00;
    private $hasAdaption = false;
    private $hasPayload = false;
    private $Counter = 0x00;
    private $Payload = null;
    
    // {{{ getPID
    /**
     * Retrive the PID of this packet
     * 
     * @access public
     * @return int
     **/
    public function getPID () {
      return $this->PID;
    }
    // }}}
    
    // {{{ getCounter
    /**
     * Retrive the continuity-counter of this packet
     * 
     * @access public
     * @return int
     **/
    public function getCounter () {
      return $this->Counter;
    }
    // }}}
    
    // {{{ getPayload
    /**
     * Retrive the payload of this packet
     * 
     * @access public
     * @return string
     **/
    public function getPayload () {
      return $this->Payload;
    }
    // }}}
    
    // {{{ isPayloadStart
    /**
     * Check if this packet denotes the start of a new payload-stream
     * 
     * @access public
     * @return bool
     **/
    public function isPayloadStart () {
      return $this->payloadStart;
    }
    // }}}
    
    // {{{ parse
    /**
     * Parse an MPEG Transport-Stream Packet
     * 
     * @param string $Data
     * @param int $Offset (optional)
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data, $Offset = 0) {
      // Parse flags and PID
      $Flags = ord ($Data [++$Offset]);
      $TransportError = (($Flags & 0x80) == 0x80);
      $PayloadStart = (($Flags & 0x40) == 0x40);  
      $Priority = (($Flags & 0x20) == 0x20);
      $PID = (($Flags & 0x1F) << 8) + ord ($Data [++$Offset]);
      
      $Flags = ord ($Data [++$Offset]);
      $Scramble = (($Flags & 0xC0) >> 6);
      $Adaption = (($Flags & 0x20) >> 5);
      $Payload = (($Flags & 0x10) >> 4); 
      $Counter = ($Flags & 0x0F);
      
      // Discard data if no Adaption or payload is present
      // Defined in ITU H.222.0, chapter 2.4
      if (!$Adaption && !$Payload)
        return false;
      
      // Process optional adaption-field
      if ($Adaption) {
        // Read the length of the adaption-field
        $AdaptionLength = ord ($Data [++$Offset]);
        
        # TODO
        
        $Offset += $AdaptionLength++;
      } else
        $AdaptionLength = 0;
      
      // Process payoad
      if ($Payload) {
        // ITU H.222.0 states that all 184 bytes of the TS-packet
        // (excluding header and the optional adaption-field)
        // should be treated as payload
        $Length = 184 - $AdaptionLength;
        $Payload = substr ($Data, ++$Offset, $Length);
      } else
        $Payload = null;
      
      // Assign values
      $this->PID = $PID;
      $this->transportError = $TransportError;
      $this->payloadStart = $PayloadStart;
      $this->hasPriority = $Priority;
      $this->scrambleInfo = $Scramble;
      $this->hasAdaption = $Adaption;
      $this->hasPayload = $Payload;
      $this->Counter = $Counter;
      $this->Payload = $Payload;
      
      return true;
    }
    // }}}
  }

?>