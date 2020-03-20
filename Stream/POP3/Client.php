<?PHP

  /**
   * qcEvents - Asyncronous POP3 Client-Stream
   * Copyright (C) 2015-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Defered.php');
  
  /**
   * POP3 Client (Stream)
   * --------------------
   * Stream-Handler for POP3-Client-Connections
   * 
   * @class qcEvents_Stream_POP3_Client
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Stream_POP3_Client extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* Defaults for POP3 */
    const DEFAULT_PORT = 110;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* POP3-Protocol-States */
    const POP3_STATE_DISCONNECTED = 0;
    const POP3_STATE_DISCONNECTING = 1;
    const POP3_STATE_CONNECTING = 2;
    const POP3_STATE_CONNECTED = 3; 
    const POP3_STATE_AUTHORIZED = 4;
    
    /* Our current protocol-state */
    private $State = qcEvents_Stream_POP3_Client::POP3_STATE_DISCONNECTED;
    
    /* Timestamp from Server-Greeting */
    private $serverTimestamp = null;
    
    /* Current command being executed */
    private $activeCommand = null;
    
    /* Queued commands */
    private $pendingCommands = array ();
    
    /* Receive-Buffer */
    private $Buffer = '';
    
    /* Server-Responses */
    private $Response = array ();
    
    /* Server-Capabilities */
    private $serverCapabilities = null;
    
    /* Handle of the attached stream */
    private $Stream = null;
    
    /* Promise for stream-initialization */
    private $initPromise = null;
    
    // {{{ getCapabilities
    /**
     * Retrive the capabilities from server
     * 
     * @access public
     * @return qcEvents_Promise Resoles to array of capabilities
     **/
    public function getCapabilities () : qcEvents_Promise {
      // Check if we already know this is unsupported
      if ($this->serverCapabilities !== null)
        return qcEvents_Promise::resolve ($this->serverCapabilities);
      
      // Issue the command
      return $this->popCommand (
        'CAPA',
        null,
        true
      )->then (
        function ($responseBody) {
          // Store received capabilities
          $this->serverCapabilities = array_slice ($responseBody, 1);
          
          // Raise a callback
          $this->___callback ('popCapabilities', $this->serverCapabilities);
          
          // Forward the result
          return $this->serverCapabilities;
        },
        function () {
          // Mark capabilities as received
          $this->serverCapabilities = array ();
          
          // Forward the rejection
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ checkCapability
    /**
     * Safely check if the server supports a given capability
     * 
     * @param string $Capability
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function checkCapability ($Capability) : qcEvents_Promise {
      return $this->getCapabilities ()->then (
        function (array $serverCapabilities) use ($Capability) {
          if (count ($serverCapabilities) == 0)
            return null;
          
          return in_array ($Capability, $serverCapabilities);
        }
      );
    }
    // }}}
    
    // {{{ haveCapability
    /**
     * Check if the server supports a given capability
     * 
     * @param string $Capability
     * 
     * @access public
     * @return bool
     **/
    public function haveCapability ($Capability) {
      // Check if we have server-capabilities
      if (!is_array ($this->serverCapabilities) ||
          (count ($this->serverCapabilities) == 0))
        return null;
      
      return in_array ($Capability, $this->serverCapabilities);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startTLS () : qcEvents_Promise {
      return $this->checkCapability ('STLS')->then (
        function ($haveCapability) {
          // Check if the server supports StartTLS
          if ($haveCapability === false)
            throw new Error ('Server does not support StartTLS');
          
          // Issue the command
          return $this->popCommand ('STLS');
        }
      )->then (
        function () {
          // Lock the command-pipeline
          $this->activeCommand = true;
          
          return $this->Stream->tlsEnable (true)->then (
            function () {
              // Unlock the command-pipeline
              if ($this->activeCommand === true)
                $this->activeCommand = null;
              
              // Restart the pipeline
              $this->popCommandNext ();
            }
          );
        },
        function () {
          // Raise a callback
          $this->___callback ('tlsFailed');
          
          // Forward the rejection
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ login
    /**
     * Perform USER/PASS login on server
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function login ($Username, $Password) : qcEvents_Promise {
      // Check if the server supports this
      return $this->checkCapability ('USER')->then (
        function ($haveCapability) use ($Username) {
          // Check if the server supports StartTLS
          if ($haveCapability === false)
            throw new Error ('Server does not support USER/PASS login-method');
          
          // Issue the command
          return $this->popCommand ('USER', array ($Username));
        }
      )->then (
        function () use ($Password) {
          // Forward the password to the server
          return $this->popCommand ('PASS', array ($Password));
        }
      )->then (
        function () use ($Username) {
          // Change our state
          $this->popSetState (self::POP3_STATE_AUTHORIZED);
          
          // Fire generic callback
          $this->___callback ('popAuthenticated', $Username);
        }
      );
    }
    // }}}
    
    // {{{ apop
    /**
     * Perform login using APOP
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function apop ($Username, $Password) : qcEvents_Promise {
      // Check if the server supports this
      if ($this->serverTimestamp === null)
        return qcEvents_Promise::reject ('Missing timestamp for APOP');
      
      // Issue the command
      return $this->popCommand (
        'APOP',
        array ($Username, md5 ($this->serverTimestamp . $Password))
      )->then (
        function () use ($Username) {
          // Change our state
          $this->popSetState (self::POP3_STATE_AUTHORIZED);
          
          // Fire generic callback
          $this->___callback ('popAuthenticated', $Username);
        }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Perform login using AUTH
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password) : qcEvents_Promise {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
    
      // Create a SASL-Client
      require_once ('qcAuth/SASL/Client.php');

      $saslClient = new qcAuth_SASL_Client;
      $saslClient->setUsername ($Username);
      $saslClient->setPassword ($Password);
      
      // Check capabilities
      return $this->getCapabilities ()->then (
        function (array $serverCapabilities) use ($saslClient, $Username) {
          // Find mechanisms to try
          $saslMechanisms = $saslClient->getMechanisms ();
          
          foreach ($serverCapabilities as $serverCapability)
            if (substr ($serverCapability, 0, 5) == 'SASL ') {
              $saslMechanisms = explode (' ', substr ($serverCapability, 5));
              
              break;
            }
          
          if (count ($saslMechanisms) == 0)
            throw new Error ('No SASL-Mechanisms available');
          
          $saslAuthenticate = null;
          $saslAuthenticate = function () use (&$saslAuthenticate, &$saslMechanisms, $saslClient, $Username) {
            // Initialize the authentication
            $saslMechanism = array_shift ($saslMechanisms);
            
            while (!$saslClient->setMechanism ($saslMechanism)) {
              if (count ($saslMechanisms) == 0)
                throw new Error ('No acceptable SASL-Mechanism found');
              
              $saslMechanism = array_shift ($saslMechanisms);
            }
            
            $saslInitial = true;
            
            return $this->popCommand (
              'AUTH',
              array ($saslMechanism),
              false,
              function () use (&$saslInitial, $saslClient) {
                if (!$saslInitial)
                  return base64_encode ($saslClient->getResponse ());
                
                $saslInitial = false;
                
                return base64_encode ($saslClient->getInitialResponse ());
              }
            )->then (
              function () use ($Username) {
                // Change our state
                $this->popSetState (self::POP3_STATE_AUTHORIZED);
                
                // Fire generic callback
                $this->___callback ('popAuthenticated', $Username);
              },
              function () use (&$saslInitial, $saslAuthenticate) {
                // Check for hard authentication-failure
                if (!$saslInitial)
                  throw new qcEvents_Promise_Solution (func_get_args ());
                
                // Try next SASL-Method
                return $saslAuthenticate ();
              }
            );
          };
          
          return $saslAuthenticate ();
        }
      );
    }
    // }}}
    
    // {{{ stat
    /**
     * Retrive statistical data about this mailbox
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function stat () : qcEvents_Promise {
      // Issue the command
      return $this->popCommand ('STAT')->then (
        function (array $responseBody) {
          // Parse the result
          $Count = null;
          $Size = null; 
          
          list ($Count, $Size) = explode (' ', $responseBody [0]);
          
          return new qcEvents_Promise_Solution (array ((int)$Count, (int)$Size));
        }
      );
    }
    // }}}
    
    // {{{ messageSize
    /**
     * Retrive the size of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function messageSize ($Index) : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'LIST',
        array ((int)$Index)
      )->then (
        function (array $responseBody) {
          return (int)explode (' ', $responseBody [0])[1];
        }
      );
    }
    // }}}
    
    // {{{ messageSizes
    /**
     * Retrive the sizes of all messages
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function messageSizes () : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'LIST',
        null,
        true
      )->then (
        function (array $responseBody) {
          // Process the result
          $messageSizes = array ();
          
          foreach (array_slice ($responseBody, 1) as $responseLine) {
            $Data = explode (' ', $responseLine);
            $messageSizes [(int)$Data [0]] = (int)$Data [1];
          }
          
          return $messageSizes;
        }
      );
    }
    // }}}
    
    // {{{ getUID
    /**
     * Retrive the UID of a given message
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getUID ($Index) : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'UIDL',
        array ((int)$Index)
      )->then (
        function (array $responseBody) {
          return explode (' ', $responseBody [0])[1];
        }
      );
    }
    // }}}
    
    // {{{ getUIDs
    /**
     * Retrive the UIDs of all messages
     *  
     * @access public
     * @return qcEvents_Promise
     **/
    public function getUIDs () : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'UIDL',
        null,
        true
      )->then (
        function (array $responseBody) {
          $messageUIDs = array ();
          
          foreach (array_slice ($responseBody, 1) as $responseLine) {
            $Data = explode (' ', $responseLine);
            $messageUIDs [(int)$Data [0]] = $Data [1];
          }
          
          return $messageUIDs;
        }
      );
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message by index
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getMessage ($Index) : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'RETR',
        array ((int)$Index),
        true
      )->then (
        function (array $responseBody) {
          return implode ("\r\n", array_slice ($responseBody, 1));
        }
      );
    }
    // }}}
    
    // {{{ getMessageLines
    /**   
     * Retrive the entire header and a given number of lines from a message
     * 
     * @param int $Index
     * @param int $Lines
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getMessageLines ($Index, $Lines) : qcEvents_Promise {
      return $this->checkCapability ('TOP')->then (
        function ($checkResult) use ($Index, $Lines) {
          // Check if the server supports this
          if ($checkResult === false)
            throw new Error ('Server does not support TOP');
          
          // Issue the command
          return $this->popCommand (
            'TOP',
            array ((int)$Index, (int)$Lines),
            true
          );
        }
      )->then (
        function (array $responseBody) {
          return implode ("\r\n", array_slice ($responseBody, 1));
        }
      );
    }     
    // }}}
    
    // {{{ deleteMessage
    /**
     * Remove a message from server
     * 
     * @param int $Index
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deleteMessage ($Index) : qcEvents_Promise {
      // Issue the command
      return $this->popCommand (
        'DELE',
        array ($Index)
      )->then (
        function () { }
      );
    } 
    // }}}
    
    // {{{ noOp
    /**
     * Merely keep the connection alive
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function noOp () : qcEvents_Promise {
      // Issue the command
      return $this->popCommand ('NOOP')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ reset
    /**   
     * Reset Message-Flags
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function reset () : qcEvents_Promise {
      // Issue the command
      return $this->popCommand ('RSET')->then (
        function () { }
      );
    }  
    // }}}
    
    // {{{ close
    /**
     * Close the POP3-Connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Check if our stream is already closed
      if (!is_object ($this->Stream)) {
        // Check if we are in disconnected state
        if ($this->State != self::POP3_STATE_DISCONNECTED) {
          // Update our state
          $this->popSetState (self::POP3_STATE_DISCONNECTED);
          
          // Raise events
          $this->___callback ('popDisconnected');
          $this->___callback ('eventClosed');
        }
        
        return qcEvents_Promise::resolve ();
      }
      
      // Query a stream-close on server-side
      return $this->popCommand ('QUIT')->then (
        function () { }
      );
    }
    // }}}
        
    
    // {{{ popCommand
    /**
     * Issue/Queue a POP3-Command
     * 
     * @param string $Keyword
     * @param array $Args (optional)
     * @param bool $Multiline (optional)
     * @param callable $intermediateCallback (optional)
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function popCommand ($Keyword, $Args = null, $Multiline = false, callable $intermediateCallback = null) : qcEvents_Promise {
      // Check our state
      if ($this->State < self::POP3_STATE_CONNECTED)
        return qcEvents_Promise::reject ('Not connected');
      
      // Create a new defered promise
      $deferedPromise = new qcEvents_Defered;
      
      // Put the command on our pipeline
      $this->pendingCommands [] = array ($deferedPromise, $Keyword, $Args, $Multiline, $intermediateCallback);
      
      // Check wheter to issue the next command directly
      $this->popCommandNext ();
      
      // Always return true
      return $deferedPromise->getPromise ();
    }
    // }}}
    
    // {{{ popCommandNext
    /**
     * Issue the next POP3-Command over the wire
     * 
     * @access private
     * @return void
     **/
    private function popCommandNext () {
      // Check if there is another command active
      if ($this->activeCommand !== null)
        return;
      
      // Check if there are commands waiting
      if (count ($this->pendingCommands) == 0)
        return;
      
      // Retrive the next command
      $this->activeCommand = array_shift ($this->pendingCommands);
  
      // Parse arguements
      if (is_array ($this->activeCommand [2]) && (count ($this->activeCommand [2]) > 0) && (strlen ($commandArguments = $this->popArgs ($this->activeCommand [2])) > 0))
        $commandArguments = ' ' . $commandArguments;
      else
        $commandArguments = '';
      
      // Write out the command
      $this->Stream->write ($this->activeCommand [1] . $commandArguments . "\r\n");
    }
    // }}}
    
    // {{{ popArgs
    /**
     * Convert a list of arguements into a suitable format for the wire
     * 
     * @param array $Args
     * 
     * @access private
     * @return string
     **/
    private function popArgs ($Args) {
      # TODO: Make this safe
      return implode (' ', $Args);
    }
    // }}}
    
    // {{{ popSetState
    /**   
     * Change the POP3-Protocol-State
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function popSetState ($State) {
      // Change the status
      $oState = $this->State;
      $this->State = $State;
      
      // Fire callback
      $this->___callback ('popStateChanged', $State, $oState);
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
    public function initStreamConsumer (qcEvents_Interface_Stream $streamSource) : qcEvents_Promise {
      // Check if this is really a new stream
      if ($this->Stream === $streamSource)
        return qcEvents_Promise::resolve ();
      
      // Check if we have a stream assigned
      if (is_object ($this->Stream))
        $waitPromise = $this->Stream->unpipe ($this)->catch (function () { });
      else
        $waitPromise = qcEvents_Promise::resolve ();
      
      return $waitPromise->then (
        function () use ($streamSource) {
          // Reset our state
          $this->Stream = $streamSource;
          $this->Buffer = '';
          $this->activeCommand = null;
          $this->pendingCommands = array ();
          $this->Response = array ();
          $this->serverCapabilities = null;
          
          $this->popSetState (self::POP3_STATE_CONNECTING);
          
          // Raise callbacks
          $this->___callback ('popConnecting');
          
          // Setup init-promise
          $this->initPromise = new qcEvents_Defered;
          
          $this->initPromise->getPromise ()->then (
            function () {
              $this->___callback ('eventPipedStream', $this->Stream);
            }
          );
          
          return $this->initPromise->getPromise ();
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
      // Check if the source is authentic
      if ($this->Stream !== $Source)
        return qcEvents_Promise::reject ('Invalid source');
      
      // Remove the stream
      $this->Stream = null;
      
      // Reset our state
      return $this->close ();
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
      // Append data to internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Read lines from buffer
      $s = 0;
      
      while (($p = strpos ($this->Buffer, "\n", $s)) !== false) {
        // Strip the line from the buffer
        $Line = substr ($this->Buffer, $s, $p - $s);
        $s = $p + 1;
        
        if (substr ($Line, -1, 1) == "\r")
          $Line = substr ($Line, 0, -1);
        
        // Process multi-line-responses
        if (($this->activeCommand !== null) && (count ($this->Response) > 0)) {
          if ($Line == '.')
            $this->popFinishResponse (true);
          else
            $this->Response [] = $Line;
          
          continue;
        }
        
        // Peek the status form the line
        if (($p = strpos ($Line, ' ')) !== false) {
          $Status = substr ($Line, 0, $p);
          $Line = substr ($Line, $p + 1); 
        } else {
          $Status = $Line;
          $Line = '';
        }
        
        // Check for continuation
        if (($this->activeCommand !== null) && ($Status == '+') && isset ($this->activeCommand [4])) {
          $rc = call_user_func ($this->activeCommand [4], $Line);
          
          $this->Stream->write (rtrim ($rc) . "\r\n");
          
          continue;
        }
        
        $Success = ($Status == '+OK');
        
        // Handle server-greeting
        if ($this->State == self::POP3_STATE_CONNECTING) {
          // Check if the server does not accept new connections
          if (!$Success) {
            if ($this->initPromise)
              $this->initPromise->reject ('Non-Successfull response from server received');
            
            $this->popSetState (self::POP3_STATE_DISCONNECTING);
            $this->initPromise = null;
            
            return $this->close ();
          }
          
          // Check for a timestamp
          if ((($p = strpos ($Line, '<')) !== false) && (($p2 = strpos ($Line, '>', $p)) !== false))
            $this->serverTimestamp = substr ($Line, $p, $p2 - $p + 1);
          else
            $this->serverTimestamp = null;
          
          // Change our status
          $this->popSetState (self::POP3_STATE_CONNECTED);
          
          // Try to determine capabilities of the server
          $this->getCapabilities ()->finally (
            function () {
              // Fire callbacks
              $this->___callback ('popConnected');
              
              if ($this->initPromise)
                $this->initPromise->resolve ();
              
              $this->initPromise = null;
            }
          );
          
          continue;
        }
        
        // Handle a normal response
        $this->Response [] = $Line;
        
        // Check if the command finished
        if (!$Success || !$this->activeCommand [3])
          $this->popFinishResponse ($Success);
      }
        
      // Truncate the buffer
      $this->Buffer = substr ($this->Buffer, $s);
    }
    // }}}
    
    // {{{ popFinishResponse
    /**
     * Finish the current command-response
     * 
     * @param bool $withSuccess
     * 
     * @access private
     * @return void
     **/
    private function popFinishResponse ($withSuccess) {
      // Reset
      $activeCommand = $this->activeCommand;  
      $Response = $this->Response;
      
      $this->activeCommand = null;
      $this->Response = array ();
      
      // Fire up any callback
      if ($activeCommand !== null) {
        if ($withSuccess)
          $activeCommand [0]->resolve ($Response);
        else
          $activeCommand [0]->reject (implode ("\n", $Response));
      }
      
      // Issue the next command
      $this->popCommandNext ();
    }
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: POP3-Stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ popStateChanged
    /**
     * Callback: Our protocol-state was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function popStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ popConnecting
    /**
     * Callback: We are connecting to the server
     * 
     * @access protected
     * @return void
     **/
    protected function popConnecting () { }
    // }}}
    
    // {{{ popConnected
    /**
     * Callback: The connection to POP3-Server was established
     * 
     * @access protected
     * @return void
     **/
    protected function popConnected () { }
    // }}}
    
    // {{{ popDisconnected
    /** 
     * Callback: POP3-Connection was closed, Client is in Disconnected-State
     *    
     * @access protected
     * @return void
     **/
    protected function popDisconnected () { }
    // }}}
    
    // {{{ popCapabilities
    /**
     * Callback: Server-Capabilities were received/changed
     * 
     * @param array $Capabilties
     * 
     * @access protected
     * @return void
     **/
    protected function popCapabilities ($Capabilities) { }
    // }}}
    
    // {{{ popAuthenticated
    /**
     * Callback: POP3-Connection was authenticated
     * 
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function popAuthenticated ($Username) { }
    // }}}
  }

?>