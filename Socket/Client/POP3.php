<?PHP

  /**
   * qcEvents - Asyncronous POP3 Client
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket/Client.php');
  
  /**
   * POP3 Client (RFC 1939)
   * ----------------------
   * Simple asyncronous POP3-Client Interface
   * 
   * @class qcEvents_Socket_Client_POP3
   * @extends qcEvents_Socket_Client
   * @package qcEvents
   * @revision 01
   * 
   * @changelog 20130624 Added Support for RFC 1939 POP3
   *            20130624 Added Support for RFC 2449 Capabilities for POP3
   *            20130625 Added Support for RFC 2595 Using TLS with POP3
   *            20130625 Added Support for RFC 1734 SASL-AUTH
   **/
  class qcEvents_Socket_Client_POP3 extends qcEvents_Socket_Client {
    /* Defaults for IMAP */
    const DEFAULT_PORT = 110;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* POP3-Protocol-States */
    const POP3_STATE_CONNECTING = 1;
    const POP3_STATE_CONNECTED = 2;
    const POP3_STATE_AUTHORIZED = 3;
    const POP3_STATE_DISCONNECTING = 4;
    const POP3_STATE_DISCONNECTED = 0;
    
    /* Defaults for this client-protocol */
    const USE_LINE_BUFFER = true;
    
    /* Our current protocol-state */
    private $State = qcEvents_Socket_Client_POP3::POP3_STATE_DISCONNECTED;
    
    /* Timestamp from Server-Greeting */
    private $serverTimestamp = null;
    
    /* Current command being executed */
    private $Command = null;
    
    /* Queued commands */
    private $Commands = array ();
    
    /* Server-Responses */
    private $Response = array ();
    
    /* Server-Capabilities */
    private $Capabilities = null;
    
    /* TLS-Callback */
    private $popTLSCallback = null;
    
    // {{{ getCapabilities
    /**
     * Retrive the capabilities from server
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function getCapabilities ($Callback = null) {
      // Check if we already know this is unsupported
      if ($this->Capabilities !== null)
        return $this->popCallbackStatus (array ('popCapabilities', 'popCapabilitiesFailed'), $Callback, is_array ($this->Capabilities), $this->Capabilities);
      
      // Issue the command
      return $this->popCommand ('CAPA', array ($Index), false, array ($this,'popHandleCapabilities'), $Callback);
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
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function startTLS ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED)
        return false;
      
      // Check if the server supports this
      if ($this->haveCapability ('STLS') === false)
        return false;
      
      // Issue the command
      return $this->popCommand ('STLS', null, false, array ($this, 'popHandleTLS'), $Callback);
    }
    // }}}
    // {{{ popHandleLogin
    /**
     * Internal Callback: Check wheter to enable TLS on this connection
     * 
     * @param bool $Success  
     * @param array $Response
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function popHandleTLS ($Success, $Response, $Private) {
      // Check if the server does not want us to enable TLS
      if (!$Success) {
        $this->___callback ('tlsFailed');
        
        return $this->popCallbackStatus (array ('popTLS', 'popTLSFailed'), $Private, $Success);
      }
      
      // Start TLS-Negotiation
      $this->popTLSCallback = $Private;
      $this->Command = true;
      $this->tlsEnable (true, array ($this, 'popHandleTLSReady'));
    }
    // }}}
    
    // {{{ popHandleTLSReady
    /**
     * Internal Callback: TLS-Negotiation was completed
     * 
     * @param bool $Status
     * 
     * @access protected
     * @return void
     **/
    protected final function popHandleTLSReady ($Status) {
      // Get the requested callback
      $Callback = $this->popTLSCallback;
      $this->popTLSCallback = null;
      
      // Unblock the command-pipeline
      if ($this->Command === true)
        $this->Command = null;
      
      // Fire callbacks
      $this->popCallbackStatus (array ('popTLS', 'popTLSFailed'), $Callback, $Status);
      
      // Restart the pipeline
      $this->popCommandNext ();
    }
    // }}}
    
    // {{{ login
    /**
     * Perform USER/PASS login on server
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function login ($Username, $Password, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED)
        return false;
      
      // Check if the server supports this
      if ($this->haveCapability ('USER') === false)
        return false;
      
      // Issue the command
      return $this->popCommand ('USER', array ($Username), false, array ($this, 'popHandleLogin'), array ($Username, $Password, $Callback, 0));
    }
    // }}}
    
    // {{{ apop
    /**
     * Perform login using APOP
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void  
     **/
    public function apop ($Username, $Password, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED)
        return false;
        
      // Check if the server supports this
      if ($this->serverTimestamp === null)
        return false;
      
      // Issue the command
      return $this->popCommand ('APOP', array ($Username, md5 ($this->serverTimestamp . $Password)),  false, array ($this, 'popHandleLogin'), array ($Username, $Password, $Callback, 2));
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Perform login using AUTH
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * 
     * @access public 
     * @return void   
     **/
    public function authenticate ($Username, $Password, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_CONNECTED)
        return false;
      
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
      
      if (count ($Mechs) == 0)
        return false;
      
      // Initialize the authentication
      $Mech = array_shift ($Mechs);
      
      while (!$Client->setMechanism ($Mech)) {
        if (count ($Mechs) == 0)
          return false;
        
        $Mech = array_shift ($Mechs);
      }
      
      $Private = array (
        0 => $Username,
        1 => $Password,
        2 => $Callback,
        3 => 3,
        4 => 0,
        5 => $Mech,
        6 => $Mechs,
        7 => $Client
      );
      
      return $this->popCommand ('AUTH', array ($Mech), false, array ($this, 'popHandleLogin'), $Private, array ($this, 'popHandleLogin'), $Private);
    }
    // }}}
    
    // {{{ popHandleLogin
    /**
     * Internal Callback: Handle results from login-process
     * 
     * @param bool $Success
     * @param array $Response
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function popHandleLogin ($Success, $Response, $Private) {
      // Check for a SASL-Continuation
      if (($Success === null) && ($Private [3] == 3)) {
        if ($this->Command [6][4]++ == 0)
          return base64_encode ($Private [7]->getInitialResponse ());
        
        return base64_encode ($Private [7]->getResponse ());
      }
      
      // Check if command was successfull
      if ($Success === false) {
        // Check if there are more SASL-Mechanisms available
        if (($Private [3] == 3) && ($Private [4] == 0) && (count ($Private [6]) > 0)) {
          $Private [5] = array_shift ($Private [6]);
          
          while (!$Private [7]->setMechanism ($Private [5])) {
            if (count ($Private [6]) == 0) {
              $Private [5] = false;
              break;
            }
            
            $Private [5] = array_shift ($Private [6]);  
          }
          
          if ($Private [5] !== false)
            return $this->popCommand ('AUTH', array ($Private [5]), false, array ($this, 'popHandleLogin'), $Private, array ($this, 'popHandleLogin'), $Private);
        }
        
        return $this->popCallbackStatus (array ('popLogin', 'popLoginFailed'), $Private [2], $Success);
      }
      
      switch ($Private [3]) {
        // This is a response for USER
        case 0:
          $Private [3] = 1;
          
          return $this->popCommand ('PASS', array ($Private [1]), false, array ($this, 'popHandleLogin'), $Private);
        
        // This is a response for PASS
        case 1:
        // This is a response for APOP
        case 2:
          // Change our state
          $this->popSetState (self::POP3_STATE_AUTHORIZED);
          
          return $this->popCallbackStatus (array ('popLogin', 'popLoginFailed'), $Private [2], $Success);
        
        // Response / Continuation for AUTH
        case 3:
          // Change our state
          $this->popSetState (self::POP3_STATE_AUTHORIZED);
          
          return $this->popCallbackStatus (array ('popLogin', 'popLoginFailed'), $Private [2], $Success);
      }
    }
    // }}}
    
    // {{{ logout
    /**
     * Sign off from server
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function logout ($Callback = null) {
      return $this->popCommand ('QUIT', null, false, array ($this,'popCallbacks'), array ($Callback));
    }
    // }}}
    
    // {{{ stat
    /**
     * Retrive statistical data about this mailbox
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function stat ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Issue the command
      return $this->popCommand ('STAT', null, false, array ($this, 'popHandleStat'), $Callback);
    }
    // }}}
    
    // {{{ popHandleStat
    /**
     * Internal Callback: Handle a STAT-Response
     * 
     * @param bool $Success
     * @param array $Response
     * @param callback $Callback
     * 
     * @access private
     * @return void
     **/
    private function popHandleStat ($Success, $Response, $Callback) {
      $Count = null;
      $Size = null;
      
      if ($Success)
        list ($Count, $Size) = explode (' ', $Response [0]);
      
      return $this->popCallbackStatus (array ('popStatus', 'popStatusFailed'), $Callback, $Success, $Count, $Size);
    }
    // }}}
    
    // {{{ messageSize
    /**
     * Retrive the size of a given message
     * 
     * @param int $Index
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function messageSize ($Index, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Issue the command
      return $this->popCommand ('LIST', array ($Index), false, array ($this, 'popHandleList'), array (array ('popMessageSize', 'popMessageSizeFailed'), $Callback));
    }
    // }}}
    
    // {{{ messageSizes
    /**
     * Retrive the sizes of all messages
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function messageSizes ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
       
      // Issue the command
      return $this->popCommand ('LIST', null, true, array ($this, 'popHandleList'), array (array ('popMessageSize', 'popMessageSizeFailed'), $Callback));
    }
    // }}}
    
    // {{{ getUID
    /**
     * Retrive the UID of a given message
     * 
     * @param int $Index
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function getUID ($Index, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;   
      
      // Issue the command
      return $this->popCommand ('UIDL', array ($Index), false, array ($this, 'popHandleList'), array (array ('popMessageUID', 'popMessageUIDFailed'), $Callback));
    }
    // }}}
    
    // {{{ getUIDs
    /**
     * Retrive the UIDs of all messages
     *  
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void     
     **/
    public function getUIDs ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
    
      // Issue the command
      return $this->popCommand ('UILD', null, true, array ($this, 'popHandleList'), array (array ('popMessageUID', 'popMessageUIDFailed'), $Callback));
    }
    // }}}
    
    // {{{ popHandleList
    /**
     * Internal Callback: Handle response for LIST
     * 
     * @param bool $Success
     * @param array $Response
     * @param callback $Callback
     * 
     * @access private
     * @return void
     **/
    private function popHandleList ($Success, $Response, $Callbacks) {
      $Result = null;
      
      if ($Success && (count ($Response) == 1)) {
        $Data = explode (' ', $Response [0]);
        $Result = array ($Data [0] => $Data [1]);
      } elseif ($Success) {
        $Result = array ();
        
        for ($i = 1; $i < count ($Response); $i++) {
          $Data = explode (' ', $Response [$i]);
          $Result [$Data [0]] = $Data [1];
        }
      }
      
      return $this->popCallbackStatus ($Callbacks [0], $Callbacks [1], $Success, $Result);
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message by index
     * 
     * @param int $Index
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function getMessage ($Index, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
        
      // Issue the command
      return $this->popCommand ('RETR', array ($Index), true, array ($this, 'popHandleRetr'), array ($Index, false, $Callback));
    }
    // }}}
    
    // {{{ getMessageLines
    /**   
     * Retrive the entire header and a given number of lines from a message
     * 
     * @param int $Index
     * @param int $Lines
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function getMessageLines ($Index, $Lines, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Check if the server supports this
      if ($this->haveCapability ('TOP') === false)
        return false;
      
      // Issue the command
      return $this->popCommand ('TOP', array ($Index, $Lines), true, array ($this, 'popHandleRetr'), array ($Index, $Lines, $Callback)); 
    }  
    // }}}
    
    // {{{ popHandleRetr
    /**
     * Internal Callback: Forward a retrived message
     * 
     * @param bool $Success
     * @param array $Response
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function popHandleRetr ($Success, $Response, $Private) {
      // Retrive the whole body
      if ($Success) {
        array_shift ($Response);
        $Body = implode ("\r\n", $Response);
      } else
        $Body = null;
      
      // Fire the callback
      if ($Private [1] === false)
        return $this->popCallbackStatus (array ('popMessage', 'popMessageFailed'), $Private [1], $Success, $Private [0], $Body);
      
      return $this->popCallbackStatus (array ('popMessageLines', 'popMessageLinesFailed'), $Private [1], $Success, $Private [0], $Private [1], $Body);
    }
    // }}}
    
    // {{{ deleteMessage
    /**
     * Remove a message from server
     * 
     * @param int $Index
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function deleteMessage ($Index, $Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Issue the command
      return $this->popCommand ('DELE', array ($Index), false, array ($this,'popCallbacks'), array ($Callback, null, null, $Index));
    }  
    // }}}
    
    // {{{ noOp
    /**
     * Merely keep the connection alive
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function noOp ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Issue the command
      return $this->popCommand ('NOOP', null, false, array ($this,'popCallbacks'), array ($Callback));
    }  
    // }}}
    
    // {{{ reset
    /**
     * Reset Message-Flags
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function reset ($Callback = null) {
      // Check our state
      if ($this->State != self::POP3_STATE_AUTHORIZED)
        return false;
      
      // Issue the command
      return $this->popCommand ('RSET', null, false, array ($this,'popCallbacks'), array ($Callback));
    } 
    // }}}
    
    
    // {{{ popCommand
    /**
     * Issue/Queue a POP3-Command
     * 
     * @param string $Keyword
     * @param array $Args (optional)
     * @param bool $Multiline (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return void
     **/
    private function popCommand ($Keyword, $Args = null, $Multiline = false, $Callback = null, $Private = null) {
      // Put the command on our pipeline
      $this->Commands [] = func_get_args ();
      
      // Check wheter to issue the next command directly
      $this->popCommandNext ();
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
      $this->mwrite ($this->Command [0], $Args, "\r\n");
      echo 'OUT: ', $this->Command [0], $Args, "\n";
    }
    // }}}
    
    private function popArgs ($Args) {
      return implode (' ', $Args);
    }
    
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
    
    // {{{ popCallbacks
    /**
     * Internal Callback: Issue callbacks depending on status
     * 
     * @param bool $Success
     * @param array $Response
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function popCallbacks ($Success, $Response, $Private) {
      $Internal = array ();
      
      if (count ($Private) > 1)
        $Internal [0] = $Private [1];
      
      if (count ($Private) > 2)
        $Internal [1] = $Private [2];
      
      $Args = $Private;
      $Args [0] = $Internal;
      $Args [1] = $Private [0];
      $Args [2] = $Success;
      
      return call_user_func_array (array ($this, 'popCallbackStatus'), $Args);
    }
    // }}}
    
    // {{{ popCallbackStatus
    /**
     * Fire callbacks and append status
     * 
     * @param array $Internal
     * @param callback $External
     * @param bool $Status
     * 
     * @access private
     * @return void
     **/
    private function popCallbackStatus ($Internal, $External, $Status) {
      $Args = array_slice (func_get_args (), 3);
      
      if (is_callable ($External)) {
        $eArgs = $Args;
        
        array_unshift ($eArgs, $this);
        array_push ($eArgs, $Status);
        
        call_user_func_array ($External, $eArgs);
      }
      
      if (!is_array ($Internal) || (count ($Internal) == 0))
        return;
      
      if ($Status)
        $Callback = array_shift ($Internal);
      else
        $Callback = array_pop ($Internal);
      
      array_unshift ($Args, $Callback);
      
      call_user_func_array (array ($this, '___callback'), $Args);
    }
    // }}}
    
    // {{{ receivedLine
    /**
     * A single POP3-Line was received
     * 
     * @param string $Line
     *    
     * @access protected
     * @return void
     **/
    protected final function receivedLine ($Line) {
      echo 'IN: ', $Line, "\n";
      
      // Process multi-line-responses
      if (($this->Command !== null) && (count ($this->Response) > 0)) {
        if ($Line == '.')
          $this->popFinishResponse (true);
        else
          $this->Response [] = $Line;
        
        return;
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
        
        echo 'OUT-CONT: ', rtrim ($rc), "\n";
        return $this->mwrite (rtrim ($rc), "\r\n");
      }
      
      $Success = ($Status == '+OK');
      
      // Handle server-greeting
      if ($this->State == self::POP3_STATE_CONNECTING) {
        // Check if the server does not accept new connections
        if (!$Success) {
          $this->popSetState (self::POP3_STATE_DISCONNECTING);
          
          return $this->disconnect ();
        }
        
        // Check for a timestamp
        if ((($p = strpos ($Line, '<')) !== false) && (($p2 = strpos ($Line, '>', $p)) !== false))
          $this->serverTimestamp = substr ($Line, $p, $p2 - $p + 1);
        else
          $this->serverTimestamp = null;
        
        // Change our status
        $this->popSetState (self::POP3_STATE_CONNECTED);
        
        // Try to determine capabilities of the server
        return $this->popCommand ('CAPA', null, true, array ($this, 'popHandleCapaOnConnect'));
      }
      
      // Handle a normal response
      $this->Response [] = $Line;
      
      // Check if the command finished
      if (!$Success || !$this->Command [2])
        $this->popFinishResponse ($Success);
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
    
    // {{{ popHandleCapabilities
    /**
     * Internal callback: Handle CAPA-Response
     * 
     * @param bool $Success Command was successfull
     * @param array $Response All response-lines
     * @param array $Callback
     * 
     * @access private
     * @return void
     **/
    private function popHandleCapabilities ($Success, $Response, $Callback) {
      // Check if the server supports capabilities
      if ($Success)
        $this->Capabilities = array_slice ($Response, 1);
      else
        $this->Capabilities = false;
      
      // Fire callbacks
      $this->popCallbackStatus (array ('popCapabilities', 'popCapabilitiesFailed'), $Callback, $Success, $this->Capabilities);
    }
    // }}}
    
    // {{{ popHandleCapaOnConnect
    /**
     * Internal Callback: Handle CAPA-Response after Server-Greeting
     * 
     * @param bool $Success Command was successfull
     * @param array $Response All response-lines
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return void
     **/
    private function popHandleCapaOnConnect ($Success, $Response, $Private) {
      // Check if the server supports capabilities
      if ($Success)
        $this->Capabilities = array_slice ($Response, 1);
      else
        $this->Capabilities = false;
      
      $this->___callback ('popConnected');
      
      if ($Success)
        $this->popCallbackStatus (array ('popCapabilities', 'popCapabilitiesFailed'), null, $Success, $this->Capabilities);
    }
    // }}}
    
    // {{{ socketConnected
    /**
     * Occupied Callback: Underlying connection was established
     * 
     * @access protected
     * @return void
     **/
    protected final function socketConnected () {
      $this->popSetState (self::POP3_STATE_CONNECTING);
    }
    // }}}
    
    // {{{ socketDisconnected
    /**
     * Occupied Callback: Underlying connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected final function socketDisconnected () {
      $this->popSetState (self::POP3_STATE_DISCONNECTED);
      $this->___callback ('popDisconnected');
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
    protected function popStateChanged ($newState, $oldState) { echo 'POP3: State changed from ', $oldState, ' to ', $newState, "\n"; }
    // }}}
    
    // {{{ popConnected
    /**
     * Callback: POP3-Connection was established, Client is in Connected-State
     * 
     * @access protected
     * @return void
     **/
    protected function popConnected () { echo 'POP3: Connected', "\n"; }
    // }}}
    
    // {{{ popLogin
    /**
     * Callback: POP3-Login was successfull
     * 
     * @access protected
     * @return void
     **/
    protected function popLogin () { echo 'POP3: Login successfull', "\n"; }
    // }}}
    
    // {{{ popLoginFailed
    /**
     * Callback: POP3-Login failed
     * 
     * @access protected
     * @return void
     **/
    protected function popLoginFailed () { echo 'POP3: Login failed', "\n"; }
    // }}}
    
    // {{{ popDisconnected
    /** 
     * Callback: POP3-Connection was closed, Client is in Disconnected-State
     *    
     * @access protected
     * @return void
     **/
    protected function popDisconnected () { echo 'POP3: Disconnected', "\n"; }
    // }}}
  }

?>
