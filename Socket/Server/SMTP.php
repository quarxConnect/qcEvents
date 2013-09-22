<?PHP

  /**
   * qcEvents - SMTP-Server Implementation
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
  
  require_once ('qcEvents/Socket.php');
  
  class qcEvents_Socket_Server_SMTP_Command {
    /* The SMTP-Server we are running on */
    private $Server = null;
    
    /* The actual Command we are handling */
    private $Command = '';
    
    /* Parameter for this command */
    private $Parameter = null;
    
    /* Our response-code */
    private $Code = null;
    
    /* Message for the response */
    private $Message = null;
    
    /* Callback when the command was finished */
    private $Callback = null;
    
    /* Private data to pass to the callback */
    private $Private = null;
    
    // {{{ __construct
    /**
     * Create a new Command-Object
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Socket_Server_SMTP $Server, $Command, $Parameter = null) {
      $this->Server = $Server;
      $this->Command = $Command;
      $this->Parameter = $Parameter;
    }
    // }}}
    
    // {{{ __toString
    /**
     * Cast this object into a string
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return $this->Command;
    }
    // }}}
    
    // {{{ hasParameter
    /**
     * Check if this command has parameter assigned
     * 
     * @access public
     * @return bool
     **/
    public function hasParameter () {
      return (($this->Parameter !== null) && (strlen ($this->Parameter) > 0));
    }
    // }}}
    
    // {{{ getParameter
    /**
     * Retrive the parameter for this command
     * 
     * @access public
     * @return string
     **/
    public function getParameter () {
      return $this->Parameter;
    }
    // }}}
    
    // {{{ setResponse
    /**
     * Store the response for this command
     *
     * @param int $Code The response-code
     * @param mixed $Message (optional) Message for the response, may be multi-line
     * @param callable $Callback (optional) A callback to be called once the response was send
     * @param mixed $Private (optional) Some private data to be passed to the callback
     * 
     * @access public
     * @return void
     **/
    public function setResponse ($Code, $Message = null, callable $Callback = null, $Private = null) {
      $this->Code = $Code;
      $this->Message = $Message;
      $this->Callback = $Callback;
      $this->Private = $Private;
      
      $this->Server->smtpCommandReady ($this);
    }
    // }}}
    
    public function getCode () {
      return $this->Code;
    }
    
    public function getMessage () {
      return $this->Message;
    }
    
    public function getCallback () {
      return $this->Callback;
    }
    
    public function getCallbackPrivate () {
      return $this->Private;
    }
  }
  
  /**
   * SMTP-Server
   * -----------
   * Simple SMTP-Server-Implementation (RFC 5321)
   * 
   * @class qcEvents_Socket_Server_SMTP
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server_SMTP extends qcEvents_Socket {
    /* Protocol-States */
    const SMTP_STATE_DISCONNECTED = 0;
    const SMTP_STATE_CONNECTING = 1;
    const SMTP_STATE_CONNECTED = 2;
    const SMTP_STATE_TRANSACTION = 3;
    const SMTP_STATE_DISCONNECTING = 4;
    
    /* Our current protocol-state */
    private $smtpState = qcEvents_Socket_Server_SMTP::SMTP_STATE_DISCONNECTED;
    
    private $smtpReady = true;
    
    /* Do we allow pipelining */
    private $smtpPipelining = true;
    
    /* Internal buffer for incoming SMTP-Data */
    private $smtpBuffer = '';
    
    /* Current SMTP-Command being executed */
    private $smtpCommand = null;
    
    /* Registered SMTP-Commands */
    private $smtpCommands = array ();
    
    /* Originator of mail */
    private $mailOriginator = null;
    
    /* Receivers for mail */
    private $mailReceivers = array ();
    
    /* Body of current mail */
    private $mailData = array ();
    
    // {{{ __construct   
    /**
     * Create a new SMTP-Server
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent   
      call_user_func_array ('parent::__construct', func_get_args ());
      
      // Register SMTP-Commands
      $this->smtpAddCommand ('QUIT', array ($this, 'smtpQuit'), self::SMTP_STATE_CONNECTING);
      $this->smtpAddCommand ('HELO', array ($this, 'smtpHelo'), self::SMTP_STATE_CONNECTING);
      $this->smtpAddCommand ('EHLO', array ($this, 'smtpHelo'), self::SMTP_STATE_CONNECTING);
      $this->smtpAddCommand ('MAIL', array ($this, 'smtpMail'), self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('RCPT', array ($this, 'smtpRcpt'), self::SMTP_STATE_TRANSACTION);
      $this->smtpAddCommand ('DATA', array ($this, 'smtpData'), self::SMTP_STATE_TRANSACTION);
      $this->smtpAddCommand ('RSET', array ($this, 'smtpReset'), self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('HELP', array ($this, 'smtpUnimplemented'), self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('EXPN', array ($this, 'smtpUnimplemented'), self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('VRFY', array ($this, 'smtpUnimplemented'), self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('NOOP', array ($this, 'smtpNoop'), self::SMTP_STATE_CONNECTING);
      
      // Register hooks
      $this->addHook ('socketConnected', array ($this, 'smtpConnected'));
    }
    // }}}
    
    // {{{ smtpGreetingLines
    /**
     * Retrive all lines for the greeting
     * 
     * @access protected
     * @return mixed
     **/
    protected function smtpGreetingLines () {
      return array ('ESMTP qcEvents-Mail/0.1');
    }
    // }}}
    
    // {{{ smtpDomainname
    /**
     * Retrive the domainname of this smtp-server
     * 
     * @access protected
     * @return string
     **/
    protected function smtpDomainname () {
      return gethostname ();
    }
    // }}}
    
    // {{{ smtpConnected
    /**
     * Internal Callback: The underlying socket was connected
     * 
     * @access protected
     * @return void
     **/
    protected final function smtpConnected () {
      // Set new status
      $this->smtpSetState (self::SMTP_STATE_CONNECTING);
      
      // Check if the smtp-service is ready
      if (!$this->smtpReady)
        return $this->smtpSendResponse (554, '');
      
      // Retrive all lines for the greeting
      $Lines = $this->smtpGreetingLines ();
      
      // Prepend our domainname to the response
      if (count ($Lines) > 0)
        $Lines [0] = $this->smtpDomainname () . ' ' . $Lines [0];
      else
        $Lines [] = $this->smtpDomainname ();
      
      // Write out the response
      return $this->smtpSendResponse (220, $Lines);
    }
    // }}}
    
    // {{{ socketReceive
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * 
     * @access protected  
     * @return void
     **/
    protected function socketReceive ($Data) {
      // Append the received data to our internal buffer
      if (strlen ($Data) > 0) {
        $this->smtpBuffer .= $Data;
        unset ($Data);
      }
      
      // Check for commands that are ready
      while (($p = strpos ($this->smtpBuffer, "\n")) !== false) {
        // Retrive the command from the line
        $Command = rtrim (substr ($this->smtpBuffer, 0, $p));
        $this->smtpBuffer = substr ($this->smtpBuffer, $p + 1);
        
        // Check for an active command
        if ($this->smtpCommand !== null) {
          // Check if the command waits for additional data
          $Code = $this->smtpCommand->getCode ();
          
          if (($Code > 299) && ($Code < 400)) {
            call_user_func ($this->smtpCommand->getCallback (), $this->smtpCommand, $Command, $this->smtpCommand->getCallbackPrivate ());
            
            continue;
          }
          
          // Check for pipelining
          if (!$this->smtpPipelining) {
            $this->smtpSendResponse (520);
            $this->disconnect ();
            $this->smtpCommand = null;
            
            continue;
          }
        }
        
        // Check if there are parameters
        if (($p = strpos ($Command, ' ')) !== false) {
          $Parameter = ltrim (substr ($Command, $p + 1));
          $Command = strtoupper (substr ($Command, 0, $p));
        } else {
          $Parameter = null;
          $Command = strtoupper ($Command);
        }
        
        // Register the command
        $this->smtpCommand = $Handle = new qcEvents_Socket_Server_SMTP_Command ($this, $Command, $Parameter);
        
        // Check if we are accepting commands (always allow QUIT-Command to be executed)
        if (!$this->smtpReady && ($Command != 'QUIT')) {
          $Handle->setResponse (503);
          
          continue;
        }
        
        // Check if the command is known
        if (!isset ($this->smtpCommands [$Command])) {
          $Handle->setResponse (500);
          
          continue;
        }
        
        // Check our state
        if ($this->smtpState < $this->smtpCommands [$Command][1]) {
          $Handle->setResponse (503);
          
          continue;
        }
        
        // Run the command
        call_user_func ($this->smtpCommands [$Command][0], $Handle);
        
        break;
      }
    }
    // }}}
    
    // {{{ smtpAddCommand
    /**
     * Register a command-handler for SMTP
     * 
     * @param string $Command The Command-Verb
     * @param callable $Callback The callback to run for the command
     * @param enum $minState (optional) The minimal state we have to be in for this command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpAddCommand ($Command, callable $Callback, $minState = self::SMTP_STATE_DISCONNECTED) {
      $this->smtpCommands [$Command] = array ($Callback, $minState);
    }
    // }}}
    
    // {{{ smtpSetState
    /**
     * Set our protocol-state
     * 
     * @param enum $State The protocol-state to set
     * 
     * @access protected
     * @return void
     **/
    protected function smtpSetState ($State) {
      $this->smtpState = $State;
    }
    // }}}
    
    // {{{ smtpExplodeMailParams
    /** 
     * Split up mail-adress and parameters
     * 
     * @param string $Data
     * 
     * @access private
     * @return array
     **/
    private function smtpExplodeMailParams ($Data) {
      // Check where to start 
      $haveBrackets = ($Data [0] == '<');
      $p = ($haveBrackets ? 1 : 0);
      $l = strlen ($Data);
      
      // Retrive the localpart
      if ($Data [$p] == '"') {
        for ($i = $p + 1; $i < $l; $i++)
          if ($Data [$i] == '\\') {
            $c = ord ($Data [++$i]);
            
            if (($c < 32) || ($c > 126))
              break;
          } elseif ($Data [$i] != '"') {
            $c = ord ($Data [$i]);
            
            if (($c < 32) || ($c == 34) || ($c == 92) || ($c > 126))
              break;
          } else
            break;
          
        $Localpart = substr ($Data, $p, $i - $p + 2);
        $p = $i + 2;
      } else {
        for ($i = $p; $i < $l; $i++) {
          $C = ord ($Data [$i]);
          
          if (($C < 33) || ($C == 34) || (($C > 39) && ($C < 42)) || ($C == 44) || (($C > 57) && ($C < 61)) ||
              ($C == 62) || ($C == 64) || (($C > 90) && ($C < 94)) || ($C > 126))
            break;
        }
        
        $Localpart = substr ($Data, $p, $i - $p);
        $p = $i;
      }
      
      if ($Data [$p++] != '@')
        return false;
      
      // Retrive the domain
      if (($e = strpos ($Data, ($haveBrackets ? '>' : ' '), $p)) === false)
        return false;
      
      $Domain = substr ($Data, $p, $e - $p);
      $p = $e + 1;
      
      $Mail = substr ($Data, 0, $p);
      
      // Check for additional parameter
      $Parameter = ltrim (substr ($Data, $p));
      $Parameters = array ();
      
      if (strlen ($Parameter) > 0)
        foreach (explode (' ', $Parameter) as $Value)
          if (($p = strpos ($Value, '=')) !== false)
            $Parameters [substr ($Value, 0, $p)] = substr ($Value, $p + 1);
          else
            $Parameters [$Value] = true;
      
      return array ($Mail, $Parameters);
    }
    // }}}
    
    // {{{ smtpHelo
    /**
     * Internal Callback: EHLO/HELO-Command was received
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpHelo (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Check if there is a parameter given
      if (!$Command->hasParameter ())
        return $Command->setResponse (501);
      
      // Change our current state
      $this->smtpSetState (self::SMTP_STATE_CONNECTED);
      
      // Write out features
      $Features = array ($this->smtpDomainname ());
      
      // Check for extended HELO
      if ($Command == 'EHLO') {
        $Features [] = '8BITMIME';
        
        if ($this->smtpPipelining)
          $Features [] = 'PIPELINING';
      }
      
      $Command->setResponse (250, $Features);
    }
    // }}}
    
    // {{{ smtpNoop
    /**
     * Internal callback: Do nothing but a 250-Response
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpNoop (qcEvents_Socket_Server_SMTP_Command $Command) {
      $Command->setResponse (250);
    }
    // }}}
    
    // {{{ smtpUnimplemented
    /**
     * Internal callback: Do nothing but return a not-implemented error
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpUnimplemented (qcEvents_Socket_Server_SMTP_Command $Command) {
      $Command->setResponse (502);
    }
    // }}}
    
    // {{{ smtpQuit
    /**
     * Internal Callback: QUIT-Command was issued
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpQuit (qcEvents_Socket_Server_SMTP_Command $Command) {
      $Command->setResponse (221, $this->smtpDomainname () . ' Service closing transmission channel', array ($this, 'disconnect'));
    }
    // }}}
    
    // {{{ smtpMail
    /**
     * Internal Callback: MAIL-Command was received
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpMail (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Check if we are in the right protocol-state
      if ($this->smtpState !== self::SMTP_STATE_CONNECTED)
        return $Command->setResponse (503);
      
      // Check if there is a parameter given
      if (!$Command->hasParameter ())
        return $Command->setResponse (501);
      
      // Retrive the Originator
      $Originator = $Command->getParameter ();
      
      // Check if this is realy a MAIL FROM:
      if (strtoupper (substr ($Originator, 0, 5)) != 'FROM:')
        return $Command->setResponse (501);
      
      $Originator = ltrim (substr ($Originator, 5));
      
      // Parse the parameter
      if (!is_array ($Parameters = $this->smtpExplodeMailParams ($Originator)))
        return $Command->setResponse (501);
      
      // Fire the callback to validate and set
      $this->___callback ('smtpSetOriginator', $Parameters [0], $Parameters [1], array ($this, 'smtpMailResult'), $Command);
    }
    // }}}
    
    // {{{ smtpMailResult
    /**
     * Callback: The originator of a mail was accepted or rejected
     * 
     * @param bool $Result
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access public
     * @return void
     **/ 
    public function smtpMailResult ($Result, qcEvents_Socket_Server_SMTP_Command $Command) {
      // Convert a boolean-Result into an smtp-code
      if ($Result === false)
        $Result = 550;
      elseif ($Result === true)
        $Result = 250;
      
      // Check if the command was successull (and switch into transaction-state)
      if ($Result < 300)
        $this->smtpSetState (self::SMTP_STATE_TRANSACTION);
        
      // Finish the command
      $Command->setResponse ($Result);
    }
    // }}}
    
    // {{{ smtpRcpt
    /**
     * Internal Callback: Receive a receiver for the current transaction
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpRcpt (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Check if there is a parameter given
      if (!$Command->hasParameter ())
        return $Command->setResponse (501);
      
      // Retrive the Receiver
      $Receiver = $Command->getParameter ();
      
      // Check if this is realy a RCPT TO:
      if (strtoupper (substr ($Receiver, 0, 3)) != 'TO:')
        return $Command->setResponse (501);
      
      $Receiver = ltrim (substr ($Receiver, 3));
      
      // Parse the parameter
      if (!is_array ($Parameters = $this->smtpExplodeMailParams ($Receiver)))
        return $Command->setResponse (501);
      
      // Fire the callback to validate and set
      $this->___callback ('smtpAddReceiver', $Parameters [0], $Parameters [1], array ($this, 'smtpRcptResult'), $Command);
    }
    // }}}
    
    // {{{ smtpRcptResult
    /**
     * Callback: A receiver for the current transaction was accepted or rejected
     * 
     * @param bool $Result
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access public
     * @return void
     **/
    public function smtpRcptResult ($Result, qcEvents_Socket_Server_SMTP_Command $Command) {
      // Convert a boolean-Result into an smtp-code
      if ($Result === false)
        $Result = 550;
      elseif ($Result === true)
        $Result = 250;
      
      // Finish the command
      $Command->setResponse ($Result);
    }
    // }}}
    
    // {{{ smtpData
    /**
     * Internal Callback: DATA-Command was received
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpData (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Check if there is a parameter given
      if ($Command->hasParameter ())
        return $Command->setResponse (504);
      
      // Accept incoming mail-data
      $Command->setResponse (354, null, array ($this, 'smtpDataIncoming'));
    }
    // }}}
    
    // {{{ smtpDataIncoming
    /**
     * Internal Callback: Handle incoming message data
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * @param string $Data
     * 
     * @access public
     * @return void
     **/
    public function smtpDataIncoming (qcEvents_Socket_Server_SMTP_Command $Command, $Data) {
      // Check for end of incoming data
      if ($Data == '.')
        return $this->___callback ('smtpMessageReceived', implode ("\r\n", $this->mailData), array ($this, 'smtpDataResult'), $Command);
      
      // Check for a transparent '.'
      if ($Data == '..')
        $Data = '.';
      
      // Append to internal buffer
      $this->mailData [] = $Data;
    }
    // }}}
    
    // {{{ smtpDataResult
    /**
     * Internal callback: Write out status for DATA-Command
     * 
     * @param bool $Result
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access public
     * @return void
     **/
    public function smtpDataResult ($Result, qcEvents_Socket_Server_SMTP_Command $Command) {
      // Convert a boolean-result into a numeric one
      if ($Result === true)
        $Result = 250;
      elseif ($Result === false)
        $Result = 554;
      
      $Command->setResponse ($Result);
    }
    // }}}
    
    // {{{ smtpReset
    /**
     * Internal Callback: Reset an ongoing transaction
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpReset (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Remove any data transmitted for an transaction
      $this->mailOriginator = null;
      $this->mailReceivers = array ();
      
      // Set our internal state
      $this->smtpSetState (self::SMTP_STATE_CONNECTED);
      
      // Complete the command
      $Command->setResponse (250);
    }
    // }}}
    
    // {{{ smtpCommandReady
    /**
     * A command was finished
     * 
     * @param qcEvents_Socket_Server_SMTP_Command $Command The received command
     * 
     * @access public
     * @return void
     **/
    public function smtpCommandReady (qcEvents_Socket_Server_SMTP_Command $Command) {
      // Check if this command is the first running command
      if ($Command !== $this->smtpCommand)
        return;
      
      // Write out its response
      $this->smtpSendResponse ($Code = $this->smtpCommand->getCode (), $this->smtpCommand->getMessage ());
      
      // Check if this is an intermediate response
      if (($Code > 299) && ($Code < 400))
        return;
      
      // Fire a callback
      if (($Callback = $this->smtpCommand->getCallback ()) !== null)
        call_user_func ($Callback, $this->smtpCommand->getCallbackPrivate ());
      
      // Release the command
      $this->smtpCommand = null;
      
      // Proceed to next command
      if (strlen ($this->smtpBuffer) > 0)
        $this->socketReceive ('');
    }
    // }}}
    
    // {{{ smtpSendResponse
    /**
     * Write out an SMTP-Response
     * 
     * @access private
     * @return void
     **/
    private function smtpSendResponse ($Code, $Message = null) {
      static $Codes = array (
        221 => 'Service closing transmission channel',
        250 => 'Ok',
        354 => 'Start mail input; end with <CRLF>.<CRLF>',
        500 => 'Syntax error, command unrecognized',
        501 => 'Syntax error in parameters or arguments',
        502 => 'Command not implemented',
        503 => 'bad sequence of commands',
        504 => 'Command parameter not implemented',
        
        520 => 'Pipelining not allowed', # This is not on the RFC
        554 => 'Transaction failed',
      );
      
      // Check if to return a default response-message
      if (($Message === null) && (isset ($Codes [$Code])))
        $Message = $Codes [$Code];
      
      // Make sure the message is an array
      if (!is_array ($Message))
        $Message = array ($Message);
      
      // Write out all message-lines
      while (($c = count ($Message)) > 0) {
        $Text = array_shift ($Message);
        
        $this->mwrite ($Code, ($c > 1 ? '-' : ' '), $Text, "\r\n");
      }
    }
    // }}}
    
    
    // {{{ smtpSetOriginator
    /**
     * Callback: Try to store the originator of a mail-transaction
     * 
     * @param string $Originator
     * @param array $Parameters
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The given callback is expected to be fired in the form of
     * 
     *   function (bool $Status, mixed $Private) { }
     * 
     * 
     * @access protected
     * @return void
     **/
    protected function smtpSetOriginator ($Originator, $Parameters, callable $Callback, $Private = null) {
      // Simply store the originator
      $this->mailOriginator = $Originator;
      
      // Fire the callback
      call_user_func ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ smtpAddReceiver
    /**
     * Callback: Try to add a recevier to the current mail-transaction
     * 
     * @param string $Receiver
     * @param array $Parameters
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The given callback is expected to be fired in the form of
     * 
     *   function (bool $Status, mixed $Private) { }
     * 
     * 
     * @access protected
     * @return void
     **/
    protected function smtpAddReceiver ($Receiver, $Parameters, callable $Callback, $Private = null) {
      // Append to receivers
      $this->mailReceivers [] = $Receiver;
      
      // Fire the callback
      call_user_func ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ smtpMessageReceived
    /**
     * Callback: Message-Data was completely received
     * 
     * @param string $Body The actual message-data
     * @param callable $Callback A callback to run after this one (with a status)
     * @param mixed $Private (optional) Some private data to pass to the callback
     * 
     * The given callback is expected to be fired in the form of
     * 
     *   function (bool $Status, mixed $Private) { }
     * 
     * 
     * @access protected
     * @return void
     **/
    protected function smtpMessageReceived ($Body, callable $Callback, $Private = null) {
      // Fire the callback
      call_user_func ($Callback, true, $Private);
    }
    // }}}
  }

?>