<?PHP

  /**
   * qcEvents - Stratum-Stream Implementation
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  
  class qcEvents_Stream_Stratum extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* Stream to our peer */
    private $Stream = null;
    
    /* Local buffer for JSON-Messages */
    private $Buffer = '';
    
    /* Queue of messages to send once a stream is connected */
    private $Queue = array ();
    
    // {{{ getStream
    /**
     * Retrive the piped stream
     * 
     * @access public
     * @return qcEvents_Interface_Stream
     **/
    public function getStream () {
      return $this->Stream;
    }
    // }}}
    
    // {{{ sendMessage
    /**
     * Write out a Stratum-Message to the stream
     * 
     * @param array $Message
     * 
     * @access public
     * @return void
     **/
    public function sendMessage (array $Message) {
      if (!$this->Stream || !$this->Stream->isConnected ())
        $this->Queue [] = $Message;
      else
        $this->Stream->write (json_encode ($Message) . "\n");
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Push data to local buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Read JSON-Lines from buffer
      $s = 0;
      
      while (($p = strpos ($this->Buffer, "\n", $s)) !== false) {
        // Try to read JSON
        if (!is_object ($call = json_decode (substr ($this->Buffer, $s, $p - $s)))) {
          $this->___callback ('stratumInvalidJSON', substr ($this->Buffer, $s, $p - $s));
          
          return $this->close ();
        }
        
        // Sanatize the message
        if (!property_exists ($call, 'id') || !(
            (property_exists ($call, 'method') && property_exists ($call, 'params')) ||
            (property_exists ($call, 'result') && property_exists ($call, 'error'))
          )) {
          $this->___callback ('stratumInvalidJSON', $call);
          
          return $this->close ();
        }

        // Forward to handler
        $this->processMessage ($call);

        // Move pointer forward
        $s = $p + 1;
      }

      // Truncate buffer
      if ($s > 0)
        $this->Buffer = substr ($this->Buffer, $s);
    }
    // }}}

    // {{{ processMessage
    /**
     * Process an incoming message
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processMessage ($Message) {
      // Notify about the message in general
      $this->___callback ('stratumMessage', $Message);
      
      // Forward to specialized handlers
      if (isset ($Message->method))
        $this->processRequest ($Message);
      elseif ($Message->id !== null)
        $this->processResponse ($Message);
      else
        $this->processNotify ($Message);
    }
    // }}}
    
    // {{{ processRequest
    /**
     * Process a Stratum-Request
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processRequest ($Message) {
      $this->___callback ('stratumRequest', $Message);
    }
    // }}}
    
    // {{{ processResponse
    /**
     * Process a Stratum-Response
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processResponse ($Message) {
      $this->___callback ('stratumResponse', $Message);
    }
    // }}}
    
    // {{{ processNotify
    /**
     * Process a Stratum-Notify
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function processNotify ($Message) {
      $this->___callback ('stratumNotify', $Message);
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
      // Make sure we have a stream assigned ...
      if (!$this->Stream || !$this->Stream->isConnected ()) {
        $this->___raiseCallback ($Callback, true, $Private);
        
        return;
      }
      
      // ... and forward the call
      $this->Stream->close ($Callback, $Private);
      $this->___callback ('eventClosed');
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
      // Store the stream
      $this->Stream = $Source;
      
      // Indicate success
      if (($Source instanceof qcEvents_Socket) && !$Source->isConnected ()) {
        $Source->addHook ('socketConnected', function () use ($Callback, $Private, $Source) {
          if ($Source !== $this->Stream)
            return;
          
          $this->___raiseCallback ($Callback, $Source, true, $Private);
          $this->___callback ('eventPipedStream', $this->Stream);
          
          foreach ($this->Queue as $Q)
            $this->sendMessage ($Q);
          
          $this->Queue = array ();
        });
        
        return;
      }
      
      $this->___raiseCallback ($Callback, $Source, true, $Private);
      $this->___callback ('eventPipedStream', $Source);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
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
     * @return void
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Check if the stream is ours
      if ($this->Stream !== $Source)
        return $this->___raiseCallback ($Callback, true, $Private);
      
      // Remove the stream
      $this->Stream = null;
      $this->Buffer = '';
      
      // Indicate success
      $this->___raiseCallback ($Callback, true, $Private);
      $this->___callback ('eventUnpiped', $Source);
    }
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
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ stratumInvalidJSON
    /**
     * Callback: An invalid JSON-Message was received
     * 
     * @param mixed $Data
     * 
     * @access protected
     * @return void
     **/
    protected function stratumInvalidJSON ($Data) { }
    // }}}
    
    // {{{ stratumMessage
    /**
     * Callback: A Stratum-Message was received
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function stratumMessage ($Message) { }
    // }}}
    
    // {{{ stratumRequest
    /**
     * Callback: A Stratum-Request was received
     * 
     * @access object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function stratumRequest ($Message) { }
    // }}}
    
    // {{{ stratumResponse
    /**
     * Callback: A Stratum-Response was received
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function stratumResponse ($Message) { }
    // }}}
    
    // {{{ stratumNotify
    /**
     * Callback: A Stratum-Notify was received
     * 
     * @param object $Message
     * 
     * @access protected
     * @return void
     **/
    protected function stratumNotify ($Message) { }
    // }}}
  }

?>