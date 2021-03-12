<?php

  /**
   * quarxConnect Events - Websocket-Stream
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class Websocket extends Events\Hookable implements Events\Interface\Stream\Consumer {
    /* Known frame-opcodes */
    public const OPCODE_CONTINUE = 0x00;
    public const OPCODE_TEXT = 0x01;
    public const OPCODE_BINARY = 0x02;
    public const OPCODE_CLOSE = 0x08;
    public const OPCODE_PING = 0x09;
    public const OPCODE_PONG = 0x0A;
    
    public const OPCODE_CONTROL_MASK = 0x08;
    
    /* Type of websocket-endpoint */
    public const TYPE_CLIENT = 0;
    public const TYPE_SERVER = 1;
    
    /* Nonce for connection-setup */
    private static $Nonce = 0x00000000;
    
    /* Source-Stream for websocket-connection */
    private $Stream = null;
    
    /* Received Upgrade-header */
    private $Start = null;
    
    /* Type of this endpoint */
    private $Type = Websocket::TYPE_CLIENT;
    
    /* URI for this endpoint (in client-mode) */
    private $URI = null;
    
    /* Origin for this endpoint (in client-mode) */
    private $Origin = null;
    
    /* List of supported protocols */
    private $Protocols = null;
    
    /* Actuall negotiated protocol */
    private $Protocol = null;
    
    /* Buffer for received data */
    private $readBuffer = '';
    
    /* Current message being received */
    private $readMessage = null;
    
    /* Current message being written */
    private $writeMessage = null;
    
    /* Queue for messages being written */
    private $writeMessages = [ ];
    
    // {{{ __construct
    /**
     * Create a new Websocket-Stream
     * 
     * @param enum $Type (optional)
     * @param array $Protocols (optional)
     * @param string $URI (optional)
     * @param string $Origin (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (int $Type = self::TYPE_CLIENT, array $Protocols = null, string $URI = null, string $Origin = null) {
      $this->Type = $Type;
      $this->Protocols = $Protocols;
      $this->URI = $URI;
      $this->Origin = $Origin;
    }
    // }}}
    
    // {{{ getURI
    /**
     * Retrive the URI of this websocket
     * 
     * @access public
     * @return string
     **/
    public function getURI () : ?string {
      return $this->URI;
    }
    // }}}
    
    // {{{ setURI
    /**
     * Set a new URI for this websocket
     * 
     * @param string $URI
     * 
     * @access public
     * @return void
     **/
    public function setURI ($URI) : void {
      # TODO: Make sure we are not connected yet
      
      $this->URI = $URI;
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param Events\Interface\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, Events\Interface\Source $Source) : void {
      // Validate the source
      if ($Source !== $this->Stream)
        return;
      
      // Check if we are starting (and discard data)
      if ($this->Start === true)
        return;
      
      // Check if we have a header stored
      elseif ($this->Start !== null) {
        // Compare header with data
        if (strncmp ($this->Start, $Data, strlen ($this->Start)) == 0)
          // Tuncate the header form data
          $Data = substr ($Data, strlen ($this->Start));
        
        // Remove the start-header
        $this->Start = null;
        
        // Try to send pending messages
        $this->sendPendingMessage ();
      }
      
      // Push data to buffer
      $this->readBuffer .= $Data;
      $Length = strlen ($this->readBuffer);
      
      // Try to parse frames
      $Offset = 0;
      $Consumed = 0;
      
      while ($Length >= $Offset + 2) {
        // Read and parse header of the next frame
        $fOpcode = ord ($this->readBuffer [$Offset++]);
        $fFinish = (($fOpcode & 0x80) == 0x80);
        $fRsv1 = (($fOpcode & 0x40) == 0x40);
        $fRsv2 = (($fOpcode & 0x20) == 0x20);
        $fRsv3 = (($fOpcode & 0x10) == 0x10);
        $fOpcode = ($fOpcode & 0x0F);
        
        $fLength = ord ($this->readBuffer [$Offset++]);
        $fMasked = (($fLength & 0x80) == 0x80);
        $fLength = ($fLength & 0x7F);
        
        // Check state of reserved bits and masked bit (in server mode, we expect incoming frames to be masked, in client mode not)
        if (($fRsv1 || $fRsv2 || $fRsv3) ||
            ($fMasked !== ($this->Type == $this::TYPE_SERVER))) {
          # TODO: Maybe bail out an error here
          trigger_error ('Invalid frame');
          
          // Close the source-connection
          $Source->close ();
          
          // Empty the buffer
          $this->readBuffer = '';
          
          return;
        }
        
        // Check for extended length
        if ($fLength == 126) {
          if ($Length < $Offset + 2)
            break;
          
          $fLength = unpack ('nlength', substr ($this->readBuffer, $Offset, 2));
          $fLength = $fLength ['length'];
          $Offset += 2;  
        } elseif ($fLength == 127) {
          if ($Length < $Offset + 8)
            break;
          
          $fLength = unpack ('Jlength', substr ($this->readBuffer, $Offset, 8));
          $fLength = $fLength ['length'];
          $Offset += 8;
        }
        
        // Check if we have sufficient data on the buffer
        if ($Length < $Offset + $fLength + ($fMasked ? 4 : 0))
          break;
        
        // Read the masking-key
        if ($fMasked) {
          $maskKey = substr ($this->readBuffer, $Offset, 4);
          $Offset += 4;
        } else
          $maskKey = null;
        
        // Read the payload
        $Payload = substr ($this->readBuffer, $Offset, $fLength);
        $Offset += $fLength;
        
        // Unmask
        if ($fMasked)
          for ($i = 0; $i < $fLength; $i++)
            $Payload [$i] = $Payload [$i] ^ $maskKey [$i % 4];
        
        // Process the frame
        if ($this->receiveFrame ($fOpcode, $fFinish, $Payload, $fLength) === false) {
          # TODO: Maybe bail out an error here
          trigger_error ('receiveFrame() failed');
          
          // Close the source-connection
          $Source->close ();
          
          // Empty the buffer
          $this->readBuffer = '';
          
          return;
        }
        
        // Mark the data as consumed
        $Consumed = $Offset;
      }
      
      // Truncate internal read-buffer
      if ($Consumed)
        $this->readBuffer = substr ($this->readBuffer, $Consumed);
    }
    // }}}
    
    // {{{ receiveFrame
    /**
     * Process a frame that was received by the input-parser
     * 
     * @param int $Opcode
     * @param bool $Finished
     * @param string $Payload
     * @param int $Length
     * 
     * @access private
     * @return bool
     **/
    private function receiveFrame (int $Opcode, bool $Finished, string $Payload, int $Length) : bool {
      // Sanity-Check Control-Frames
      if (($Opcode & $this::OPCODE_CONTROL_MASK) == $this::OPCODE_CONTROL_MASK) {
        // Control-frames MUST NOT be fragmented
        if (!$Finished) {
          trigger_error ('Fragmented control-message received');
          
          return false;
        }
        
        // Control-frames MUST NOT be longer than 125 bytes
        if ($Length > 125) {
          trigger_error ('control-message too long');
          
          return false;
        }
      }
      
      // Check if we need to create a fragmented message
      if (!$this->readMessage && !$Finished) {
        // Make sure current frame is start of message
        if ($Opcode == $this::OPCODE_CONTINUE) {
          trigger_error ('Continuation-Frame received without preceeding opcode');
          
          return false;
        }
        
        // Create a new message
        $Message = $this->readMessage = Websocket\Message::factory ($this, $Opcode);
        $this->___callback ('websocketMessageStart', $Message);
      
      // Create an unfragmented message
      } elseif (!$this->readMessage && $Finished) {
        $Message = Websocket\Message::factory ($this, $Opcode);
        $this->___callback ('websocketMessageStart', $Message);
      
      // Update framgented message
      } elseif ($Opcode == $this::OPCODE_CONTINUE) {
        $Message = $this->readMessage;
        
        if ($Finish)
          $this->readMessage = null;
      
      // Check for fragmented new message
      } else {
        trigger_error ('Fragmented message received before finishing the preceeding one');
        
        return false;
      }
      
      // Append Payload to current message
      $Message->sourceInsert ($Payload);
      
      // Check if an message has finished
      if (!$Finished)
        return true;
      
      // Tell the message that it won't receive further data
      $Message->close ();
      
      // Raise the callback
      $this->___callback ('websocketMessage', $Message);
      
      // Process special messages
      if ($Message instanceof Websocket\Ping) {
        $Response = new Websocket\Pong ($this, $Message->getData ());
        
        $this->sendMessage ($Response);
      } elseif ($Message instanceof Websocket\Close)
        $this->sendMessage (new Websocket\Close ($this))->then (
          function () {
            $this->close ();
          }
        );
      
      return true;
    }
    // }}}
    
    // {{{ sendMessage
    /**
     * Write out a message to the remote peer
     * 
     * @param Websocket\Message $Message
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendMessage (Websocket\Message $Message) : Events\Promise {
      // Make sure we may write out messages
      if (($this->Start === null) && $this->Stream) {
        // Check if the message contains complete buffered data
        if ($Message->isClosed ())
          return $this->sendFrame ($Message->getOpcode (), $Message->getData (), true);
        
        // Write out control-messages directly as single frame
        if ($Message->isControlMessage ())
          return $Message->once (
            'eventClosed'
          )->then (
            function () use ($Message) {
              // Forward to the wire
              return $this->sendFrame ($Message->getOpcode (), $Message->getData (), true);
            }
          );
      }
      
      // Create a new promise
      return new Events\Promise (
        function (callable $resolve, callable $reject) use ($Message) {
          // Push message to queue
          $this->writeMessages [] = [ $Message, $resolve, $reject ];
          
          // Check wheter to send pending messages
          $this->sendPendingMessage ();
        }
      );
    }
    // }}}
    
    // {{{ sendPendingMessage
    /**
     * Prepare a pending message to be forwarded though the wire
     * 
     * @access private
     * @return void
     **/
    private function sendPendingMessage () : void {
      // Check if we may send a pending message
      if ($this->writeMessage)
        return;
      
      // Check if there is a message pending
      if (count ($this->writeMessages) < 1)
        return;
      
      // Push a message to active
      $this->writeMessage = array_shift ($this->writeMessages);
      
      // Check if the message already has finished
      if ($this->writeMessage [0]->isClosed ()) {
        // Write out this message
        $this->sendFrame ($this->writeMessage [0]->getOpcode (), $this->writeMessage [0]->getData (), true)->then (
          $this->writeMessage [1],
          $this->writeMessage [2]
        );
        
        // Remove active message
        $this->writeMessage = null;
        
        // Try again
        $this->sendPendingMessage ();
        
        return;
      }
      
      // Register hooks on message
      $Written = $Closed = false;
      
      $this->writeMessage [0]->addHook (
        'eventReadable',
        function (Websocket\Message $Message) use (&$Written, &$Closed) {
          // Check if we are already closed (should never become true)
          if ($Closed) {
            trigger_error ('Attempt to forward on closed message');
            
            return;
          }
          
          // Forward to the wire
          if ($Closed = $Message->isClosed ())
            $this->sendFrame (
              ($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()),
              $Message->read (),
              true
            )->then (
              $this->writeMessage [1],
              $this->writeMessage [2]
            );
          else
            $this->sendFrame (($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()), $Message->read (), false);
          
          // Mark as written
          $Written = true;
        }
      );
      
      $this->writeMessage [0]->addHook (
        'eventClosed',
        function (Websocket\Message $Message) use (&$Written, &$Closed) {
          // Forward to the wire
          if (!$Closed)
            $this->sendFrame (
              ($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()),
              $Message->getData (),
              true
            )->then (
              $this->writeMessage [1],
              $this->writeMessage [2]
            );
          
          $Closed = true;
          
          // Release current message
          $this->writeMessage = null;
          
          // Check if there are pending messages
          $this->sendPendingMessage ();
        },
        null,
        true
      );
      
      // Make sure read-events are raised
      $this->writeMessage [0]->watchRead (true);
    }
    // }}}
    
    // {{{ sendFrame
    /**
     * Write a frame to the wire
     * 
     * @param int $Opcode
     * @param string $Payload
     * @param bool $Finish
     * 
     * @access private
     * @return Events\Promise
     **/
    private function sendFrame (int $Opcode, string $Payload, bool $Finish) : Events\Promise {
      // Make sure we have a stream assigned
      if (!$this->Stream)
        return Events\Promise::reject ('Not connected to a stream');
      
      // Retrive the length of payload
      $Length = strlen ($Payload);
      
      // Start the frame-header
      $Frame = pack (
        'CC',
        ($Finish ? 0x80 : 0x00) | ($Opcode & 0x0F),
        ($this->Type == $this::TYPE_CLIENT ? 0x80 : 0x00) | ($Length < 0x7E ? $Length : ($Length < 0x10000 ? 0x7E : 0x7F))
      );
      
      if ($Length > 0xFFFF)
        $Frame .= pack ('J', $Length);
      elseif ($Length > 0x7D)
        $Frame .= pack ('n', $Length);
      
      // Append Mask-Key
      if ($this->Type == $this::TYPE_CLIENT) {
        $Frame .= $maskKey = substr (md5 ((string)rand (), true), 0, 4);
        
        for ($i = 0; $i < $Length; $i++)
          $Payload [$i] = $Payload [$i] ^ $maskKey [$i % 4];
      } else
        $maskKey = null;
      
      // Append Payload
      $Frame .= $Payload;
      unset ($Payload);
      
      // Write to source
      return $this->Stream->write ($Frame);
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
      $this->___callback ('eventClosed');
      
      # TODO
      return Events\Promise::resolve ();
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
      // Check if we are server or a handshake isn't needed
      if (($this->Type == $this::TYPE_SERVER) ||
          ($this->URI === null)) {
        // Register the source
        $this->Stream = $Source;
        $this->Start = null;
        
        // Raise events
        $this->___callback ('websocketConnected');
        $this->___callback ('eventPipedStream', $Source);
        
        return Events\Promise::resolve ();
      }
      
      // Create a new HTTP-Request for the Upgrade
      $Nonce = base64_encode (pack ('JJ', time (), self::$Nonce++));
      
      $Request = new HTTP\Request ([
        'GET ' . $this->URI . ' HTTP/1.1',
        'Host: ' . $Source->getRemoteHost (),
        'Connection: Upgrade',
        'Upgrade: websocket',
        'Sec-WebSocket-Version: 13',
        'Sec-WebSocket-Key: ' . $Nonce,
        # 'Sec-WebSocket-Extensions: permessage-deflate',
      ]);
      
      if ($this->Origin !== null)
        $Request->setField ('Origin', $this->Origin);
      
      if ($this->Protocols !== null)
        $Request->setField ('Sec-WebSocket-Protocol', implode (', ', $this->Protocols));
      
      $this->Start = true;
      
      // Pipe source to request
      return $Source->pipeStream ($Request)->then (
        function () use ($Request) {
          return $Request->once ('httpFinished');
        },
        function () use ($Source, $Request) {
          // Unpipe the request from the source
          $Source->unpipe ($Request);
          
          // Raise local callback for this
          $this->___callback ('websocketFailed');
          
          // Forward the error
          throw new Events\Promise\Solution (func_get_args ());
        }
      )->then (
        function (HTTP\Header $Header)
        use ($Source, $Request, $Nonce) {
          // Unpipe request from source
          return $Source->unpipe ($Request)->then (
            function () use ($Header, $Source, $Nonce) {
              // Check the result
              $Success =
                ($Header->getStatus () == 101) &&
                ($Header->hasField ('Upgrade') && (strcasecmp ($Header->getField ('Upgrade'), 'websocket') == 0)) &&
                ($Header->hasField ('Connection') && (strcasecmp ($Header->getField ('Connection'), 'Upgrade') == 0)) &&
                ($Header->hasField ('Sec-WebSocket-Accept') && (strcmp ($Header->getField ('Sec-WebSocket-Accept'), base64_encode (sha1 ($Nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))) == 0));

              # TODO: Status may be 401 (Authz required) or 3xx (Redirect)

              if ($Success &&
                  ($this->Protocols !== null) &&
                  $Header->hasField ('Sec-WebSocket-Protocol') &&
                  in_array ($Header->getField ('Sec-WebSocket-Protocol'), $this->Protocols))
                $this->Protocol = $Header->getField ('Sec-WebSocket-Protocol');
              elseif (($this->Protocols !== null) || $Header->hasField ('Sec-WebSocket-Protocol'))
                $Success = false;

              if (!$Success) {
                $this->___callback ('websocketFailed');

                throw new \Exception ('Failed to negotiate websocket-connection');
              }

              // Store the header as start
              $this->Start = strval ($Header);

              // Register the source
              $this->Stream = $Source;

              // Raise events
              $this->___callback ('websocketConnected');
              $this->___callback ('eventPipedStream', $Source);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\Interface\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\Interface\Source $Source) : Events\Promise {
      // Check if the source is our stream
      if ($this->Stream === $Source) {
        // Remove reference to stream
        $this->Stream = null;
        
        // Raise a callback
        $this->___callback ('eventUnpiped', $Source);
      }
      
      // Return a resolved promise
      return Events\Promise::resolve ();
    }
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
    protected function eventPipedStream (Events\Interface\Stream $Source) : void { }
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
    protected function eventUnpiped (Events\Interface\Source $Source) : void { }
    // }}}
    
    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () : void { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () : void { }
    // }}}
    
    // {{{ websocketConnected
    /**
     * Callback: Websocket-Connection was negotiated
     * 
     * @access protected
     * @return void
     **/
    protected function websocketConnected () : void { }
    // }}}
    
    // {{{ websocketFailed
    /**
     * Callback: Failed to negotiate Websocket-Connection
     * 
     * @access protected
     * @return void
     **/
    protected function websocketFailed () : void { }
    // }}}
    
    // {{{ websocketMessageStart
    /**
     * Callback: A new Websocket-message has been started receiving
     * 
     * @param Websocket\Message $websocketMessage
     * 
     * @access protected
     * @return void
     **/
    protected function websocketMessageStart (Websocket\Message $websocketMessage) : void { }
    // }}}
    
    // {{{ websocketMessage
    /**
     * Callback: A Websocket-Message was completely received
     * 
     * @param Websocket\Message $websocketMessage
     * 
     * @access protected
     * @return void
     **/
    protected function websocketMessage (Websocket\Message $websocketMessage) : void { }
    // }}}
  }
