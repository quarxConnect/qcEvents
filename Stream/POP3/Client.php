<?PHP

  /**
   * qcEvents - Asyncronous POP3 Client-Stream
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
  
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  
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
    /* Defaults for IMAP */
    const DEFAULT_PORT = 110;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* POP3-Protocol-States */
    const POP3_STATE_CONNECTING = 1;
    const POP3_STATE_CONNECTED = 2; 
    const POP3_STATE_AUTHORIZED = 3;
    const POP3_STATE_DISCONNECTING = 4;
    const POP3_STATE_DISCONNECTED = 0;
    
    /* Our current protocol-state */
    private $State = qcEvents_Stream_POP3_Client::POP3_STATE_DISCONNECTED;
    
    /* Timestamp from Server-Greeting */
    private $serverTimestamp = null;
    
    /* Current command being executed */
    private $Command = null;
    
    /* Queued commands */
    private $Commands = array ();
    
    /* Receive-Buffer */
    private $Buffer = '';
    
    /* Server-Responses */
    private $Response = array ();
    
    /* Server-Capabilities */
    private $Capabilities = null;
    
    /* Handle of the attached stream */
    private $Stream = null;
    
    // {{{ getCapabilities
    /**
     * Retrive the capabilities from server
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, array $Capabilities, bool $Status, mixed $Private) { }
     * 
     * @access public
     * @return bool
     **/
    public function getCapabilities (callable $Callback = null, $Private = null) {
      // Check if we already know this is unsupported
      if ($this->Capabilities !== null) {
        $this->___raiseCallback ($Callback, (is_array ($this->Capabilities) ? $this->Capabilities : null), is_array ($this->Capabilities), $Private);
        
        return true;
      }
      
      // Issue the command
      return $this->popCommand ('CAPA', array ($Index), false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Callback, $Private) {
        // Check if the server supports capabilities
        if ($Success) {
          $this->Capabilities = array_slice ($Response, 1);
          $this->___callback ('popCapabilities', $this->Capabilities);
        } else
          $this->Capabilities = false;
        
        // Fire callback
        $this->___raiseCallback ($Callback, (is_array ($this->Capabilities) ? $this->Capabilities : null), $Success, $Private);
      });
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
      if (!is_array ($this->Capabilities))
        return null;
      
      return in_array ($Capability, $this->Capabilities);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function startTLS (callable $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
      
      // Check if the server supports this
      if ($this->haveCapability ('STLS') === false) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('STLS', null, false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Callback, $Private) {
        // Check if the server accepted TLS-negotiation
        if (!$Success) {
          $this->___callback ('tlsFailed');
          
          return $this->___raiseCallback ($Callback, false, $Private);
        }
        
        // Lock the command-pipeline
        $this->Command = true;
        
        // Proceed with TLS-negotiation
        $this->Stream->tlsEnable (true, function (qcEvents_Socket $Socket, $Status) {
          // Unlock the command-pipeline
          if ($this->Command === true)
            $this->Command = null;
          
          // Raise the callback
          $this->___raiseCallback ($Callback, $Status === true, $Private);
          
          // Restart the pipeline
          $this->popCommandNext ();
        });
      });
    }
    // }}}
    
    // {{{ login
    /**
     * Perform USER/PASS login on server
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, string $Username, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function login ($Username, $Password, callable $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
      
      // Check if the server supports this
      if ($this->haveCapability ('USER') === false) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('USER', array ($Username), false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Username, $Password, $Callback, $Private) {
        // Check if the server accepted the USER-Command
        if (!$Success)
          return $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        // Forward the password to the server
        $this->popCommand ('PASS', array ($Password), false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Username, $Password, $Callback, $Private) {
          // Handle success
          if ($Success) {
            // Change our state
            $this->popSetState (self::POP3_STATE_AUTHORIZED);
            
            // Fire generic callback
            $this->___callback ('popAuthenticated', $Username);
          }
          
          // Raise the final callback
          $this->___raiseCallback ($Callback, $Username, $Success, $Private);
        });
      });
    }
    // }}}
    
    // {{{ apop
    /**
     * Perform login using APOP
     * 
     * @param string $Username
     * @param string $Password
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, string $Username, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function apop ($Username, $Password, callable $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
        
      // Check if the server supports this
      if ($this->serverTimestamp === null) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('APOP', array ($Username, md5 ($this->serverTimestamp . $Password)),  false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Username, $Password, $Callback, $Private) {
        // Check if the server accepted the USER-Command
        if (!$Success)
          return $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        // Change our state
        $this->popSetState (self::POP3_STATE_AUTHORIZED);
        
        // Fire generic callback
        $this->___callback ('popAuthenticated', $Username);
        
        // Raise the final callback
        $this->___raiseCallback ($Callback, $Username, $Success, $Private);
      });
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Perform login using AUTH
     * 
     * @param string $Username
     * @param string $Password
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, string $Username, bool $Status, mixed $Private = null) { }
     * 
     * @access public 
     * @return bool   
     **/
    public function authenticate ($Username, $Password, $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
    
      // Create a SASL-Client
      require_once ('qcAuth/SASL/Client.php');

      $Client = new qcAuth_SASL_Client;
      $Client->setUsername ($Username);
      $Client->setPassword ($Password);
      
      // Check capabilities
      if (is_array ($this->Capabilities)) {
        $Mechs = array ();
        
        foreach ($this->Capabilities as $Capability)  
          if (substr ($Capability, 0, 5) == 'SASL ') {
            $Mechs = explode (' ', substr ($Capability, 5));
            break;
          } 
      } else
        $Mechs = $Client->getMechanisms ();
      
      if (count ($Mechs) == 0) {
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
      
      // Initialize the authentication
      $Mech = array_shift ($Mechs);
      
      while (!$Client->setMechanism ($Mech)) {
        if (count ($Mechs) == 0) {
          $this->___raiseCallback ($Callback, $Username, false, $Private);
          
          return false;
        }
        
        $Mech = array_shift ($Mechs);
      }
       
      $Initial = true;
      $saslFunc = null;
      
      $saslFunc = function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Username, $Password, $Callback, $Private, $Client, &$Initial, &$Mech, &$Mechs, &$saslFunc) {
        // Check for a SASL-Continuation
        if ($Success === null) {
          if ($Initial) {
            $Initial = false;
            
            return base64_encode ($Client->getInitialResponse ());
          }
        
          return base64_encode ($Client->getResponse ());
        
        // Check if SASL-Negotiation failed
        } elseif ($Success === false) {
          // Check if there are more SASL-Mechanisms available
          if ($Initial && (count ($Mechs) > 0)) {
            // Try to pick the next SASL-Mechanism
            $Mech = array_shift ($Mechs);
            
            while (!$Client->setMechanism ($Mech)) {
              if (count ($Mechs) == 0)
                return $this->___raiseCallback ($Callback, $Username, false, $Private);
              
              $Mech = array_shift ($Mechs);
            }
            
            // Switch to other SASL-Mechanism
            return $this->popCommand ('AUTH', array ($Mech), false, $saslFunc, null, $saslFunc);
          }
          
          return $this->___raiseCallback ($Callback, $Username, false, $Private);
        }
        
        // Change our state
        $this->popSetState (self::POP3_STATE_AUTHORIZED);
        
        // Fire generic callback
        $this->___callback ('popAuthenticated', $Username);
        
        // Raise the final callback
        $this->___raiseCallback ($Callback, $Username, $Success, $Private);
      };
      
      return $this->popCommand ('AUTH', array ($Mech), false, $saslFunc, null, $saslFunc);
    }
    // }}}
    
    // {{{ stat
    /**
     * Retrive statistical data about this mailbox
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Messages, int $Size, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function stat (callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, null, null, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('STAT', null, false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Callback, $Private) {
        // Parse the result
        $Count = null;
        $Size = null; 
        
        if ($Success)
          list ($Count, $Size) = explode (' ', $Response [0]);
        
        // Raise the callback
        $this->___raiseCallback ($Callback, ($Count !== null ? (int)$Count : null), ($Size != null ? (int)$Size : null), false, $Private);
      });
    }
    // }}}
    
    // {{{ messageSize
    /**
     * Retrive the size of a given message
     * 
     * @param int $Index
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Index, int $Size, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function messageSize ($Index, callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, (int)$Index, null, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('LIST', array ((int)$Index), false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Index, $Callback, $Private) {
        // Raise the callback
        $this->___raiseCallback ($Callback, (int)$Index, ($Success ? (int)explode (' ', $Response [0])[1] : null), $Success, $Private);
      });
    }
    // }}}
    
    // {{{ messageSizes
    /**
     * Retrive the sizes of all messages
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, array $Sizes, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function messageSizes (callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, null, false, $Private);
        return false;
      }
       
      // Issue the command
      return $this->popCommand ('LIST', null, true, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Callback, $Private) {
        // Process the result
        if ($Success) {
          $Result = array ();
          
          for ($i = 1; $i < count ($Response); $i++) {
            $Data = explode (' ', $Response [$i]);
            $Result [(int)$Data [0]] = (int)$Data [1];
          }
        } else
          $Result = null;
        
        // Raise the callback
        $this->___raiseCallback ($Callback, $Result, $Success, $Private);
      });
    }
    // }}}
    
    // {{{ getUID
    /**
     * Retrive the UID of a given message
     * 
     * @param int $Index
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Index, string $UID, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function getUID ($Index, callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, (int)$Index, null, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('UIDL', array ((int)$Index), false, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Index, $Callback, $Private) {
        // Raise the callback
        $this->___raiseCallback ($Callback, (int)$Index, ($Success ? explode (' ', $Response [0])[1] : null), $Success, $Private);
      });
    }
    // }}}
    
    // {{{ getUIDs
    /**
     * Retrive the UIDs of all messages
     *  
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, array $UIDs, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool   
     **/
    public function getUIDs (callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, null, false, $Private);
        
        return false;
      }
    
      // Issue the command
      return $this->popCommand ('UILD', null, true, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Callback, $Private) {
        // Process the result
        if ($Success) {
          $Result = array ();
       
          for ($i = 1; $i < count ($Response); $i++) {
            $Data = explode (' ', $Response [$i]);
            $Result [(int)$Data [0]] = $Data [1];
          }
        } else
          $Result = null;
        
        // Raise the callback
        $this->___raiseCallback ($Callback, $Result, $Success, $Private);
      });
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message by index
     * 
     * @param int $Index
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Index, string $Message, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function getMessage ($Index, callable $Callback, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, (int)$Index, null, false, $Private);
        
        return false;
      }
        
      // Issue the command
      return $this->popCommand ('RETR', array ((int)$Index), true, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Index, $Callback, $Private) {
        // Retrive the whole message
        if ($Success) {
          array_shift ($Response);
          $Message = implode ("\r\n", $Response);
        } else
          $Message = null;
        
        // Raise the callback
        $this->___raiseCallback ($Callback, (int)$Index, $Message, $Success, $Private);
      });
    }
    // }}}
    
    // {{{ getMessageLines
    /**   
     * Retrive the entire header and a given number of lines from a message
     * 
     * @param int $Index
     * @param int $Lines
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Index, int $Lines, string $Message, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function getMessageLines ($Index, $Lines, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, (int)$Index, (int)$Lines, null, false, $Private);
        
        return false;
      }
      
      // Check if the server supports this
      if ($this->haveCapability ('TOP') === false) {
        $this->___raiseCallback ($Callback, (int)$Index, (int)$Lines, null, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('TOP', array ((int)$Index, (int)$Lines), true, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) use ($Index, $Lines, $Callback, $Private) {
        // Retrive the whole message
        if ($Success) {
          array_shift ($Response);
          $Message = implode ("\r\n", $Response);
        } else
          $Message = null;
        
        // Raise the callback
        $this->___raiseCallback ($Callback, (int)$Index, (int)$Lines, $Message, $Success, $Private);
      });
    }     
    // }}}
    
    // {{{ deleteMessage
    /**
     * Remove a message from server
     * 
     * @param int $Index
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, int $Index, bool $Status, mixed $Private = null) { }
     * 
     * @access public     
     * @return bool
     **/
    public function deleteMessage ($Index, callable $Callback = null, $Private = null) {
      // Check our state  
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, (int)$Index, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('DELE', array ($Index), false, function (qcEvents_Stream_POP3_Client $Self, $Success) use ($Index, $Callback, $Private) {
        $this->___raiseCallback ($Callback, (int)$Index, $Success, $Private);
      });
    } 
    // }}}
    
    // {{{ noOp
    /**
     * Merely keep the connection alive
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function noOp (callable $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('NOOP', null, false, function (qcEvents_Stream_POP3_Client $Self, $Success) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Success, $Private);
      });
    }
    // }}}
    
    // {{{ reset
    /**   
     * Reset Message-Flags
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_POP3_Client $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function reset (callable $Callback = null, $Private = null) {
      // Check our state 
      if ($this->State != self::POP3_STATE_AUTHORIZED) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
      
      // Issue the command
      return $this->popCommand ('RSET', null, false, function (qcEvents_Stream_POP3_Client $Self, $Success) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Success, $Private);
      });
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
          $this->popSetState (self::POP3_STATE_DISCONNECTED);
          $this->___callback ('popDisconnected');
        }
        
        return qcEvents_Promise::resolve ();
      }
      
      // Query a stream-close on server-side
      return new qcEvents_Promise (function ($resolve, $reject) {
        return $this->popCommand ('QUIT', null, false, function (qcEvents_Stream_POP3_Client $Self, $Success) use ($resolve, $reject) {
          if ($Success)
            $resolve ();
          else
            $reject ('Command failed');
        });
      });
    }
    // }}}
        
    
    // {{{ popCommand
    /**
     * Issue/Queue a POP3-Command
     * 
     * @param string $Keyword
     * @param array $Args (optional)
     * @param bool $Multiline (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return bool
     **/
    private function popCommand ($Keyword, $Args = null, $Multiline = false, callable $Callback = null, $Private = null) {
      // Put the command on our pipeline
      $this->Commands [] = func_get_args ();
      
      // Check wheter to issue the next command directly
      $this->popCommandNext ();
      
      // Always return true
      return true;
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
      if ($this->Command !== null)
        return;
      
      // Check if there are commands waiting
      if (count ($this->Commands) == 0)
        return;
      
      // Retrive the next command
      $this->Command = array_shift ($this->Commands);
  
      // Parse arguements
      if (is_array ($this->Command [1]) && (count ($this->Command [1]) > 0) && (strlen ($Args = $this->popArgs ($this->Command [1])) > 0))
        $Args = ' ' . $Args;
      else
        $Args = '';
      
      // Write out the command
      $this->Stream->write ($this->Command [0] . $Args . "\r\n");
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
      // Check if this is really a new stream
      if ($this->Stream === $Source)
        return;
      
      // Check if we have a stream assigned
      if (is_object ($this->Stream))
        $this->Stream->unpipe ($this);
      
      // Reset our state
      $this->Stream = $Source;
      $this->Buffer = '';
      $this->Command = null;
      $this->Commands = array ();
      $this->Response = array ();
      $this->Capabilities = null;
      
      $this->popSetState (self::POP3_STATE_CONNECTING);
      
      // Raise callbacks
      $this->___callback ('eventPipedStream', $Source);
      $this->___callback ('popConnecting');
      $this->___raiseCallback ($Callback, true, $Private);
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
        
        // Process multi-line-responses
        if (($this->Command !== null) && (count ($this->Response) > 0)) {
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
        if (($this->Command !== null) && ($Status == '+') && isset ($this->Command [5])) {
          $rc = call_user_func ($this->Command [5], null, $Line, (isset ($this->Command [6]) ? $this->Command [6] : null));
          
          $this->Stream->write (rtrim ($rc) . "\r\n");
          
          continue;
        }
        
        $Success = ($Status == '+OK');
        
        // Handle server-greeting
        if ($this->State == self::POP3_STATE_CONNECTING) {
          // Check if the server does not accept new connections
          if (!$Success) {
            $this->popSetState (self::POP3_STATE_DISCONNECTING);
            
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
          $this->popCommand ('CAPA', null, true, function (qcEvents_Stream_POP3_Client $Self, $Success, $Response) {
            // Check if the server supports capabilities
            if ($Success)
              $this->Capabilities = array_slice ($Response, 1);
            else
              $this->Capabilities = false;
            
            // Fire callbacks
            $this->___callback ('popConnected');
            
            if ($Success)
              $this->___callback ('popCapabilities', $this->Capabilities);
          });
          
          continue;
        }
        
        // Handle a normal response
        $this->Response [] = $Line;
        
        // Check if the command finished
        if (!$Success || !$this->Command [2])
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
     * @param bool $Success
     * 
     * @access private
     * @return void
     **/
    private function popFinishResponse ($Success) {
      // Reset
      $Command = $this->Command;  
      $Response = $this->Response;
      
      $this->Command = null;
      $this->Response = array ();
      
      // Fire up any callback
      if (is_callable ($Command [3]))
        call_user_func ($Command [3], $Success, $Response, (isset ($Command [4]) ? $Command [4] : null));
      
      // Issue the next command
      $this->popCommandNext ();
    }
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