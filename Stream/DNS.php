<?PHP

  /**
   * qcEvents - Generic DNS Handling
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Stream/DNS/Message.php');
  
  class qcEvents_Stream_DNS implements qcEvents_Interface_Consumer, qcEvents_Interface_Stream_Consumer {
    use qcEvents_Trait_Hookable;
    
    /* Internal DNS-Buffer for TCP-Mode */
    private $dnsBuffer = '';
    
    /* Expected length of dnsBuffer */
    private $dnsLength = null;
    
    /* Active queries */
    private $dnsQueries = array ();
    
    /* Timeout for queries */
    private $dnsTimeout = 4;
    
    /* Forced Datagram-size for EDNS-Messages */
    private $dnsDatagramSize = 1200;
    
    /* Source of our pipe */
    private $Source = null;
    
    // {{{ getSource
    /**
     * Retrive the source for this DNS-Stream
     * 
     * @access public
     * @return qcEvents_Interface_Source
     **/
    public function getSource () {
      return $this->Source;
    }
    // }}}
    
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
      
      // Try to parse the message and push back if everything was successfull
      if (($rCode = $Message->parse ($Data)) === true)
        return $Message;
      
      // Check for a generic failure
      if (($rCode === false) || ($rCode === null)) {
        trigger_error ('Parse failed instantly');
        
        return false;
      } else
        trigger_error ('Received Malformed DNS-Message, dropping with error-response');
      
      // Handle a DNS-Error
      $Response = $Message->createClonedResponse ();
      $Response->setError ($rCode);
      
      $this->dnsStreamSendMessage ($Response);
      
      return false;
    }
    // }}}
    
    // {{{ dnsStreamSendMessage
    /**
     * Write a DNS-Message to the wire
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access public
     * @return bool
     **/
    public function dnsStreamSendMessage (qcEvents_Stream_DNS_Message $Message) {
      // Make sure we have a source available
      if (!$this->Source)
        return false;
      
      // Check if this is a query
      $Query = $Message->isQuestion ();
      
      // Try to override datagram-size
      if ($this->dnsDatagramSize !== null)
        $Message->setDatagramSize ($this->dnsDatagramSize);
      
      // Convert the Message into a string
      $Data = $Message->toString ();
      
      // Handle UDP-Writes
      if ($this->Source->isUDP ()) {
        // Check the maximum size for datagram-transport
        if (!$Query && isset ($this->dnsQueries [$Message->getID ()]))
          $Size = $this->dnsQueries [$Message->getID ()]->getDatagramSize ();
        else
          $Size = $Message->getDatagramSize ();
        
        // Make sure that the payload is not too long
        while (strlen ($Data) > $Size) {
          if (!$Message->truncate ())
            return false;
          
          $Data = $Message->toString ();
        }
      
      // Handle TCP-Writes
      } else
        $Data = chr ((strlen ($Data) & 0xFF00) >> 8) . chr (strlen ($Data) & 0xFF) . $Data;
      
      // Add to local storage if it is a query
      if ($Query && ($this->Source instanceof qcEvents_Interface_Timer)) {
        $this->dnsQueries [$Message->getID ()] = $Message;
        $this->Source->addTimer ($this->dnsTimeout, false, array ($this, 'dnsStreamTimeout'), $Message);
      
      // Or remove from queue if this is a response
      } elseif (!$Query)
        unset ($this->dnsQueries [$Message->getID ()]);
      
      return $this->Source->write ($Data);
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
      if (!is_object ($Message = $this->dnsParseMessage ($Data))) {
        trigger_error ('Malformed DNS-Message');
        
        return false;
      }
      
      // Fire initial callback
      $this->___callback ('dnsMessageReceived', $Message);
      
      // Process depending on message-type
      if (!$Message->isQuestion ()) {
        // Check if we have the corresponding query saved
        unset ($this->dnsQueries [$Message->getID ()]);
        
        return $this->___callback ('dnsResponseReceived', $Message);
      }
      
      if ($this->Source instanceof qcEvents_Interface_Timer) {
        $this->dnsQueries [$Message->getID ()] = $Message;
        $this->Source->addTimer ($this->dnsTimeout, false, array ($this, 'dnsStreamTimeout'), $Message);
      }
      
      return $this->___callback ('dnsQuestionReceived', $Message);
    }
    // }}}
    
    // {{{ dnsStreamTimeout
    /**
     * Timeout a localy queued query
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsStreamTimeout (qcEvents_Stream_DNS_Message $Message) {
      // Retrive the ID of the message
      $ID = $Message->getID ();
      
      // Sanatize the call
      if (!isset ($this->dnsQueries [$ID]) || ($this->dnsQueries [$ID] !== $Message))
        return;
      
      // Remove from the queue
      unset ($this->dnsQueries [$ID]);
      
      // Fire a callback
      $this->___callback ('dnsQuestionTimeout', $Message);
    }
    // }}}
    
    // {{{ consume
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Make sure we have a source
      if (!$this->Source) {
        trigger_error ('consume() without Source assigned');
        
        return;
      }
      
      // Just forward the data in UDP-Mode
      if ($this->Source->isUDP ())
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
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @param callable $Callback (optional) Callback to raise once the interface is closed
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @access public
     * @return void
     **/
    public function close (callable $Callback = null, $Private = null) {
      // Just raise callbacks
      $this->___raiseCallback ($Callback, $Private);
      $this->___callback ('eventClosed');
    }
    // }}}
    
    // {{{ reset
    /**
     * Reset our internal state
     * 
     * @access private
     * @return void
     **/
    private function reset () {
      $this->dnsBuffer = '';
      $this->dnsLength = null;
      $this->dnsQueries = array ();
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     *  
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     *    
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Check if this source is already set
      if ($this->Source === $Source) {
        $this->___raiseCallback ($Callback, true, $Private);
        
        return;
      }
      
      // Check if there is an existing source
      if ($this->Source)
        $this->deinitConsumer ($this->Source);
      
      // Reset ourself
      $this->reset ();
      
      // Set the new source
      $this->Source = $Source;
      
      // Raise an event for this
      $this->___raiseCallback ($Callback, true, $Private);
      $this->___callback ('eventPiped', $Source);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Stream_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      // Check if this source is already set
      if ($this->Source === $Source) {
        $this->___raiseCallback ($Callback, true, $Private);
        
        return;
      }
      
      // Check if there is an existing source
      if ($this->Source)
        $this->deinitConsumer ($this->Source);
      
      // Reset ourself
      $this->reset ();
      
      // Set the new source
      $this->Source = $Source;
      
      // Raise an event for this
      $this->___callback ('eventPipedStream', $Source);
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Check if this is the right source
      if ($this->Source !== $Source)
        return $this->___raiseCallback ($Callback, false, $Private);
      
      // Unset the source
      $this->Source = null;
      
      // Raise an event for this
      $this->___raiseCallback ($Callback, true, $Private);
      $this->___callback ('eventUnpiped', $Source);
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
    
    // {{{ dnsQuestionTimeout
    /**
     * Callback: A DNS-Question as been timed out
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsQuestionTimeout (qcEvents_Stream_DNS_Message $Message) { }
    // }}}
    
    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: This stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (qcEvents_Interface_Source $Source) { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $Source) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (qcEvents_Interface_Source $Source) { }
    // }}}
  }

?>