<?PHP

  /**
   * qcEvents - Asyncronous SMTP Client
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket/Client.php');
     
  /**
   * SMTP-Client
   * ----------- 
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qcEvents_Socket_Client_SMTP
   * @extends qcEvents_Socket_Client
   * @package qcEvents
   * @revision 02
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * @changelog 20130703 Added Support for RFC 3207 StartTLS
   *            20130703 Added basic Support for RFC 2034 Enhanced Status Codes
   *            20130703 Added Support for RFC 1870 SMTP Size Declaration
   *            20130704 Added Support for RFC 1985 ETRN Command (remote queue startup)
   *            20130705 Added Support for RFC 4954 SMTP Authentication
   **/
  class qcEvents_Socket_Client_SMTP extends qcEvents_Socket_Client {
    /* Default configuration for our socket */
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    const DEFAULT_PORT = 25;
    
    const USE_LINE_BUFFER = true;
    
    /* Protocol state */
    const SMTP_STATE_DISCONNECTED = 0;
    const SMTP_STATE_CONNECTING = 1;
    const SMTP_STATE_CONNECTED = 2;
    const SMTP_STATE_TRANSACTION = 3;
    const SMTP_STATE_DISCONNECTING = 4;
    
    private $State = qcEvents_Socket_Client_SMTP::SMTP_STATE_DISCONNECTED;
    
    /* Is this connection authenticated */
    private $authenticated = false;
    
    /* State for handshake */
    private $connectingState = 0;
    
    /* Command-Buffer */
    private $Command = null;
    private $Commands = array ();
    
    /* Response-Buffer */
    private $resppnseCode = null;
    private $responseLines = array ();
    
    /* Last response from server */
    private $lastCode = null;
    private $lastLines = null;
    
    /* Queued mails */
    private $mailCurrent = null;
    private $mailQueue = array ();
    
    /* Domain of server */
    private $serverDomain = null;
    
    /* Features supported by the server */
    private $serverFeatures = null;
    
    // {{{ __construct
    /**
     * Create a new SMTP-Client
     *  
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
        
      // Register hooks
      $this->addHook ('socketConnected', array ($this, 'smtpClientConnected'));
    }
    // }}}
    
    // {{{ getClientName
    /**
     * Retrive the name of this client
     * 
     * @access protected
     * @return string
     **/
    protected function getClientName () {
      if (function_exists ('gethostname'))
        return gethostname ();
      
      return 'smtpc.quarxconnect.org';
    }
    // }}}
    
    // {{{ getLastCode
    /**
     * Retrive the last result-code
     * 
     * @access public
     * @return int
     **/
    public function getLastCode () {
      return $this->lastCode;
    }
    // }}}
    
    // {{{ quit
    /**
     * Ask the server to close this session
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function quit ($Callback = null, $Private = null) {
      // Issue the command
      return $this->smtpCommand ('QUIT', null, null, null, array ($this, 'smtpCallbackSimple'), array ($Callback, $Private), self::SMTP_STATE_DISCONNECTING);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function startTLS ($Callback = null, $Private = null) {
      // Check if the server supports StartTLS
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['STARTTLS']))
        return $this->smtpCallbackStatus (502, array (), array ($Callback, $Private));
      
      // Check if TLS is already active
      if ($this->tlsEnable ())
        return $this->smtpCallbackStatus (220, array (), array ($Callback, $Private));
      
      // Issue the command
      return $this->smtpCommand ('STARTTLS', null, null, null, array ($this, 'smtpHandleTLS'), array ($Callback, $Private), self::SMTP_STATE_CONNECTING, self::SMTP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ smtpHandleTLS
    /**
     * Internal Callback: Handle TLS-Setup
     * 
     * @access protected
     * @return void
     **/
    protected final function smtpHandleTLS ($Code, $Lines = null, $Private = null) {
      // Fire final callback
      if (is_array ($Private) && ($Private [0] === 1))
        return $this->smtpCallbackStatus (220, array (), $Private [1]);
      
      // Check if our parent TLS-Handler is returning
      if (($Code === true) || ($Code === null)) {
        // Free the current command
        $this->Command = null;
        
        // Check if negotiation failed
        if (!$Code)
          return $this->smtpCallbackStatus (454, array (), $Lines);
        
        // Issue a EHLO-Command
        $this->connectingState = 1;
        $this->serverFeatures = null;
        
        return $this->smtpCommand ('EHLO', array ($this->getClientName ()), null, null, array ($this, 'smtpHandleTLS'), array (1, $Lines));
      }
      
      // Check for a successfull response
      if ($Code >= 300) {
        $this->smtpCallbackStatus ($Code, array (), $Private);
        
        return $this->___callback ('tlsFailed');
      }
      
      // Try to start TLS
      $this->Command = true;
      $this->tlsEnable (true, array ($this, 'smtpHandleTLS'), $Private);
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this connection
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function authenticate ($Username, $Password, $Callback = null, $Private = null) {
      // Check if the server supports Authentication
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['AUTH']))
        return $this->smtpCallbackStatus (502, array (), array ($Callback, $Private));
      
      // Don't authenticate twice
      if ($this->authenticated)
        return $this->smtpCallbackStatus (503, array (), array ($Callback, $Private));
      
      // Create an authenticator
      require_once ('qcAuth/SASL/Client.php');
      
      $Client = new qcAuth_SASL_Client;
      $Client->setUsername ($Username);
      $Client->setPassword ($Password);
      
      // Override private data
      $Private = array (
        0 => null,
        1 => $this->serverFeatures ['AUTH'],
        2 => $Client,
        3 => $Callback,
        4 => $Private,
      );
      
      // Let our handler do the first round
      $this->smtpAuthenticateHandler (535, array (), $Private);
    }
    // }}}
    
    // {{{ smtpAuthenticateHandler
    /**
     * Internal Callback: Process Authentication
     * 
     * @param int $Code
     * @param array $Lines
     * @param arrya $Private
     * 
     * @access private
     * @return void
     **/
    private function smtpAuthenticateHandler ($Code, $Lines, $Private) {
      // Fire final callback
      if (is_array ($Private) && ($Private [0] === 1))
        return $this->smtpCallbackStatus ($Private [1], array (), $Private [2]);
      
      // Check if the authentication was successfull
      if (($Code >= 200) && ($Code < 300)) {
        // Mark ourself as authenticated
        $this->authenticated = true;
        
        // Issue a EHLO-Command   
        $this->connectingState = 1;   
        $this->serverFeatures = null;     
       
        return $this->smtpCommand ('EHLO', array ($this->getClientName ()), null, null, array ($this, 'smtpAuthenticateHandler'), array (1, $Code, array ($Private [3], $Private [4])));
      }
      
      // Check wheter to send the next chunk
      if (($Code >= 300) && ($Code < 400))
        return base64_encode ($Private [2]->getResponse ());
      
      // Assume authentication has failed
      if (count ($Private [1]) == 0)
        return $this->smtpCallbackStatus ($Code, $Lines, array ($Private [3], $Private [4]));
      
      $Mechanism = array_shift ($Private [1]);
      
      while (!$Private [2]->setMechanism ($Mechanism)) {
        if (count ($Private [1]) == 0)
          return $this->smtpCallbackStatus ($Code, $Lines, array ($Private [3], $Private [4]));
        
        $Mechanism = array_shift ($Private [1]);
      }
      
      $Private [0] = $Mechanism;
      
      // Issue the AUTH-Command
      return $this->smtpCommand ('AUTH', array ($Private [0], base64_encode ($Private [2]->getInitialResponse ())), array ($this, 'smtpAuthenticateHandler'), $Private, array ($this, 'smtpAuthenticateHandler'), $Private, self::SMTP_STATE_CONNECTING, self::SMTP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ sendMail
    /**
     * Submit an entire mail
     * 
     * @param string $Originator
     * @param array $Receivers
     * @param string $Mail
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function sendMail ($Originator, $Receivers, $Mail, $Callback = null, $Private = null) {
      // Check the size
      if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['SIZE']) && (count ($this->serverFeatures ['SIZE']) > 0) && (strlen ($Mail) > $this->serverFeatures ['SIZE'][0]) && ($this->serverFeatures ['SIZE'][0] > 0))
        $this->smtpCallbackStatus (552, array (), array ($Callback, $Originator, array (), $Mail, $Private));
      
      // Enqueue the mail
      $this->mailQueue [] = array ($Originator, $Receivers, $Mail, $Callback, $Private, $Receivers, array ());
      
      // Try to start the submission
      $this->runMailQueue ();
    }
    // }}}
    
    // {{{ runMailQueue
    /**
     * Check wheter to enqueue the next mail
     * 
     * @access private
     * @return void
     **/
    private function runMailQueue () {
      // Check if there is a mail being transmitted
      if ($this->mailCurrent !== null)
        return;
      
      // Check if the queue is empty
      if (count ($this->mailQueue) == 0)
        return;
      
      // Enqueue the next mail
      $this->mailCurrent = array_shift ($this->mailQueue);
      
      // Generate parameters
      if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['SIZE']))
        $Params = array ('SIZE' => strlen ($this->mailCurrent [2]));
      else
        $Params = null;
      
      // Start the submission
      $this->startMail ($this->mailCurrent [0], $Params, array ($this, 'handleMailSubmission'), 0);
    }
    // }}}
    
    // {{{ handleMailSubmission
    /**
     * Internal Callback: Handle submission of mail-queue
     * 
     * @param mixed $Arg1
     * @param mixed $Arg2
     * @param mixed $Arg3 (optional)
     * 
     * @access private
     * @return void
     **/
    private function handleMailSubmission ($Arg1, $Arg2, $Arg3 = null) {
      // Multiplex parameters
      if ($Arg3 === null) {
        $Response = null;
        $Status = $Arg1;
        $Private = $Arg2;
      } else {
        $Response = $Arg1;
        $Status = $Arg2;
        $Private = $Arg3;
      }
      
      // Proceed to next step
      $Error = false;
      
      switch ($Private) {
        case 0: // MAIL FROM was successfull
          // Check the status
          if ($Error = !$Status)
            break;
          
        case 1: // RCPT TO was successfull
          // Check wheter to append to successfull receivers
          if ($Status)
            $this->mailCurrent [6][] = $Response;
          else
            $this->mailCurrent [7] = $this->lastCode;
          
          // Check wheter to submit the mail
          if (count ($this->mailCurrent [5]) == 0) {
            if (count ($this->mailCurrent [6]) == 0) {
              $Error = true;
              
              if (isset ($this->mailCurrent [7]))
                $this->lastCode = $this->mailCurrent [7];
              
              break;
            }
            
            return $this->sendData ($this->mailCurrent [2], array ($this, 'handleMailSubmission'), 2);
          }
          
          // Submit the next receiver
          $this->addReceiver (array_shift ($this->mailCurrent [5]), null, array ($this, 'handleMailSubmission'), 1);
          
          break;
        
        case 2: // DATA was successfull
          // Fire the callback
          $this->smtpCallbackStatus ($this->lastCode, $this->lastLines, array ($this->mailCurrent [3], $this->mailCurrent [0], $this->mailCurrent [6], $this->mailCurrent [2], $this->mailCurrent [4]));
          
          // Clear the current mail
          $this->mailCurrent = null;
          
          // Enqueue the next one
          $this->runMailQueue ();
          
          break;
      }
      
      // Check if everything went fine
      if (!$Error)
        return;
      
      // Make sure we have a current mail
      if ($this->mailCurrent === null)
        return;
      
      // Fire the callback
      $this->smtpCallbackStatus ($this->lastCode, $this->lastLines, array ($this->mailCurrent [3], $this->mailCurrent [0], $this->mailCurrent [6], $this->mailCurrent [2], $this->mailCurrent [4]));
    }
    // }}}
    
    // {{{ startMail
    /**
     * Start the submission of an e-mail
     * 
     * @param string $Originator Originator of the mail
     * @param array $Params (optional) Additional parameters for this command (for extensions)
     * @param callback $Callback (optional) A callback to fire once the command was processed
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * @access public
     * @return void
     **/
    public function startMail ($Originator, $Params = null, $Callback = null, $Private = null) {
      // Make sure the originator is valid
      if ($Originator [0] != '<')
        $Originator = '<' . $Originator . '>';
      
      // Handle params
      if ($Params !== null) {
        $iParams = $Params;
        $Params = array ();
        
        if (is_array ($iParams))
          foreach ($iParams as $k=>$v)
            $Params [] = $k . '=' . $v;
      }
      
      // Issue the command
      return $this->smtpCommand ('MAIL FROM:' . $Originator, $Params, null, null, array ($this, 'smtpCallbackStatus'), array ($Callback, $Originator, $Private), self::SMTP_STATE_TRANSACTION, self::SMTP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ addReceiver
    /**
     * Append a receiver for an ongoing transaction
     * 
     * @param string $Receiver
     * @param array $Params (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function addReceiver ($Receiver, $Params = null, $Callback = null, $Private = null) {
      // Make sure the originator is valid
      if ($Receiver [0] != '<')
        $Receiver = '<' . $Receiver . '>';

      // Issue the command
      return $this->smtpCommand ('RCPT TO:' . $Receiver, $Params, null, null, array ($this, 'smtpCallbackStatus'), array ($Callback, $Receiver, $Private), null, self::SMTP_STATE_TRANSACTION);
    }
    // }}}
    
    // {{{ sendData
    /**
     * Submit Mail-Data
     * 
     * @param string $Mail
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function sendData ($Mail, $Callback = null, $Private = null) {
      // Issue the command
      return $this->smtpCommand ('DATA', null, array ($this, 'handleData'), $Mail, array ($this, 'smtpCallbackStatus'), array ($Callback, $Private), self::SMTP_STATE_CONNECTED, self::SMTP_STATE_TRANSACTION);
    }
    // }}}
    
    // {{{ handleData
    /**
     * Handle continuation-responses for DATA
     * 
     * @param int $Code
     * @param array $Lines
     * @param string $Private
     * 
     * @access private
     * @return string
     **/
    private function handleData ($Code, $Lines, $Private) {
      $p = 0;
      
      while (($p = strpos ($Private, "\r\n.\r\n", $p)) !== false)
        $Private = substr ($Private, 0, $p + 2) . '.' . substr ($Private, $p + 2);
      
      return $Private . (substr ($Private, -2, 2) == "\r\n" ? '' : "\r\n") . ".\r\n";
    }
    // }}}
    
    // {{{ reset
    /**
     * Abort any ongoing mail-transaction
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function reset ($Callback = null, $Private = null) {
      // Issue the command
      return $this->smtpCommand ('RSET', null, null, null, array ($this, 'smtpCallbackStatus'), array ($Callback, $Private), self::SMTP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ verify
    /**
     * Verfiy a username or mailbox
     * 
     * @param string $Mailbox
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function verify ($Mailbox, $Callback = null, $Private = null) {
      // Issue the command
      return $this->smtpCommand ('VRFY', array ($Mailbox), null, null, array ($this, 'smtpHandleVerify'), array ($Callback, $Mailbox, $Private));
    }
    // }}}
    
    // {{{ smtpHandleVerify
    /**
     * Internal Callback: Handle VRFY-Responses
     * 
     * @param int $Code
     * @param array $Lines
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function smtpHandleVerify ($Code, $Lines, $Private) {
      // Handle a successfull response
      if (($Code >=200) && ($Code < 300)) {
        $Mailbox = array_shift ($Lines);
        
        if (($p = strpos ($Mailbox, '<')) !== false) {
          $Fullname = rtrim (substr ($Mailbox, 0, $p));
          $Mailbox = substr ($Mailbox, $p + 1, strrpos ($Mailbox, '>') - $p - 1);
        } else
          $Fullname = null;
        
        // Rewrite Callback-Parameters
        $Private [4] = $Private [2];
        $Private [2] = $Mailbox;
        $Private [3] = $Fullname;
      
      // Handle failure
      } else {
        $Mailboxes = array ();
        
        foreach ($Lines as $Line)
          if (($p = strpos ($Line, '<')) !== false)
            $Mailboxes [] = substr ($Line, $p + 1, strrpos ($Line, '>') - $p - 1);
        
        // Rewrite Callback-Parameters
        $Private [4] = $Private [2];
        $Private [2] = $Mailboxes;
        $Private [3] = null;
      }
      
      // Forward the callback
      return $this->smtpCallbackStatus ($Code, $Lines, $Private);
    }
    // }}}
    
    // {{{ noop
    /**
     * Do nothing, but let the server know
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function noop ($Callback = null, $Private = null) {
      // Issue the command
      return $this->smtpCommand ('NOOP', null, null, null, array ($this, 'smtpCallbackStatus'), array ($Callback, $Private));
    }
    // }}}
    
    // {{{ startQueue
    /**
     * Start/Flush the remote queue for a domain at the servers site
     * 
     * @param string $Domaim
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function startQueue ($Domain, $Callback = null, $Private = null) {
      // Check if the server supports ETRN
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['ETRN']))
        return $this->smtpCallbackStatus (502, array (), array ($Callback, $Domain, $Private));
      
      // Issue the command
      return $this->smtpCommand ('ETRN', array ($Domain), null, null, array ($this, 'smtpCallbackStatus'), array ($Callback, $Domain, $Private));
    }   
    // }}}
    
    
    // {{{ smtpSetState
    /**
     * Change our protocol-state
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function smtpSetState ($State) {
      // Check if anything was changed
      if ($this->State == $State)
        return;
      
      // Set the state
      $oState = $this->State;
      $this->State = $State;
      
      // Fire a callback
      $this->___callback ('smtpStateChanged', $State, $oState);
    }
    // }}}
    
    // {{{ smtpCheckState
    /**
     * Check our internal state how it will be when all commands are executed
     * 
     * @access private
     * @return enum
     **/
    private function smtpCheckState () {
      // Start with our current state
      $State = $this->State;
      
      // Check the current command
      if (($this->Command !== null) && isset ($this->Command [6]) && ($this->Command [6] !== null))
        $State = $this->Command [6];
      
      // Check all commands on the pipe
      foreach ($this->Commands as $Command)
        if (isset ($Command [6]) && ($Command [6] !== null))
          $State = $Command [6];
      
      return $State;
    }
    // }}}
    
    // {{{ smtpCommand
    /**
     * Issue an SMTP-Command
     * 
     * @param string $Verb
     * @param array $Args (optional)
     * @param callback $ContinuationCallback (optional)
     * @param mixed $ContinuationPrivate (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * @param enum $setState (optional)
     * @param enum $requiredState (optional)
     * 
     * @access private
     * @return void
     **/
    private function smtpCommand ($Verb, $Args = null, $ContinuationCallback = null, $ContinuationPrivate = null, $Callback = null, $Private = null, $setState = null, $requiredState = null) {
      // Just push the command to the queue
      $this->Commands [] = array ($Verb, $Args, $ContinuationCallback, $ContinuationPrivate, $Callback, $Private, $setState, $requiredState);
      
      // Try to issue the next command
      $this->smtpExecuteCommand ();
    }
    // }}}
    
    // {{{ smtpExecuteCommand
    /**
     * Try to execute the next pending command
     * 
     * @access private
     * @return void
     **/
    private function smtpExecuteCommand () {
      // Check if there is a command active
      if ($this->Command !== null)
        return;
      
      // Check if there are pending commands
      if (count ($this->Commands) == 0)
        return;
      
      // Retrive the next command
      while (($c = count ($this->Commands)) > 0) {
        $this->Command = array_shift ($this->Commands);
        
        // Check the required state
        if (($this->Command [7] === null) || ($this->State == $this->Command [7]))
          break;
        
        // Fire a failed callback
        if (is_callable ($this->Command [4]))
          call_user_func ($this->Command [4], 503, array (), $this->Command [5]);
        
        if ($c > 1)
          continue;
        
        return ($this->Command = null);
      }
      
      // Write the command to the queue
      $Command = $this->Command [0];
      
      if (is_array ($this->Command [1]) && (count ($this->Command [1]) > 0))
        $Command .= ' ' . implode (' ', $this->Command [1]);
      
      $this->write ($Command . "\r\n");
    }
    // }}}
    
    // {{{ smtpCallbackSimple
    /**
     * Internal Callback: Just forward a given callback without any parameters
     * 
     * @param int $Code
     * @param array $Lines
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function smtpCallbackSimple ($Code, $Lines, $Private) {
      if (!is_array ($Private) || !isset ($Private [0]) || !is_callable ($Private [0]))
        return;
      
      $Callback = array_shift ($Private);
      
      if (!is_array ($Callback) || ($Callback [0] !== $this))
        array_unshift ($Private, $this);
      
      call_user_func_array ($Callback, $Private);
    }
    // }}}
    
    // {{{ smtpCallbackStatus
    /**
     * Interncal Callback: Forward a given callback with a status given
     * 
     * @param int $Code
     * @param array $Lines
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function smtpCallbackStatus ($Code, $Lines, $Private) {
      if (!is_array ($Private) || !isset ($Private [0]) || !is_callable ($Private [0]))
        return;
      
      $Callback = $Private [0];
      $Private [0] = ($Code < 400);
      
      if (!is_array ($Callback) || ($Callback [0] !== $this))
        array_unshift ($Private, $this);
      
      call_user_func_array ($Callback, $Private);
    }
    // }}}
    
    
    // {{{ smtpClientConnected
    /**
     * Internal Callback: Our Socket-Layer just went into connected-state
     * 
     * @access protected
     * @return void
     **/
    protected final function smtpClientConnected () {
      // Change our protocol-state
      $this->smtpSetState (self::SMTP_STATE_CONNECTING);
      
      // Wait for a greeting
      $this->connectingState = 0;
    }
    // }}}
    
    // {{{ receivedLine
    /**
     * A single SMTP-Line was received
     * 
     * @param string $Line
     *    
     * @access protected
     * @return void
     **/
    protected final function receivedLine ($Line) {
      // Retrive the code from the line
      $Code = intval (substr ($Line, 0, 3));
      
      if ($Multiline = (strlen ($Line) > 3)) {
        $Multiline = ($Line [3] == '-');
        $Line = substr ($Line, 4);
      } else
        $Line = '';
      
      // Handle enhanced response-codes
      if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['ENHANCEDSTATUSCODES']) && (($p = strpos ($Line, ' ', 5)) !== false)) {
        $eCode = substr ($Line, 0, $p);
        $Line = ltrim (substr ($Line, $p + 1));
      }
      
      // Push the response to local buffer
      if ($this->resppnseCode === null)
        $this->resppnseCode = $Code;
      elseif ($this->resppnseCode != $Code) {
        # TODO: Protocol-violation!
      }
      
      $this->responseLines [] = $Line;
      
      // Wait for further responses on multiline-responses
      if ($Multiline)
        return;
      
      // Retrive buffered lines
      $this->lastCode = $Code;
      $this->lastLines = $Lines = $this->responseLines;
      unset ($Line);
      
      // Clear local buffer
      $this->resppnseCode = null;
      $this->responseLines = array ();
      
      // Check for continuation
      if (($Code >= 300) && ($Code < 400)) {
        if (is_callable ($this->Command [2]))
          return $this->write (call_user_func ($this->Command [2], $Code, $Lines, $this->Command [3]));
        
        trigger_error ('Server wants continuation, but we dont have a callback for this', E_USER_ERROR);
      }
      
      // Check if we are connecting
      if ($this->State == self::SMTP_STATE_CONNECTING) {
        // Peek the current command
        if ($this->Command)
          $Command = $this->Command;
        else
          $Command = array (null, null, null, null, null, null);
        
        // Handle the server's greeting
        if ($this->connectingState == 0) {
          // Check if the server does not want us to connect
          // The RFC says only 554 here, we check them all though
          if ($Code >= 500) {
            $this->quit ();
            
            return $this->___callback ('smtpConnectionFailed');
          }
          
          // Do the client-initiation
          $this->connectingState = 1;
          $this->Command = null;
          
          return $this->smtpCommand ('EHLO', array ($this->getClientName ()), $Command [2], $Command [3], $Command [4], $Command [5]);
        }
        
        // Handle the response to our own Greeting
        if ($Code >= 500) {
          // Handle strange errors, were both EHLO and HELO failed
          if ($this->connectingState > 1) {
            $this->quit ();
            
            return $this->___callback ('smtpConnectionFailed');
          }
          
          // Try HELO-Fallback
          $this->connectingState = 2;
          $this->Command = null;
          
          return $this->smtpCommand ('HELO', array ($this->getClientName ()), $Command [2], $Command [3], $Command [4], $Command [5]);
        }
        
        // Retrive domainname of server
        $this->serverDomain = array_shift ($Lines);
        
        if (($p = strpos ($this->serverDomain, ' ')) !== false)
          $this->serverDomain = substr ($this->serverDomain, 0, $p);
        
        // Handle an EHLO-Response
        if ($this->connectingState == 1) {
          $this->serverFeatures = array ();
          
          foreach ($Lines as $Line) {
            $Info = explode (' ', $Line);
            $Keyword = strtoupper (array_shift ($Info));
            
            $this->serverFeatures [$Keyword] = $Info;
          }
        
        // Server does not support EHLO
        } else
          $this->serverFeatures = false;
        
        // Change our protocol-state
        $this->smtpSetState (self::SMTP_STATE_CONNECTED);
        
        // Fire the callback (only if not TLS was enabled)
        if (($this->Command === null) || ($this->Command [4] === null))
          $this->___callback ('smtpConnected');
      }
      
      // Handle normal replies
      if (($this->Command [6] !== null) && ($Code >= 200) && ($Code < 300))
        $this->smtpSetState ($this->Command [6]);
      
      if (is_callable ($this->Command [4]))
        call_user_func ($this->Command [4], $Code, $Lines, $this->Command [5]);
      
      // Remove the current command
      $this->Command = null;
      
      // Try to issue any pending commands
      $this->smtpExecuteCommand ();
    }
    // }}}
    
    
    // {{{ smtpStateChanged
    /**
     * Callback: SMTP-Protocol-State was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function smtpStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ smtpConnected
    /**
     * Callback: SMTP-Connection is ready for action
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnected () { }
    // }}}
    
    // {{{ smtpConnectionFailed
    /**
     * Callback: SMTP-Connection could not be established
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnectionFailed () { }
    // }}}
  }

?>