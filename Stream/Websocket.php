<?PHP

  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Stream/HTTP/Request.php');
  require_once ('qcEvents/Stream/Websocket/Message.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  
  class qcEvents_Stream_Websocket extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* Known frame-opcodes */
    const OPCODE_CONTINUE = 0x00;
    const OPCODE_TEXT = 0x01;
    const OPCODE_BINARY = 0x02;
    const OPCODE_CLOSE = 0x08;
    const OPCODE_PING = 0x09;
    const OPCODE_PONG = 0x0A;
    
    const OPCODE_CONTROL_MASK = 0x08;
    
    /* Type of websocket-endpoint */
    const TYPE_CLIENT = 0;
    const TYPE_SERVER = 1; # Unimplemented
    
    /* Nonce for connection-setup */
    private static $Nonce = 0x00000000;
    
    /* Source-Stream for websocket-connection */
    private $Source = null;
    
    /* Received Upgrade-header */
    private $Start = null;
    
    /* Type of this endpoint */
    private $Type = qcEvents_Stream_Websocket::TYPE_CLIENT;
    
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
    private $writeMessages = array ();
    
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
    function __construct ($Type = self::TYPE_CLIENT, array $Protocols = null, $URI = null, $Origin = null) {
      if ($Type != self::TYPE_CLIENT)
        throw new Exception ('Only client-endpoints are supported at the moment');
      
      $this->Type = $Type;
      $this->Protocols = $Protocols;
      $this->URI = $URI;
      $this->Origin = $Origin;
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
      // Validate the source
      if ($Source !== $this->Source)
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
          $fLength = unpack ('nlength', substr ($this->readBuffer, $Offset, 2));
          $fLength = $fLength ['length'];
          $Offset += 2;  
        } elseif ($fLength == 127) {
          $fLength = unpack ('Jlength', substr ($this->readBuffer, $Offset, 2));
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
    private function receiveFrame ($Opcode, $Finished, $Payload, $Length) {
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
        $Message = $this->readMessage = qcEvents_Stream_Websocket_Message::factory ($this, $Opcode);
        $this->___callback ('websocketMessageStart', $Message);
      
      // Create an unfragmented message
      } elseif (!$this->readMessage && $Finished) {
        $Message = qcEvents_Stream_Websocket_Message::factory ($this, $Opcode);
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
        return;
      
      // Tell the message that it won't receive further data
      $Message->close ();
      
      // Raise the callback
      $this->___callback ('websocketMessage', $Message);
      
      // Process special messages
      if ($Message instanceof qcEvents_Stream_Websocket_Ping) {
        $Response = new qcEvents_Stream_Websocket_Pong ($this, $Message->getData ());
        $this->sendMessage ($Response);
      } elseif ($Message instanceof qcEvents_Stream_Websocket_Close)
        $this->sendMessage (new qcEvents_Stream_Websocket_Close ($this), function () {
          $this->close ();
        });
    }
    // }}}
    
    // {{{ sendMessage
    /**
     * Write out a message to the remote peer
     * 
     * @param qcEvents_Stream_Websocket_Message $Message
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_Websocket $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function sendMessage (qcEvents_Stream_Websocket_Message $Message, callable $Callback = null, $Private = null) {
      // Make sure we may write out messages
      if (($this->Start !== null) || !$this->Source) {
        $this->writeMessages [] = array ($Message, $Callback, $Private);
        
        return;
      }
      
      // Check if the message contains complete buffered data
      if ($Message->isClosed ())
        return $this->sendFrame ($Message->getOpcode (), $Message->getData (), true, $Callback, $Private);
      
      // Write out control-messages directly as single frame
      if ($Message->isControlMessage ())
        return $Message->addHook ('eventClosed', function (qcEvents_Stream_Websocket_Message $Message) {
          // Forward to the wire
          $this->sendFrame ($Message->getOpcode (), $Message->getData (), true, $Callback, $Private);
        }, null, true);
      
      // Push message to queue
      $this->writeMessages [] = array ($Message, $Callback, $Private);
      
      // Check wheter to send pending messages
      $this->sendPendingMessage ();
    }
    // }}}
    
    // {{{ sendPendingMessage
    /**
     * Prepare a pending message to be forwarded though the wire
     * 
     * @access private
     * @return void
     **/
    private function sendPendingMessage () {
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
        $this->sendFrame ($this->writeMessage [0]->getOpcode (), $this->writeMessage [0]->getData (), true, $this->writeMessage [1], $this->writeMessage [2]);
        
        // Remove active message
        $this->writeMessage = null;
        
        // Try again
        return $this->sendPendingMessage ();
      }
      
      // Register hooks on message
      $Written = $Closed = false;
      
      $this->writeMessage [0]->addHook ('eventReadable', function (qcEvents_Stream_Websocket_Message $Message) use (&$Written, &$Closed) {
        // Check if we are already closed (should never become true)
        if ($Closed) {
          trigger_error ('Attempt to forward on closed message');
          
          return;
        }
        
        // Forward to the wire
        if ($Closed = $Message->isClosed ())
          $this->sendFrame (($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()), $Message->read (), true, $this->writeMessage [1], $this->writeMessage [2]);
        else
          $this->sendFrame (($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()), $Message->read (), false);
        
        // Mark as written
        $Written = true;
      });
      
      $this->writeMessage [0]->addHook ('eventClosed', function (qcEvents_Stream_Websocket_Message $Message) use (&$Written, &$Closed) {
        // Forward to the wire
        if (!$Closed)
          $this->sendFrame (($Written ? $this::OPCODE_OPCODE_CONTINUE : $Message->getOpcode ()), $Message->getData (), true, $this->writeMessage [1], $this->writeMessage [2]);
        
        $Closed = true;
        
        // Release current message
        $this->writeMessage = null;
        
        // Check if there are pending messages
        $this->sendPendingMessage ();
      }, null, true);
      
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
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_Websocket $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access private
     * @return void
     **/
    private function sendFrame ($Opcode, $Payload, $Finish, callable $Callback = null, $Private = null) {
      // Retrive the length of payload
      $Length = strlen ($Payload);
      
      // Start the frame-header
      $Frame = pack ('CC', ($Finish ? 0x80 : 0x00) | ($Opcode & 0x0F), ($this->Type == $this::TYPE_CLIENT ? 0x80 : 0x00) | ($Length < 0x7E ? $Length : ($Length < 0x10000 ? 0x7E : 0x7F)));
      
      if ($Length > 0xFFFF)
        $Frame .= pack ('J', $Length);
      elseif ($Length > 0x7D)
        $Frame .= pack ('n', $Length);
      
      // Append Mask-Key
      if ($this->Type == $this::TYPE_CLIENT) {
        $Frame .= $maskKey = substr (md5 (rand (), true), 0, 4);
        
        for ($i = 0; $i < $Length; $i++)
          $Payload [$i] = $Payload [$i] ^ $maskKey [$i % 4];
      } else
        $maskKey = null;
      
      // Append Payload
      $Frame .= $Payload;
      unset ($Payload);
      
      // Write to source
      if ($Callback)
        return $this->Source->write ($Frame, function ($Source, $Sucess) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Success, $Private);
        });
      
      return $this->Source->write ($Frame);
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
      // Check if we should generate a handshake
      if ($this->URI === null) {
        // Register the source
        $this->Source = $Source;
        $this->Start = null;
        
        // Forward the callback
        $this->___raiseCallback ($Callback, true, $Private);
        $this->___callback ('websocketConnected');
        
        return;
      }
      
      // Create a new HTTP-Request for the Upgrade
      $Nonce = base64_encode (pack ('JJ', time (), $this::$Nonce++));
      
      $Request = new qcEvents_Stream_HTTP_Request (array (
        'GET ' . $this->URI . ' HTTP/1.1',
        'Host: ' . $Source->getRemoteHost (),
        'Connection: Upgrade',
        'Upgrade: websocket',
        'Sec-WebSocket-Version: 13',
        'Sec-WebSocket-Key: ' . $Nonce,
        # 'Sec-WebSocket-Extensions: permessage-deflate',
      ));
      
      if ($this->Origin !== null)
        $Request->setField ('Origin', $this->Origin);
      
      if ($this->Protocols !== null)
        $Request->setField ('Sec-WebSocket-Protocol', implode (', ', $this->Protocols));
      
      $this->Start = true;
      
      $Request->addHook (
        'httpFinished',
        function (qcEvents_Stream_HTTP_Request $Request, $Header)
        use ($Source, $Nonce, $Callback, $Private) {
          // Unpipe request from source
          $Source->unpipe ($Request);
          
          // Check the result
          $Success =
            ($Header->getStatus () == 101) &&
            ($Header->hasField ('Upgrade') && (strcasecmp ($Header->getField ('Upgrade'), 'websocket') == 0)) &&
            ($Header->hasField ('Connection') && (strcasecmp ($Header->getField ('Connection'), 'Upgrade') == 0)) &&
            ($Header->hasField ('Sec-WebSocket-Accept') && (strcmp ($Header->getField ('Sec-WebSocket-Accept'), base64_encode (sha1 ($Nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))) == 0));
          
          # TODO: Status may be 401 (Authz required) or 3xx (Redirect)
          
          if ($Success && ($this->Protocols !== null) && $Header->hasField ('Sec-WebSocket-Protocol') &&
              in_array ($Header->getField ('Sec-WebSocket-Protocol'), $this->Protocols))
            $this->Protocol = $Header->getField ('Sec-WebSocket-Protocol');
          elseif (($this->Protocols !== null) || $Header->hasField ('Sec-WebSocket-Protocol'))
            $Success = false;
          
          if (!$Success) {
            $this->___raiseCallback ($Callback, false, $Private);
            $this->___callback ('websocketFailed');
            
            return;
          }
          
          // Store the header as start
          $this->Start = strval ($Header);
          
          // Register the source
          $this->Source = $Source;
          
          // Forward the callback
          $this->___raiseCallback ($Callback, true, $Private);
          $this->___callback ('websocketConnected');
        }, null,
        true
      );
      
      $Request->addHook (
        'httpFailed',
        function (qcEvents_Stream_HTTP_Request $Request)
        use ($Source, $Callback, $Private) {
          // Unpipe request from source
          $Source->unpipe ($Request);
          
          // Forward the callback
          $this->___raiseCallback ($Callback, false, $Private);
          $this->___callback ('websocketFailed');
        }, null,
        true
      );
      
      // Pipe source to request
      $Source->pipe ($Request);
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
      $this->___raiseCallback ($Callback, true, $Private);
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
    
    protected function websocketConnected () { }
    protected function websocketFailed () { }
    protected function websocketMessageStart (qcEvents_Stream_Websocket_Message $Message) { }
    protected function websocketMessage (qcEvents_Stream_Websocket_Message $Message) { }
  }

?>