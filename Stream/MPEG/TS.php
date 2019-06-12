<?PHP

  /**
   * qcEvents - MPEG TS Stream Parser
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
  
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Stream/MPEG/TS/Packet.php');
  require_once ('qcEvents/Stream/MPEG/TS/PAT.php');
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_Stream_MPEG_TS implements qcEvents_Interface_Consumer {
    use qcEvents_Trait_Hookable;
    
    /* Internal buffer with MPEG-TS data */
    private $tsBuffer = '';
    
    /* Buffers for PES-Packets */
    private $payloadBuffers = array ();
    
    /* External Handlers */
    private $payloadHandlers = array ();
    
    /* Internal PAT-Table */
    private $patTable = null;
    
    // {{{ setPayloadHandler
    /**
     * Register a new PID-Payload-Handler
     * 
     * @param int $PID
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function setPayloadHandler ($PID, callable $Callback, $Private = null) {
      $this->payloadHandlers [$PID] = array ($Callback, $Private);
    }
    // }}}
    
    // {{{ unsetPayloadHandler
    /** 
     * Remove the handler for a given PID-Payload-Handler
     * 
     * @param int $PID
     * 
     * @access public
     * @return void
     **/
    public function unsetPayloadHandler ($PID) {
      unset ($this->payloadHandlers [$PID]);
    }
    // }}}
    
    // {{{ consume
    /** 
     * Process data from our source
     * 
     * @param string $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source = null) {
      // Append data to internal buffer
      $this->tsBuffer .= $Data;
      unset ($Data);
      
      // Try to read packets from buffer
      $p = $op = 0;
      $l = strlen ($this->tsBuffer);
      
      while ((($p = strpos ($this->tsBuffer, 'G', $p)) !== false) && (($l - $p) > 187)) {
        // Parse a new packet
        $Packet = new qcEvents_Stream_MPEG_TS_Packet;
        
        if ($Packet->parse ($this->tsBuffer, $p)) {
          // Fire a callback first
          $this->___callback ('tsReceivedPacket', $Packet);
          
          // Process the packet internally
          if (!isset ($this->payloadBuffers [$pid = $Packet->getPID ()])) {
            // Discard the packet if no payload is started for an unknown PID
            if ($Packet->isPayloadStart ())
              $this->payloadBuffers [$pid] = array (
                'Counter' => $Packet->getCounter (),
                'Payload' => $Packet->getPayload (),
              );
          
          // Check the counter
          } elseif ($Packet->getCounter () != (++$this->payloadBuffers [$pid]['Counter'] % 16)) {
            # TODO: Nobody notices here that we discard a backet
            
            // Ignore if counter is equal to last packet
            if ((($Packet->getCounter () + 1) % 16) == ($this->payloadBuffers [$pid]['Counter'] % 16))
              $this->payloadBuffers [$pid]['Counter']--;
            
            // Restart if possible...
            elseif ($Packet->isPayloadStart ()) {
              $this->payloadBuffers [$pid]['Counter'] = $Packet->getCounter ();
              $this->payloadBuffers [$pid]['Payload'] = $Packet->getPayload ();
            
            // ... or just discard
            } else
              unset ($this->payloadBuffers [$pid]);
          
          // Handle a continuing packet
          } else {
            // Check if this starts a new packet
            if ($Packet->isPayloadStart ()) {
              // Forward the pes/psi-data
              $this->mpegPayloadReady ($pid, $this->payloadBuffers [$pid]['Payload']);
              
              $this->payloadBuffers [$pid]['Payload'] = $Packet->getPayload ();
            } else
              $this->payloadBuffers [$pid]['Payload'] .= $Packet->getPayload ();
          }
        }
        
        // Move forward
        $op = $p += 188;
      }
      
      // Truncate the buffer
      $this->tsBuffer = substr ($this->tsBuffer, max ($op, $l - 187));
    }
    // }}}
    
    // {{{ mpegPayloadReady
    /**
     * A complete payload for a PID was extracted from TS
     * 
     * @param int $PID
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function mpegPayloadReady ($PID, $Data) {
      // Check if there is a handler registered
      if (isset ($this->payloadHandlers [$PID]))
        return $this->___raiseCallback ($this->payloadHandlers [$PID][0], $PID, $Data, $this->payloadHandlers [$PID][1]);
      
      // Route special PIDs
      if ($PID == 0x0000)
        return $this->mpegTsPatReady ($Data);
      
      # PAT-PR-PID PMT Table 0x02
      # 0x0001 CAT
      # 0x0002 TSDT
      # 0x0010 NIT Table 0x40
      # 0x0010 NIT Table 0x41 Other
      # 0x0011 SDT Table 0x42
      # 0x0012 EIT
      # 0x0040 NIT
      # 0x0041 NIT Other
      # 0x0042 SDT
      # 0x0046 SDT
      # 0x00C8 ATSC VCT
      # 0x00C9 ATSC VCT
    }
    // }}}
    
    // {{{ mpegTsPatReady
    /**
     * Parse data of a PAT-Table
     * 
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function mpegTsPatReady ($Data) {
      // Make sure we have a PAT-Table
      if ($this->patTable === null)
        $this->patTable = new qcEvents_Stream_MPEG_TS_PAT;
      
      // Try to parse the data
      if (!$this->patTable->parse ($Data, true))
        return;
      
      // Make sure the table is ready before proceeding
      if (!$this->patTable->isReady ())
        return;
      
      // Raise a callback for this
      $this->___callback ('tsReceivedPAT', $this->patTable);
      
      // Remove the table-handle
      $this->patTable = null;
    }
    // }}}
    
    public function close () : qcEvents_Promise {
      # TODO?
      return qcEvents_Promise::resolve ();
    }
        
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, true, $Private);
    }     

    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      return qcEvents_Promise::resolve ();
    }
    
    
    // {{{ tsReceivedPacket
    /**
     * Callback: An MPEG-TS Packet was received
     * 
     * @param qcEvents_Stream_MPEG_TS_Packet $Packet
     * 
     * @access protected
     * @return void
     **/
    protected function tsReceivedPacket (qcEvents_Stream_MPEG_TS_Packet $Packet) { }
    // }}}
    
    // {{{ tsReceivedPAT
    /** 
     * Callback: A PAT was received
     * 
     * @param qcEvents_Stream_MPEG_TS_PAT $PAT
     * 
     * @access protected
     * @return void
     **/
    protected function tsReceivedPAT (qcEvents_Stream_MPEG_TS_PAT $PAT) { }
    // }}}
  }

?>