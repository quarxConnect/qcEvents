<?php

  /**
   * quarxConnect Events - Generic DNS Handling
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream;
  use quarxConnect\Events;
  
  class DNS implements Events\Interface\Consumer, Events\Interface\Stream\Consumer {
    use Events\Trait\Based;
    
    /* Internal DNS-Buffer for TCP-Mode */
    private $dnsBuffer = '';
    
    /* Expected length of dnsBuffer */
    private $dnsLength = null;
    
    /* Active queries */
    private $dnsQueries = [ ];
    
    /* Timeouts of queries */
    private $dnsTimeouts = [ ];
    
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
     * @return Events\Interface\Source
     **/
    public function getSource () : Events\Interface\Source {
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
     * @return DNS\Message
     **/
    protected function dnsParseMessage ($Data) : DNS\Message {
      $Message = new DNS\Message ();
      
      // Try to parse the message and push back if everything was successfull
      if (($rCode = $Message->parse ($Data)) === DNS\Message::ERROR_NONE)
        return $Message;
      
      // Check for a generic failure
      if (($rCode === false) || ($rCode === null))
        throw new \exception ('Failed to parse the message');
      
      trigger_error ('Received Malformed DNS-Message, dropping with error-response');
      
      // Handle a DNS-Error
      $Response = $Message->createClonedResponse ();
      $Response->setError ($rCode);
      
      $this->dnsStreamSendMessage ($Response);
      
      throw new \exception ('Failed to parse the message');
    }
    // }}}
    
    // {{{ dnsStreamSendMessage
    /**
     * Write a DNS-Message to the wire
     * 
     * @param DNS\Message $Message
     * 
     * @access public
     * @return bool
     **/
    public function dnsStreamSendMessage (DNS\Message $Message) {
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
      if ($Query && ($this->Source instanceof Events\Interface\Common)) {
        $this->dnsQueries [$Message->getID ()] = $Message;
        $this->dnsAddTimeout ($Message);
        
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
        $mID = $Message->getID ();
        
        // Check if we have the corresponding query saved
        unset ($this->dnsQueries [$mID]);
        
        // Make sure our timeout is removed
        if (isset ($this->dnsTimeouts [$mID])) {
          $this->dnsTimeouts [$mID]->cancel ();
          
          unset ($this->dnsTimeouts [$mID]);
        }
        
        return $this->___callback ('dnsResponseReceived', $Message);
      }
      
      $this->dnsQueries [$Message->getID ()] = $Message;
      $this->dnsAddTimeout ($Message);
      
      return $this->___callback ('dnsQuestionReceived', $Message);
    }
    // }}}
    
    // {{{ dnsAddTimeout
    /**
     * Setup a timeout for a given message
     * 
     * @param DNS\Message $Message
     * 
     * @access private
     * @return void
     **/
    private function dnsAddTimeout (DNS\Message $Message) {
      // Check if we can setup timeouts at all
      if (!$this->Source || !($eventBase = $this->Source->getEventBase ()))
        return;
      
      $mID = $Message->getID ();
      
      // Check wheter just to restart an old timeout
      if (isset ($this->dnsTimeouts [$mID]))
        return $this->dnsTimeouts [$mID]->restart ();
      
      // Create a new timeout
      $this->dnsTimeouts [$mID] = $eventBase->addTimeout ($this->dnsTimeout);
      $this->dnsTimeouts [$mID]->then (
        function () use ($Message, $mID) {
          // Check if the query already vanished
          if (!isset ($this->dnsQueries [$mID]))
            return;
          
          // Remove from the queue
          unset ($this->dnsQueries [$mID]);
          unset ($this->dnsTimeouts [$mID]);
          
          // Fire a callback
          $this->___callback ('dnsQuestionTimeout', $Message);
        }
      );
    }
    // }}}
    
    // {{{ consume
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * @param Events\Interface\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, Events\Interface\Source $Source) {
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
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Just raise callbacks
      $this->___callback ('eventClosed');
      
      return Events\Promise::resolve ();
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
      $this->dnsQueries = [ ];
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\Interface\Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     *  
     *   function (Events\Interface\Consumer $Self, bool $Status, mixed $Private = null) { }
     *    
     * @access public
     * @return callable
     **/
    public function initConsumer (Events\Interface\Source $Source, callable $Callback = null, $Private = null) {
      // Check if this source is already set
      if ($this->Source === $Source) {
        $this->___raiseCallback ($Callback, true, $Private);
        
        return;
      }
      
      // Check if there is an existing source
      if ($this->Source)
        $Promise = $this->deinitConsumer ($this->Source);
      else
        $Promise = Events\Promise::resolve ();
      
      $Promise->finally (
        function () use ($Source, $Callback, $Private) {
          // Reset ourself
          $this->reset ();
          
         // Set the new source
         $this->Source = $Source;
         
          // Raise an event for this
          $this->___raiseCallback ($Callback, true, $Private);
          $this->___callback ('eventPiped', $Source);
        }
      );
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\Interface\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\Interface\Stream $Source) : Events\Promise {
      // Check if this source is already set
      if ($this->Source === $Source)
        return Events\Promise::resolve ();
      
      // Check if there is an existing source
      if ($this->Source)
        $Promise = $this->deinitConsumer ($this->Source)->catch (function () { });
      else
        $Promise = Events\Promise::resolve ();
      
      return $Promise->then (
        function () use ($Source) {
          // Reset ourself
          $this->reset ();
          
          // Set the new source
          $this->Source = $Source;
          
          // Raise an event for this
          $this->___callback ('eventPipedStream', $Source);
          
          return true;
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param Events\Interface\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\Interface\Source $Source) : Events\Promise {
      // Check if this is the right source
      if ($this->Source !== $Source)
        return Events\Promise::reject ('Invalid source');
      
      // Unset the source
      $this->Source = null;
      
      // Raise an event for this
      $this->___callback ('eventUnpiped', $Source);
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    
    // {{{ dnsMessageReceived
    /**
     * Callback: A DNS-Message was received
     * 
     * @param DNS\Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsMessageReceived (DNS\Message $Message) { }
    // }}}
    
    // {{{ dnsQuestionReceived
    /**
     * Callback: A DNS-Question was received
     * 
     * @param DNS\Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsQuestionReceived (DNS\Message $Message) { }
    // }}}
    
    // {{{ dnsResponseReceived
    /** 
     * Callback: A DNS-Response was received
     *    
     * @param DNS\Message $Message
     * 
     * @access protected
     * @return void
     **/  
    protected function dnsResponseReceived (DNS\Message $Message) { }
    // }}}
    
    // {{{ dnsQuestionTimeout
    /**
     * Callback: A DNS-Question as been timed out
     * 
     * @param DNS\Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function dnsQuestionTimeout (DNS\Message $Message) { }
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
     * @param Events\Interface\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (Events\Interface\Source $Source) { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param Events\Interface\Stream $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (Events\Interface\Stream $Source) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\Interface\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (Events\Interface\Source $Source) { }
    // }}}
  }