<?PHP

  /**
   * qcEvents - Generic DNS Handling
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/DNS/Message.php');
  
  abstract class qcEvents_Stream_DNS extends qcEvents_Socket {
    /* Internal DNS-Buffer for TCP-Mode */
    private $dnsBuffer = '';
    
    /* Expected length of dnsBuffer */
    private $dnsLength = null;
    
    // {{{ dnsParseMessage
    /**
     * Parse an DNS-Message
     * 
     * @param string $Data
     * 
     * @access protected
     * @return qcEvents_Stream_DNS_Message
     **/
    protected function dnsParseMessage ($Data) {
      $Message = new qcEvents_Stream_DNS_Message;
      
      if ($Message->parse ($Data))
        return $Message;
      
      return false;
    }
    // }}}
    
    // {{{ dnsSendMessage
    /**
     * Write a DNS-Message to the wire
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return bool
     **/
    protected function dnsSendMessage (qcEvents_Stream_DNS_Message $Message) {
      // Convert the Message into a string
      $Data = $Message->toString ();
      
      // Handle UDP-Writes
      if ($this->isUDP ()) {
        // Make sure that the payload is at most 512 Bytes
        while (strlen ($Data) > 512) {
          if (!$Message->truncate ())
            return false;
          
          $Data = $Message->toString ();
        }
        
        return $this->write ($Data);
      }
      
      // Write out TCP-Mode
      return $this->write (chr ((strlen ($Data) & 0xFF00) >> 8) . chr (strlen ($Data) & 0xFF) . $Data);
    }
    // }}}
    
    // {{{ socketReadable
    /**
     * Internal callback: There is data available on the buffer
     * 
     * @access protected
     * @return void
     **/
    protected function socketReadable () {
      return $this->socketReceive ($this->readBuffer ());
    }
    // }}}
    
    // {{{ socketReceive
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function socketReceive ($Data) {
      // Just forward the data in UDP-Mode
      if ($this->isUDP ())
        return $this->dnsStreamParse ($Data);
      
      // Append the data to our buffer
      $this->dnsBuffer .= $Data;
      unset ($Data);
      
      while (($l = strlen ($this->dnsBuffer)) > 0) {
        // Check if we know the length we expect
        if ($this->dnsLength === null) {
          // We need at least two bytes here
          if ($l < 2)
            return;
          
          // Get the length
          $this->dnsLength = (ord ($this->dnsBuffer [0]) << 8) + ord ($this->dnsBuffer [1]);
          $this->dnsBuffer = substr ($this->dnsBuffer, 2);
          $l -= 2;
        }
        
        // Check if the buffer is big enough
        if ($l < $this->dnsLength)
          return;
        
        // Get the data from the buffer
        $dnsPacket = substr ($this->dnsBuffer, 0, $this->dnsLength);
        $this->dnsBuffer = substr ($this->dnsBuffer, $this->dnsLength);
        $this->dnsLength = null;
        
        // Dispatch complete packet
        $this->dnsStreamParse ($dnsPacket);
        unset ($dnsPacket);
      }
    }
    // }}}
    
    // {{{ dnsStreamParse
    /**
     * Parse a received DNS-Message
     * 
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function dnsStreamParse ($Data) {
      // Try to parse the 
      if (!is_object ($Message = $this->dnsParseMessage ($Data)))
        return false;
      
      // Fire callbacks
      $this->___callback ('dnsMessageReceived', $Message);
      
      if ($Message->isQuery ())
        $this->___callback ('dnsQuestionReceived', $Message);
      else
        $this->___callback ('dnsResponseReceived', $Message);
    }
    // }}}
    
    
    // {{{ dnsMessageReceived
    /**
     * Callback: A DNS-Message was received
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsMessageReceived (qcEvents_Stream_DNS_Message $Message) { }
    // }}}
    
    // {{{ dnsQuestionReceived
    /**
     * Callback: A DNS-Question was received
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsQuestionReceived (qcEvents_Stream_DNS_Message $Message) { }
    // }}}
    
    // {{{ dnsResponseReceived
    /** 
     * Callback: A DNS-Response was received
     *    
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/  
    protected function dnsResponseReceived (qcEvents_Stream_DNS_Message $Message) { }
    // }}}
  }

?>