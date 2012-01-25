<?PHP

  require_once ('qcEvents/Socket.php');
  
  /**
   * SMTP-Server
   * -----------
   * Simple SMTP-Server-Implementation (RFC 5321)
   * 
   * @class qcEvents_Socket_Server_SMTP
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @license http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons Attribution-Share Alike 3.0 Germany
   * @remark THE LICENSE OF THIS CODE IS GOING TO BE CHANGED IN NEAR FUTURE
   **/
  class qcEvents_Socket_Server_SMTP extends qcEvents_Socket {
    // Allow Pipelining
    private $Pipelining = false;
    
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
      if (($this->Originator !== null) || (strlen ($O) == 0))
        return false;
      
      # TODO: Validate the new originator
      
      $this->Originator = $O;
      
      return true;
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
      # TODO: Validate the recevier
      
      $this->Receivers [] = $R;
      
      return true;
    }
    // }}}
    
    // {{{ mayReceive
    /**
     * Check if we are ready to receive mail-payload
     * 
     * @access protected
     * @return bool
     **/
    protected function mayReceive () {
      return (($this->Originator !== null) && (count ($this->Receivers) > 0));
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
      
      // Go out of DATA-Mode
      $this->inData = false;
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
      // Format: 220 {Domain} [text-string] <crlf>
      $this->respond (220, 'smtpd.quarxconnect.de ESMTP quarxConnect.de/20120125 ready.');
      
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
            return $this->respond (550, 'Polite people say Hello first');
          
          if (!$this->setOriginator ($Params))
            return $this->respind (550, 'Originator-address rejected');
          
          return $this->respond (250, 'Ok');
          
        // Store Receipient of mail
        case 'RCPT TO':   // Many
          if (!$this->Greeted)
            return $this->respond (550, 'Polite people say Hello first');
          
          if (!$this->addReceiver ($Params))
            return $this->respond (550, 'Receiver-address rejected');
          
          return $this->respond (250, 'Ok');
          
        // Start receiving mail-payload
        case 'DATA':
          if (!$this->Greeted)
            return $this->respond (550, 'Polite people say Hello first');
          
          // Check if we are ready to receive Mail
          if (!$this->mayReceive ())
            return $this->respond (550, 'Not ready to receive mail');
          
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
        
        default:
          return $this->respond (500, 'Command unknown');
      }
    }
    // }}}
    
    private function respond ($Code, $Message, $Close = false) {
      if (!is_array ($Message))
        $Message = array ($Message);
      
      while (($c = count ($Message)) > 0)
        if (!($rc = $this->write ($Code . ($c == 1 ? ' ' : '-') . array_shift ($Message) . "\r\n")))
          break;
      
      if ($Close)
        $this->close ();
      
      return $rc;
    }
  }

?>