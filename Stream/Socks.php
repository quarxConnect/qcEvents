<?PHP

  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Abstract/Source.php');
  require_once ('qcEvents/Interface/Stream.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  
  class qcEvents_Stream_Socks extends qcEvents_Abstract_Source implements qcEvents_Interface_Stream, qcEvents_Interface_Stream_Consumer {
    /* Timeouts */
    const NEGOTIATE_TIMEOUT = qcEvents_Socket::CONNECT_TIMEOUT;
    const AUTHENTICATE_TIMEOUT = 30;
    const CONNECT_TIMEOUT = qcEvents_Socket::CONNECT_TIMEOUT * 2;
    
    /* Connection-types */
    const TYPE_TCP = 1;
    const TYPE_TCP_SERVER = 2;
    const TYPE_UDP = 3;
    
    /* Stream we are connected to */
    private $Stream = null;
    
    /* Buffer for incoming data */
    private $Buffer = '';
    
    /* Version we are using */
    const SOCKS4  = 40;
    const SOCKS4a = 41;
    const SOCKS5  = 50;
    
    private $Version = qcEvents_Stream_Socks::SOCKS5;
    
    /* Mode of this stream */
    const MODE_CLIENT = 0;
    const MODE_SERVER = 1;
    
    private $Mode = qcEvents_Stream_Socks::MODE_CLIENT;
    
    /* Protocol-State */
    const STATE_OPEN   = 0; // Connection is open, but unauthenticated
    const STATE_UNAUTH = 1; // Connection is open and requires authentication
    const STATE_AUTH   = 2; // Connection is open and authenticated
    const STATE_CONN   = 3; // Connection is connected ;)
    const STATE_CLOSE  = 4; // Connection is closed
    
    private $State = qcEvents_Stream_Socks::STATE_CLOSE;
    
    /* Offset password-authentication during greeting (since SOCKS5) */
    private $offerPasswordAuthentication = false;
    
    /* Promise-Callbacks for current operation */
    private $promiseCallbacks = null;
    
    // {{{ authenticate
    /**
     * Try to authenticate using username/password
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password) : qcEvents_Promise {
      // Sanatize state
      if (($this->State != self::STATE_UNAUTH) ||
          $this->promiseCallbacks)
        return qcEvents_Promise::reject ('Invalid state');
      
      // Write the username/password to the wire
      $this->Stream->write (
        "\x01" .
        chr (strlen ($Username) & 0xFF) . substr ($Username, 0, 255) . 
        chr (strlen ($Password) & 0xFF) . substr ($Password, 0, 255)
      );
      
      // Create a promise
      return $this->createPromiseCallbacks (self::AUTHENTICATE_TIMEOUT);
    }
    // }}}
    
    // {{{ connect
    /**
     * Request a connection through the socks-server we are connected to
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function connect ($Host, $Port, $Type = qcEvents_Stream_Socks::TYPE_TCP) : qcEvents_Promise {
      // Sanatize state
      if (($this->State != self::STATE_AUTH) ||
          $this->promiseCallbacks)
        return qcEvents_Promise::reject ('Invalid state');
      
      // Sanatize port
      if (($Port < 0x0001) || ($Port > 0xFFFF))
        return qcEvents_Promise::reject ('Invalid port');
      
      // Sanatize type
      if (($Type > qcEvents_Stream_Socks::TYPE_TCP_SERVER) &&
          ($this->Version < self::SOCKS5))
        return qcEvents_Promise::reject ('SOCKS4 only supports TCP-Connection-Types');
      
      // Check if we have to process an IPv6-Connection
      if ($isIPv6 = qcEvents_Socket::isIPv6 ($Host)) {
        // Make sure we are running at least in SOCKS5-Mode
        if ($this->Version < self::SOCKS5)
          return qcEvents_Promise::reject ('IPv6 is only supported since SOCKS5');
      
      // Check if we have to process a domainname for connection
      } elseif (!($isIPv4 = qcEvents_Socket::isIPv4 ($Host))) {
        if ($this->Version < self::SOCKS4a)
          # TODO: Try to resolve manually here
          return qcEvents_Promise::reject ('SOCKS4 cannot connect to a domainname');
      }
      
      // Build the message
      if ($this->Version == self::SOCKS5) {
        $Message = pack ('CCC', 5, $Type, 0);
        
        if ($isIPv4)
          $Message .= "\x01" . pack ('N', ip2long ($Host));
        elseif ($isIPv6)
          $Message .= "\x04" . qcEvents_Socket::ip6toBinary ($Host);
        else
          $Message .= "\x03" . chr (strlen ($Host) & 0xFF) . substr ($Host, 0, 255);
        
        $Message .= pack ('n', $Port);
        
      } else {
        // Write protocol-version, connection-type and port to message
        $Message = pack ('CCn', 4, $Type, $Port);
        
        // Write host to message
        if (!$isIPv4)
          $Message .= pack ('NC', 0xFF, 0) . $Host . "\x00";
        else
          $Message .= pack ('NC', ip2long ($Host), 0);
      }
      
      $this->Stream->write ($Message);
      
      // Create a promise
      return $this->createPromiseCallbacks ($this::CONNECT_TIMEOUT);
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
      // Push to internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Process bytes on the buffer
      $Length = strlen ($this->Buffer);
      $Offset = 0;
      
      while ($Offset < $Length) {
        // Check if we are expecting a response to our greeting
        if ($this->State == self::STATE_OPEN) {
          // We only expect this in SOCKS5
          if ($this->Version != self::SOCKS5)
            return $this->failConnection ('Unknown message in open-state');
          
          // Check if there are enough bytes on the buffer
          if ($Length - $Offset < 2)
            break;
          
          // Read the greeting
          if (ord ($this->Buffer [$Offset++]) != 0x05)
            return $this->failConnection ('Invalid version in greeting');
          
          $AuthMethod = ord ($this->Buffer [$Offset++]);
          
          switch ($AuthMethod) {
            case 0x00:
              $this->State = self::STATE_AUTH;
              
              break;
            case 0x02:
              $this->State = self::STATE_UNAUTH;
              
              break;
            case 0xFF:
              return $this->failConnection ('No valid authentication-methods offered');
            
            default:
              return $this->failConnection ('Invalid authentication-method requested');
          }
          
          $this->runPromiseCallback ();
          
          break;
        // Check if we are expecting an authentication-response
        } elseif ($this->State == self::STATE_UNAUTH) {
          // We only expect this in SOCKS5
          if ($this->Version != self::SOCKS5)
            return $this->failConnection ('Unknown message in unauth-state');
          
          // Check if there are enough bytes on the buffer
          if ($Length - $Offset < 2)
            break;
          
          // Check authentication-version
          if (ord ($this->Buffer [$Offset++]) != 0x01)
            return $this->failConnection ('Invalid version for user-authentication');
          
          // Check status
          if (ord ($this->Buffer [$Offset++]) == 0x00)
            $this->State = self::STATE_AUTH;
          
          // Run callbacks
          $this->runPromiseCallback ($this->State == self::STATE_AUTH ? 0 : 1);
          
          // Fail if authentication wasn't successfull
          if ($this->State < self::STATE_AUTH)
            return $this->failConnection ('Authentication failed');
          
          break;
        // Check if we are expecting a connection-response
        } elseif ($this->State == self::STATE_AUTH) {
          // Check SOCKS4-Response
          if ($this->Version < self::SOCKS5) {
            // Check if there are enough bytes on the buffer
            if ($Length - $Offset < 8)
              break;
            
            if (ord ($this->Buffer [$Offset++]) != 0x00)
              return $this->failConnection ('First byte is not NULL');
            
            if (($Result = ord ($this->Buffer [$Offset++])) != 0x5A)
              return $this->failConnection ('Connection failed with ' . $Result);
            
            if ($this->Version >= self::SOCKS4a) {
              $Port = (ord ($this->Buffer [$Offset++]) << 8) |
                       ord ($this->Buffer [$Offset++]);
              $IP = ip2long (
                (ord ($this->Buffer [$Offset++]) << 24) |
                (ord ($this->Buffer [$Offset++]) << 16) |
                (ord ($this->Buffer [$Offset++]) << 8) |
                 ord ($this->Buffer [$Offset++])
              );
            } else {
              $IP = $Port = null;
              $Offset += 6;
            }
          } else {
            // Check if there are enough bytes on the buffer
            if ($Length - $Offset < 8)
              break;
            
            $nOffset = $Offset;
            
            if (ord ($this->Buffer [$nOffset++]) != 0x05)
              return $this->failConnection ('Invalid version');
            
            if (($Result = ord ($this->Buffer [$nOffset++])) != 0x00)
              return $this->failConnection ('Connection failed with ' . $Result);
            
            if (ord ($this->Buffer [$nOffset++]) != 0x00)
              return $this->failConnection ('Reserved byte is not NULL');
            
            $Type = ord ($this->Buffer [$nOffset++]);
            
            if ($Type == 0x01) {
              if ($Length - $nOffset < 6)
                break;
              
              $IP = ip2long (
                (ord ($this->Buffer [$nOffset++]) << 24) |
                (ord ($this->Buffer [$nOffset++]) << 16) |
                (ord ($this->Buffer [$nOffset++]) << 8) |
                 ord ($this->Buffer [$nOffset++])
              );
            } elseif ($Type == 0x04) {
              if ($Length - $nOffset < 18)
                break;
              
              $IP = qcEvents_Socket::ip6fromBinary (substr ($this->Buffer, $nOffset, 16));
              $nOffset += 16;
            } elseif ($Type == 0x03) {
              $dLength = ord ($this->Buffer [$nOffset++]);
              
              if ($Length - $nOffset < $dLength + 2)
                break;
              
              $IP = substr ($this->Buffer, $nOffset, $dLength);
              $nOffset += $dLength;
            } else
              return $this->failConnection ('Invalid address-type');
            
            $Offset = $nOffset;
            
            $Port = (ord ($this->Buffer [$Offset++]) << 8) |
                     ord ($this->Buffer [$Offset++]);
          }
          
          // Update our state
          $this->State = self::STATE_CONN;
          
          // Run callbacks
          $this->runPromiseCallback (0, $IP, $Port);
        } elseif ($this->State == self::STATE_CONN) {
          $this->sourceInsert (substr ($this->Buffer, $Offset, $Length - $Offset));
          $Offset = $Length;
        } else
          break;
      }
      
      // Truncate read bytes from buffer
      if ($Offset > 0)
        $this->Buffer = substr ($this->Buffer, $Offset);
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $Data The data to write to this sink
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function write ($Data) : qcEvents_Promise {
      if (($this->Stream === null) ||
          ($this->State != self::STATE_CONN))
        return qcEvents_Promise::reject ('Not connected');
      
      return $this->Stream->write ($Data);
    }
    // }}}
    
    // {{{ watchWrite
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     *  
     * @access public
     * @return bool
     **/
    public function watchWrite ($Set = null) {
      if ($this->Stream === null)
        return false;
      
      return $this->Stream->watchWrite ($Set);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Update our state
      $this->State = self::STATE_CLOSE;
      
      // Run a callback
      $this->___callback ('eventClosed');
      
      // Indicate success
      return qcEvents_Promise::resolve ();
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
      if ($Callback)
        call_user_func ($Callback, $this, false, $Private);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source) : qcEvents_Promise {
      # TODO: Remove any previously assigned stream
      
      // Assign the stream
      $this->Stream = $Source;
      $this->Stream->addHook ('eventWritable', array ($this, 'raiseWritable'));
      
      // Send initial greeting if we are a SOCKS5-Client
      if (($this->Version == self::SOCKS5) && ($this->Mode == self::MODE_CLIENT)) {
        // Update our state
        $this->State = self::STATE_OPEN;
        
        // Send the initial greeting
        if ($this->offerPasswordAuthentication)
          $this->Stream->write ("\x05\x02\x00\x02");
        else
          $this->Stream->write ("\x05\x01\x00");
        
        // Create a promise
        $Promise = $this->createPromiseCallbacks (self::NEGOTIATE_TIMEOUT);
      } else {
        // Update our state
        $this->State = self::STATE_AUTH;
        
        // Create a resolved promise
        $Promise = qcEvents_Promise::resolve ();
      }
      
      // Return the promise
      return $Promise->then (
        function () use ($Source) {
          // Make sure our stream is still as expected
          if ($this->Stream !== $Source)
            throw new exception ('Race-condition');
          
          // Run a callback
          $this->___callback ('eventPipedStream', $Source);
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Check if the source matches our stream
      if ($this->Stream !== $Source)
        return qcEvents_Promise::resolve ();
      
      // Remove the stream
      $this->Stream->removeHook ('eventWritable', array ($this, 'raiseWritable'));
      $this->Stream = null;
      
      // Close ourself and finish
      return $this->close ()->finally (
        function () use ($Source) {
          $this->___callback ('eventUnpiped', $Source);
        }
      );
    }
    // }}}
    
    // {{{ raiseWritable
    /**
     * Just raise a writable event here if suitable
     * 
     * @access public
     * @return void
     **/
    public function raiseWritable () {
      if ($this->Stream && ($this->State == self::STATE_CONN))
        $this->___callback ('eventWritable');
    }
    // }}}
    
    // {{{ failConnection
    /**
     * Fail the connection due to an error
     * 
     * @param string $Message
     * 
     * @access private
     * @return void
     **/
    private function failConnection ($Message) {
      $this->close ();
      
      $this->runPromiseCallback (1, $Message);
    }
    // }}}
    
    // {{{ createPromiseCallbacks
    /**
     * Create and register a promise, optionally with a timeout
     * 
     * @param int $Timeout (optional)
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function createPromiseCallbacks ($Timeout = null) : qcEvents_Promise {
      return new qcEvents_Promise (
        function (callable $Resolve, callable $Reject) use ($Timeout) {
          // Try to create a timeout-function if requested
          if (($Timeout > 0) &&
              (($this->Stream instanceof qcEvents_Interface_Common) ||
               ($this->Stream instanceof qcEvents_Interface_Loop)) &&
              is_object ($eventBase = $this->Stream->getEventBase ())) {
            $Timeout = $eventBase->addTimeout ($Timeout);
            
            $Timeout->then (
              function () {
                $this->failConnection ('Operation timed out');
              }
            );
          } else
            $Timeout = null;
          
          // Register the promise
          $this->promiseCallbacks = array ($Resolve, $Reject, $Timeout);
        }
      );
    }
    // }}}
    
    // {{{ runPromiseCallback
    /**
     * Run any pending promise-callback
     * 
     * @param int $ID (optional)
     * @param ...
     * 
     * @access private
     * @return void
     **/
    private function runPromiseCallback ($ID = 0) {
      // Make sure there is a promise registered
      if (!$this->promiseCallbacks)
        return;
      
      // Stop any timer
      if ($this->promiseCallbacks [2])
        $this->promiseCallbacks [2]->cancel ();
      
      // Get and clear the callbacks
      $cb = $this->promiseCallbacks;
      $this->promiseCallbacks = null;
      
      // Run the callback
      $args = func_get_args ();
      array_shift ($args);
      
      call_user_func_array ($cb [$ID], $args);
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
    
    // {{{ eventWritable
    /**
     * Callback: A writable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventWritable () { }
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
  }

?>