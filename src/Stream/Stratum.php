<?php

  /**
   * qcEvents - Stratum-Stream Implementation
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Stream;
  use \quarxConnect\Events;
  
  class Stratum extends Events\Hookable implements Events\ABI\Stream\Consumer {
    // Mining-Algorithm of our client
    public const ALGORITHM_SHA256 = 'sha256';
    public const ALGORITHM_SCRYPT = 'scrypt';
    public const ALGORITHM_X11 = 'x11';
    public const ALGORITHM_BLAKE2b = 'blake2b';
    public const ALGORITHM_ETH = 'eth';
    
    private $Algorithm = null;
    
    // Protocol-Version
    const PROTOCOL_STRATUM = 0x00;
    const PROTOCOL_ETH_STRATUM = 0x01;
    const PROTOCOL_ETH_STRATUM_NICEHASH = 0x02;
    const PROTOCOL_ETH_GETWORK = 0x03;

    private $ProtocolVersion = Stratum::PROTOCOL_STRATUM;
    
    /* Stream to our peer */
    private $Stream = null;
    
    /* Local buffer for JSON-Messages */
    private $Buffer = '';
    
    /* Queue for pending requests */
    private $Requests = [ ];
    private $nextRequest = 1;
    
    /* Queue of messages to send once a stream is connected */
    private $Queue = [ ];
    
    // {{{ getStream
    /**
     * Retrive the piped stream
     * 
     * @access public
     * @return Events\ABI\Stream
     **/
    public function getStream () : ?Events\ABI\Stream {
      return $this->Stream;
    }
    // }}}
    
    // {{{ getAlgorithm
    /**
     * Retrive the mining-algorithm of our client
     * 
     * @access public
     * @return enum
     **/
    public function getAlgorithm () : string {
      return $this->Algorithm;
    }
    // }}}
    
    // {{{ setAlgorithm
    /**
     * Set mining-algorithm of our client
     * 
     * @param enum $Algorithm
     * 
     * @access public
     * @return void
     **/
    public function setAlgorithm (string $Algorithm) : void {
      // Set the algorithm
      $this->Algorithm = $Algorithm;
    }
    // }}}
    
    // {{{ getProtocolVersion
    /**
     * Retrive the actually negotiated protocol-version
     * 
     * @access public
     * @return enum
     **/
    public function getProtocolVersion () {
      return $this->ProtocolVersion;
    }
    // }}}
    
    // {{{ setProtocolVersion
    /**
     * Set the negotiated protocol-version
     * 
     * @param enum $Version
     * 
     * @access public
     * @return void
     **/
    public function setProtocolVersion ($Version) : void {
      $this->ProtocolVersion = $Version;
    }
    // }}}
    
    // {{{ sendRequest
    /**
     * Write out a strarum-request to the wire and wait for an answer
     * 
     * @param string $Request
     * @param array $Parameters
     * @param array $Extra (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendRequest ($Request, array $Parameters = null, array $Extra = null) : Events\Promise {
      // Make sure parameters are an array
      if ($Parameters === null)
        $Parameters = [ ];
      
      // Get a message-id
      $MessageID = $this->nextRequest++;
      
      // Prepare the request
      $Message = [
        'id' => $MessageID,
        'method' => $Request,
        'params' => $Parameters,
      ];
      
      return new Events\Promise (
        function ($Resolve, $Reject)
        use ($Message, $Extra) {
          // Store the callback
          $this->Requests [$Message ['id']] = array ($Resolve, $Reject);
          
          // Send the request
          return $this->sendMessage ($Message, $Extra);
        }
      );
    }
    // }}}
    
    // {{{ sendNotify
    /**
     * Push a notify to our peer
     * 
     * @param string $Notify
     * @param array $Parameters (optional)
     * @param array $Extra (optional)
     * 
     * @access public
     * @return void
     **/
    public function sendNotify ($Notify, array $Parameters = null, array $Extra = null) : void {
      // Make sure parameters are an array
      if ($Parameters === null)
        $Parameters = [ ];
      
      // Write out the request
      $this->sendMessage (
        [
          'id' => ($this->Algorithm == $this::ALGORITHM_ETH ? 0 : null),
          'method' => $Notify,
          'params' => $Parameters,
        ],
        $Extra
      );
    }
    // }}}
    
    // {{{ sendMessage
    /**
     * Write out a Stratum-Message to the stream (use with caution!)
     * 
     * @param mixed $Message
     * @param array $Extra (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendMessage ($Message, array $Extra = null) : Events\Promise {
      // Make sure the message is an array
      $Message = (array)$Message;
      
      if ($Extra)
        $Message = array_merge ($Extra, $Message);
      
      // Patch in jsonrpc-version for ethereum
      if ($this->Algorithm == $this::ALGORITHM_ETH)
        $Message ['jsonrpc'] = '2.0';
      
      // Push message to the wire
      if (!$this->Stream || !$this->Stream->isConnected ())
        return new Events\Promise (
          function (callable $Resolve, callable $Reject) use ($Message) {
            $this->Queue [] = array ($Message, $Resolve, $Reject);
          }
        );
      
      return $this->Stream->write (json_encode ($Message) . "\n");
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, Events\ABI\Source $Source) : void {
      // Push data to local buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Read JSON-Lines from buffer
      $s = 0;
      
      while (($p = strpos ($this->Buffer, "\n", $s)) !== false) {
        // Try to read JSON
        if (!is_object ($call = json_decode (substr ($this->Buffer, $s, $p - $s)))) {
          $this->___callback ('stratumInvalidJSON', substr ($this->Buffer, $s, $p - $s));
          $this->close ();
          
          return;
        }
        
        // Do some eth-magic
        if ($this->getAlgorithm () == $this::ALGORITHM_ETH) {
          if (property_exists ($call, 'result') && !property_exists ($call, 'error'))
            $call->error = null;
          elseif (!property_exists ($call, 'result') && property_exists ($call, 'error'))
            $call->result = null;
          
          if (property_exists ($call, 'id') && ($call->id === 0))
            $call->id = null;
        }
        
        // Sanatize the message
        if (!property_exists ($call, 'id') || !(
            (property_exists ($call, 'method') && property_exists ($call, 'params')) ||
            (property_exists ($call, 'result') && property_exists ($call, 'error'))
          )) {
          $this->___callback ('stratumInvalidJSON', $call);
          $this->close ();
          
          return;
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
    protected function processMessage (object $Message) : void {
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
    protected function processRequest (object $Message) : void {
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
    protected function processResponse (object $Message) : void {
      // Check if there is a request pending
      if (isset ($this->Requests [$Message->id])) {
        // Get the callback
        $Callbacks = $this->Requests [$Message->id];
        unset ($this->Requests [$Message->id]);
        
        // Run the callback
        if ($Message->error)
          call_user_func ($Callbacks [1], $Message->error);
        else
          call_user_func ($Callbacks [0], $Message->result, $Message);
        
        return;
      }
      
      // Raise generic callback for this
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
    protected function processNotify (object $Message) : void {
      $this->___callback ('stratumNotify', $Message);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Cancel pending requests
      foreach ($this->Requests as $Callbacks)
        call_user_func ($Callbacks [1], 'Stream closing');
      
      $this->Requests = [ ];
      
      // Make sure we have a stream assigned ...
      if (!$this->Stream || !$this->Stream->isConnected ())
        return Events\Promise::resolve ();
      
      // ... and forward the call
      $this->___callback ('eventClosed');
      
      $Stream = $this->Stream;
      $this->Stream = null;
      
      return $Stream->close ();
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $Source) : Events\Promise {
      // Store the stream
      $this->Stream = $Source;
      
      // Indicate success
      if (($Source instanceof Events\Socket) && !$Source->isConnected ())
        return $Source->once ('socketConnected')->then (
          function () use ($Source) {
            // Sanity-Check stream and source
            if ($this->Stream !== $Source)
              throw new \Exception ('Stream was changed');
            
            // Raise callback
            $this->___callback ('eventPipedStream', $this->Stream);
            
            // Push queue to the wire
            foreach ($this->Queue as $Q)
              $this->sendMessage ($Q [0])->then ($Q [1], $Q [2]);
                                  
            $this->Queue = [ ];
                        
            // Forward success
            return true;
          }
        );
      
      // Raise a callback for this
      $this->___callback ('eventPipedStream', $Source);
      
      // Return resolved promise
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $Source) : Events\Promise {
      // Check if the stream is ours
      if ($this->Stream !== $Source)
        return Events\Promise::reject ('Invalid source');
      
      // Remove the stream
      $this->Stream = null;
      $this->Buffer = '';
      
      // Cancel pending requests
      foreach ($this->Requests as $Callbacks)
        call_user_func ($Callbacks [1], 'Removing pipe from consumer');
      
      $this->Requests = [ ];
      
      // Indicate success
      $this->___callback ('eventUnpiped', $Source);
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param Events\ABI\Stream $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (Events\ABI\Stream $Source) { }
    // }}}

    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (Events\ABI\Source $Source) { }
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
