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
  
  /**
   * SMTP-Server
   * -----------
   * Simple SMTP-Server-Implementation (RFC 5321)
   * 
   * @class qcEvents_Socket_Server_SMTP
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server_SMTP extends qcEvents_Socket {
    // Server is ready for connections
    protected $Ready = true;
    
    // Allow Pipelining
    protected $Pipelining = false;
    
    // If a callback returns NULL, indicate a temporary reject
    protected $nullIsTempDefer = true;
    
    // Internal Receive-Buffer
    private $Buffer = '';
    
    // Are we currently receiving Mail-Payload?
    private $Commands = array ();
    
    private $Greeted = false;
    private $Originator = null;
    private $Receivers = array ();
    private $inData = false;
    
    // {{{ processMail
    /**
     * Callback: Process an entire mail
     * 
     * @param string $From
     * @param array $To
     * @param string $Body
     * 
     * @access protected
     * @return bool
     * @remark May return null, but should send its own responses then
     **/
    protected function processMail ($From, $To, $Body) { return true; }
    // }}}
    
    // {{{ reset
    /**
     * Forget about everything (but the command-stack)
     * 
     * @access public
     * @return void
     **/
    public function reset () {
      $this->Originator = null;
      $this->Receivers = array ();
      
      if ($this->inData && (($p = strpos ($this->Buffer, "\r\n.\r\n")) !== false))
        $this->Buffer = substr ($this->Buffer, $p + 5);
      
      $this->inData = false;
    }
    // }}}
    
    // {{{ setOriginator
    /**
     * Store the originator for the mail
     * 
     * @param string $O
     * 
     * @access public
     * @return bool
     **/
    public function setOriginator ($O) {
      // Check if there is already an originator set
      if (($this->Originator !== null) || (strlen ($O) == 0))
        return false;
      
      // Set the receiver
      $this->Originator = $O;
      
      return true;
    }
    // }}}
    
    // {{{ getOriginator
    /**
     * Retrive our current orginiator
     * 
     * @access public
     * @return string
     **/
    public function getOriginator () {
      return $this->Originator;
    }
    // }}}
    
    // {{{ addReceiver
    /**
     * Append a receiver for the mail
     * 
     * @param string $R
     * 
     * @access public
     * @return bool
     **/
    public function addReceiver ($R) {
      // Append receiver to our list
      $this->Receivers [] = $R;
      
      return true;
    }
    // }}}
    
    // {{{ hasOriginator
    /**
     * Check if a mail-originator was set
     * 
     * @access protected
     * @return bool
     **/
    protected function hasOriginator () {
      return ($this->Originator !== null);
    }
    // }}}
    
    // {{{ haveReceivers
    /**
     * Check if we have receivers assigned
     * 
     * @access protected
     * @return bool
     **/
    protected function haveReceivers () {
      return (count ($this->Receivers) > 0));
    }
    // }}}
    
    // {{{ stripAddress
    /**
     * Retrive a clean e-mail-adress
     * 
     * @param string $Address
     * 
     * @access public
     * @return string
     **/
    public static function stripAddress ($A) {
      // Tuncate any whitespaces
      $A = trim ($A);
      
      // Check for leading gt
      if ($A [0] == '<')
        $A = substr ($A, 1);
      
      // Parse the address
      $LocalPart = '';
      $Domain = '';
      $inQ = false;
      $onLocal = true;
      
      for ($i = 0; $i < strlen ($A); $i++)
        if ($A [$i] == '"') {
          // Quoted-string  = DQUOTE *QcontentSMTP DQUOTE
          // QcontentSMTP   = qtextSMTP / quoted-pairSMTP
          
          // Quotes are only allowed in local part
          if (!$onLocal)
            break;
          
          // Toggle in-quotes
          $inQ = !$inQ;
          
          // Append to output
          $LocalPart .= $A [$i];
        
        } elseif ($A [$i] == '@') {
          if (!$onLocal)
            return false;
          
          elseif (!$inQ)
            $onLocal = false;
          
          else
            $LocalPart .= $A [$i];
          
        } elseif ($A [$i] == '\\') {
          // quoted-pairSMTP = %d92 %d32-126
          if (!$onLocal)
            break;
          
          $N = $A [$i + 1];
          $C = ord ($N);
          
          if (($C < 32) || ($C > 126))
            return false;
          
          $LocalPart .= $A [$i++] . $N;
        } elseif ($onLocal) {
          // qtextSMTP = %d32-33 / %d35-91 / %d93-126
          if ($inQ) {
            $C = ord ($A [$i]);
            
            if (($C < 32) || (($C > 33) && ($C < 35)) || ($C == 92) || ($C > 126))
              return false;
          
          // Dot-string = Atom *("."  Atom)
          } else {
            if (($i == 0) && ($A [0] == '.'))
              return false;
            
            $C = ord ($A [$i]);
            
            if (($C < 33) || ($C == 34) || (($C > 39) && ($C < 42)) || ($C == 44) || (($C > 57) && ($C < 61)) || ($C == 62) || ($C == 64) || (($C > 90) && ($C < 94)) || ($C > 126))
              return false;
              
          }
          
          $LocalPart .= $A [$i];
          
        } else
          $Domain .= $A [$i];
      
      return $LocalPart . '@' . $Domain;
    }
    // }}}
    
    // {{{ receivedMail
    /**
     * The payload of a mail was received completly
     * 
     * @param string $Message
     * 
     * @access protected
     * @return void
     **/
    protected function receivedMail ($Message) {
      // Fire up a callback
      if ($rc = $this->processMail ($this->Originator, $this->Receivers, $Message))
        $this->respond (250, 'Ok, thank you for the postcard');
      elseif ($rc === false)
        $this->respond (554, 'Transaction failed');
      
      // Perform a reset
      $this->reset ();
    }
    // }}}
    
    // {{{ connected
    /**
     * Callback: Invoked whenever the connection is established
     * 
     * @access protected
     * @return void  
     **/
    protected function connected () {
      // Connection was established, send a hello
      if ($this->Ready)
        // Format: 220 {Domain} [text-string] <crlf>
        $this->respond (220, 'smtpd.quarxconnect.de ESMTP quarxConnect.de/20120125 ready.');
      else
        $this->respond (554, 'smtpd.quarxconnect.de ESMTP quarxConnect.de/20120125 not ready for connections, sorry.');
      
      # TODO: Setup ourself in any way?
    }
    // }}}
    
    // {{{ receive
    /**
     * Callback: Invoked whenever incoming data is received
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function receive ($Data) {
      // Append the data to our internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Check if we are receiving payload
      if ($this->inData) {
        // Check if payload was received completely
        if (($p = strpos ($this->Buffer, "\r\n.\r\n")) === false)
          return;
        
        // Retrive the message from buffer
        $Message = substr ($this->Buffer, 0, $p);
        $this->Buffer = substr ($this->Buffer, $p + 5);
        
        // Forward it to the handler
        $this->inData = false;
        $this->receivedMail ($Message);
        unset ($Message);
      }
      
      // Check if there are any commands to be processed
      $Loop = true;
      
      while ($Loop && (($p = strpos ($this->Buffer, "\n")) !== false)) {
        $Command = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
        // Store the command in our queue
        $Loop = $this->commandQueue ($Command);
        
        // Check wheter to run the queue
        $this->processQueue ();
      }
    }
    // }}}
    
    // {{{ commandQueue
    /**
     * Append a command to our internal queue
     * 
     * @param string $Command
     * 
     * @access protected
     * @return bool
     **/
    protected function commandQueue ($Command) {
      // Check for Pipelining
      if (((count ($this->Commands) > 0) || (strlen ($this->Buffer) > 1)) && !$this->Pipelining)
        return ($this->respond (520, 'Pipelining not allowed', true) && false);
      
      // Split command and parameters
      $Params = '';
      
      if ((($p = strpos ($Command, ':')) !== false) ||
          (($p = strpos ($Command, ' ')) !== false)) {
        $Params = ltrim (substr ($Command, $p + 1));
        $Command = substr ($Command, 0, $p);
      }
      
      // Queue the command
      $this->Commands [] = array (strtoupper ($Command), $Params);
      
      // Return wheter to continue queueing
      return ($Command != 'DATA');
    }
    // }}}
    
    // {{{ processQueue
    /**
     * Run all commands on queue
     * 
     * @access procted
     * @return void
     **/
    protected function processQueue () {
      // Check if there are commands waiting
      if (count ($this->Commands) == 0)
        return;
      
      // Don't run commands in data-mode
      if ($this->inData)
        return;
      
      // Peek the command
      list ($Command, $Params) = array_shift ($this->Commands);
      
      // Mask dummy-commands
      if ($Command == '503')
        $Command = '';
      
      // Force a 503 if client does not want to quit
      if (!$this->Ready && ($Command != 'QUIT'))
        $Command = '503';
      
      // Handle the command
      switch ($Command) {
        // Send a Hello to each other
        case 'HELO':
        case 'EHLO':
          $this->Greeted = true;
          
          # TODO: This seems do be a little bit static ;-)
          $Features = array ('smtpd.quarxconnect.de');
          
          // Append extended features in EHLO-Mode
          if ($Command == 'EHLO') {
            $Features [] = '8BITMIME';
            
            if ($this->allowPipeline)
              $Features [] = 'PIPELINING';
          }
          
          return $this->respond (250, $Features);
        
        // Do nothing
        case 'NOOP':
          return $this->respond (250, 'OK');
        
        // Say goodbye and close the connection
        case 'QUIT':
          return $this->respond (221, 'Goodbye', true);
        
        // Store Originator of mail
        case 'MAIL FROM': // Once
          if (!$this->Greeted)
            return $this->respond (503, 'Polite people say Hello first');
          
          // Clean up the originator
          if (!($Originator = self::stripAddress ($Params)))
            return $this->respond (501, 'Malformed originator');
          
          // Try to set the originator
          if (($rc = $this->setOriginator ($Originator)) === false)
            return $this->respond (550, 'Originator-address rejected');
          
          // The originator was rejected temporarily
          elseif (($rc === null) && $this->nullIsTempDefer)
            // This is a violation of RFC 5321, but non of 451, 452, 455 matches here...
            return $this->respond (450, 'Originator-address rejected for the moment');
          
          // Return a custom message
          elseif (is_array ($rc) && (count ($rc) == 2) && is_int ($rc [0]) && is_string ($rc [1]))
            return $this->respond ($rc [0], $rc [1]);
          
          return $this->respond (250, 'Ok');
          
        // Store Receipient of mail
        case 'RCPT TO':   // Many
          if (!$this->Greeted)
            return $this->respond (503, 'Polite people say Hello first');
          
          // Check if the originator was already set
          if ($this->hasOriginator ())
            return $this->respond (503, 'Commands out of order');
          
          // Clean up the receiver
          if (!($Receiver = self::stripAddress ($Params)))
            return $this->respond (501, 'Malformed receiver');
          
          // Try to add another receiver
          if (($rc = $this->addReceiver ($Params)) === false)
            return $this->respond (550, 'Receiver-address rejected');
          
          // The receiver was rejected temporarly
          elseif (($rc === null) && $this->nullIsTempDefer)
            return $this->respond (450, 'Receiver-address temporarily rejected');
          
          // Return a custom message
          elseif (is_array ($rc) && (count ($rc) == 2) && is_int ($rc [0]) && is_string ($rc [1]))
            return $this->respond ($rc [0], $rc [1]);
          
          return $this->respond (250, 'Ok');
          
        // Start receiving mail-payload
        case 'DATA':
          if (!$this->Greeted)
            return $this->respond (503, 'Polite people say Hello first');
          
          // Check if the originator was already set
          if ($this->hasOriginator ())
            return $this->respond (503, 'Bad sequence of commands');
          
          // Check if we are ready to receive Mail
          if (!$this->haveReceivers ())
            return $this->respond (554, 'No valid recipients');
          
          // Put ourself into DATA-Mode
          $this->inData = true;
          $rc = $this->respond (354, 'End data with <CR><LF>.<CR><LF>');
          
          // Force another round of command-handling
          if (strlen ($this->Buffer) > 0)
            $this->receive ('');
          
          return $rc;
          
        // Reset the current operation
        case 'RSET':
          $this->reset ();
          
          return $this->respond (250, 'Ok');
          
        // Unimplemented but mentioned in RFC 5321
        case 'VRFY':
        case 'EXPN':
        case 'HELP':
          return $this->respond (502, 'Command not implemented');
        
        case '503':
           return $this->respond (503, 'Bad sequence of commands');
        
        default:
          return $this->respond (500, 'Command unknown');
      }
    }
    // }}}
    
    // {{{ respond
    /**
     * Push a response to the client
     * 
     * @param int $Code
     * @param mixed $Message
     * @param bool $Close (optional)
     * 
     * @access protected
     * @return bool
     **/
    protected function respond ($Code, $Message, $Close = false) {
      if (!is_array ($Message))
        $Message = array ($Message);
      
      while (($c = count ($Message)) > 0)
        if (!($rc = $this->write ($Code . ($c == 1 ? ' ' : '-') . array_shift ($Message) . "\r\n")))
          break;
      
      if ($Close)
        $this->close ();
      
      return $rc;
    }
    
    // {{{ respondWithCode
    /**
     * Send a response-code to client with auto-generated message
     * 
     * @param int $Code
     * 
     * @access protected
     * @return bool
     **/
    protected function respondWithCode ($Code) {
      $Messages = array (
        250 => 'Requested mail action okay, completed',
        251 => 'User not local; will forward to another address',
        252 => 'Cannot VRFY user, but will accept message and attempt delivery',
        
        354 => 'Start mail input; end with <CRLF>.<CRLF>',
        
        450 => 'Requested mail action not taken: mailbox unavailable',
        451 => 'Requested action aborted: local error in processing',
        452 => 'Requested action not taken: insufficient system storage',
        455 => 'Server unable to accommodate parameters',
        
        500 => 'Syntax error, command unrecognized',
        501 => 'Syntax error in parameters or arguments',
        502 => 'Command not implemented',
        503 => 'Bad sequence of commands',
        504 => 'Command parameter not implemented',
        550 => 'Requested action not taken: mailbox unavailable',
        551 => 'User not local; please try another address',
        552 => 'Requested mail action aborted: exceeded storage allocation',
        553 => 'Requested action not taken: mailbox name not allowed',
        554 => 'Transaction failed',
        555 => 'MAIL FROM/RCPT TO parameters not recognized or not implemented',
      );
      
      if (isset ($Messages [$Code]))
        return $this->respond ($Code, $Messages [$Code]);
      
      return $this->respond ($Code, 'Sorry, can not help you');
    }
    // }}}
  }

?>