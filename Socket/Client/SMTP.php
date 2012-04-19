<?PHP

  /**
   * qcEvents - Asyncronous SMTP Client
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
   * SMTP-Client
   * ----------- 
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qcEvents_Socket_Client_SMTP
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  class qcEvents_Socket_Client_SMTP extends qcEvents_Socket {
    const STATUS_CONNECTED = 0;
    const STATUS_HELO = 1;
    const STATUS_ORIGINATOR = 2;
    const STATUS_RECEIVERS = 3;
    const STATUS_DATA = 4;
    const STATUS_DATA_SENT = 5;
    const STATUS_QUIT = 6;
    
    // Our current status
    private $Status = qcEvents_Socket_Client_SMTP::STATUS_CONNECTED;
    
    // Internal buffer
    private $Buffer = '';
    
    // Buffer for SMTP-Responses
    private $rMessages = array ();
    
    // Preset information
    private $Originator = null;
    private $Receivers = null;
    private $Payload = null;
    
    // Asyncronous mail-transfer-buffer
    private $cOriginator = null;
    private $cReceiver = null;
    private $cReceivers = null;
    private $cAcceptedReceivers = array ();
    private $cPayload = null;
    private $cWait = false;
    
    protected function acceptedOriginator ($Originator, $Code) { }
    protected function deferredOriginator ($Originator, $Code) { }
    protected function acceptedReceiver ($Receiver, $Code) { }
    protected function deferredReceiver ($Receiver, $Code) { }
    protected function acceptedMail () { }
    protected function deferredMail () { }
    
    // {{{ __construct
    /**
     * Create a new SMTP-Connection
     * 
     * @param string $IP (optional)
     * @param int $Port (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($IP = null, $Port = 25) {
      // Check wheter to generate a connection
      if (($IP === null) || ($Port === null))
        return;

      // Try to connect
      $this->connect (self::MODE_TCP, $IP, $Port);
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive the hostname of this client
     * 
     * @access public
     * @return string
     * @remark This function is NOT asyncronous and should always respond with a valid hostname
     **/
    public function getHostname () {
      # TODO: Determine the hostname
      
      return 'smtpc.quarxconnect.de';
    }
    // }}}
    
    // {{{ setOriginator
    /**
     * Set the originator of this e-mail
     * 
     * @param string $Originator
     * 
     * @access public
     * @return void
     **/
    public function setOriginator ($Originator) {
      // Set the originator
      $this->Originator = $Originator;
      
      // Check if we should send it
      $this->sendOriginator ();
    }
    // }}}
    
    // {{{ getOriginator
    /**
     * Retrive the current originator
     * 
     * @access public
     * @return string
     **/
    public function getOriginator () {
      return $this->Originator;
    }
    // }}}
    
    // {{{ sendOriginator
    /**
     * Submit the originator of this mail
     * 
     * @access private
     * @return bool
     **/
    private function sendOriginator () {
      // Check if we might submit the originator
      if ($this->Status != self::STATUS_HELO)
        return false;
      
      // Check if we have an originator ready
      if (($this->cOriginator === null) && (($this->cOriginator = $this->getOriginator ()) === null))
        return false;
      
      // Submit the originator and change status
      return $this->sendCommand ('MAIL FROM:<' . $this->cOriginator . '>', self::STATUS_ORIGINATOR);
    }
    // }}}
    
    // {{{ setReceivers
    /**
     * Store all receivers of this mail
     * 
     * @param array $Receivers
     * 
     * @access public
     * @return void
     **/
    public function setReceivers ($Receivers) {
      // Store the receivers
      $this->Receivers = $Receivers;
      
      // Try to submit the receivers
      $this->sendReceivers ();
    }
    // }}}
    
    // {{{ getReceivers
    /**
     * Get the full set of receivers
     * 
     * @access public
     * @return array
     * @remark If you want to use an incomplete set of receivers, overload $this->getNextReceiver()
     **/
    public function getReceivers () {
      return $this->Receivers;
    }
    // }}}
    
    // {{{ getNextReceiver
    /**
     * Retrive the receiver to send next
     * 
     * @access protected
     * @return string Returns a string is a receiver is available, FALSE if an error happended, TRUE if there are no more receivers left and NULL if there is no receiver available ATM
     **/
    protected function getNextReceiver () {
      // Check if we have receivers available
      if (($this->cReceivers === null) && !is_array ($this->cReceivers = $this->getReceivers ()))
        return $this->cReceivers = null;
      
      // Check for strange errors
      if (!is_array ($this->cReceivers))
        return false;
            
      // Check if there are any receivers left
      if (count ($this->cReceivers) == 0)
        return true;
      
      // Return the next receiver
      return array_shift ($this->cReceivers);
    }
    // }}}
    
    // {{{ sendReceivers
    /**
     * Try to submit receivers
     * 
     * @access private
     * @return void
     **/
    private function sendReceivers () {
      // Check our status
      if (($this->Status != self::STATUS_ORIGINATOR) && ($this->Status != self::STATUS_RECEIVERS))
        return false;
      
      // Retrive the next receiver
      if (($rc = $this->getNextReceiver ()) === false)
        return false;
      
      // Receiver isn't available at the moment
      elseif ($rc === null)
        return null;
      
      // Traversal is complete
      elseif ($rc === true)
        return true;
        
      // Send the next receiver
      $this->sendCommand ('RCPT TO:<' . ($this->cReceiver = $rc) . '>', self::STATUS_RECEIVERS);
    }
    // }}}
    
    // {{{ setPayload
    /**
     * Store the payload (mail) for the current session
     * 
     * @access public
     * @return void
     **/
    public function setPayload ($Payload) {
      // Store the payload
      $this->Payload = $Payload;
      
      // Try to submit the payload
      $this->submitMail ();
    }
    // }}}
    
    // {{{ getPayload
    /**
     * Retrive the payload (mail) of this session
     * 
     * @access public
     * @return string
     **/
    public function getPayload () {
      return $this->Payload;
    }
    // }}}
    
    // {{{ submitMail
    /**
     * Try to submit the mail over our wire
     * 
     * @access private
     * @return bool
     **/
    private function submitMail () {
      // Check if we have data for submussion available
      if (($this->cPayload === null) && (strlen ($this->cPayload = $this->getPayload ()) == 0))
        return null;
      
      // Try to enter DATA-Mode
      if ($this->Status === self::STATUS_RECEIVERS)
        return $this->sendCommand ('DATA', self::STATUS_DATA);
      
      // Submit the mail
      if ($this->Status == self::STATUS_DATA) {
        if ($this->cWait)
          return false;
        
        if (!$this->mwrite ($this->cPayload, "\r\n.\r\n"))
          return false;
        
        $this->Status = self::STATUS_DATA_SENT;
        
        return true;
      }
      
      return false;
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
      $this->Status = self::STATUS_CONNECTED;
      
      # TODO: Add timeout for greeting
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
      // Push the received data to our buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Check for full responses
      while (($p = strpos ($this->Buffer, "\n")) !== false) {
        // Peek the line from buffer
        $Line = rtrim (substr ($this->Buffer, 0, $p));
        $this->Buffer = substr ($this->Buffer, $p + 1);
        
        // Store the repsonse
        $this->rMessages [] = substr ($Line, 4);
        
        // Check if the response is complete
        if ($Line [3] == ' ') {
          $Code = intval (substr ($Line, 0, 3));
          
          $this->handleResponse ($Code, $this->rMessages);
          $this->rMessages = array ();
        }
      }
    }
    // }}}
    
    // {{{ handleResponse
    /**
     * Handle responses according to our status
     * 
     * @param int $Code
     * @param array $Messages
     * 
     * @access private
     * @return void
     **/
    private function handleResponse ($Code, $Messages) {
      // Leave Waiting-Status if a response was received
      $this->cWait = false;
      
      // Handle responses according to our status
      switch ($this->Status) {
        // Server sent initial greeting
        case self::STATUS_CONNECTED:
          // Server is ready to accept mails from us
          if ($Code < 400)
            $this->sendCommand ('EHLO ' . $this->getHostname (), self::STATUS_HELO);
          
          // Server does not want any e-mail from us at the moment
          else
            $this->sendCommand ('QUIT', self::STATUS_QUIT);
          
          break;
        
        // Received response to our EHLO
        case self::STATUS_HELO:
          // Server sent a reply
          if ($Code < 400) {
            # TODO: Handle the response? (Features, etc.)
            
            // Try to submit the originator
            $this->sendOriginator ();
          
          // Check if we are talking to an old-style mail-server
          } elseif ($Code == 502)
            $this->sendCommand ('HELO ' . $this->getHostname ());
          
          // Something strange happened
          else
            $this->sendCommand ('QUIT', self::STATUS_QUIT);
          
          break;
           
        // Originator was handled (MAIL FROM: Response)
        case self::STATUS_ORIGINATOR:
          // Server sent a reply
          if ($Code < 400) {
            // Fire up the callback
            $this->___callback ('acceptedOriginator', $this->cOriginator, $Code);
            
            // Try to submit the receivers
            $this->sendReceivers ();
            
          // Originator was not accepted
          } else {
            // Reset our status
            $this->Status = self::STATUS_HELO;
            
            // Fire up the callback and maybe cancel the mail-submission
            if (!$this->___callback ('deferredOriginator', $this->cOriginator, $Code))
              $this->cancelMail ();
          }
          
          break;
        
        // Response for a receiver
        case self::STATUS_RECEIVERS:
          // Check if the last receiver was accepted
          if ($Code < 400) {
            $this->cAcceptedReceivers [] = $this->cReceiver;
            $this->___callback ('acceptedReceiver', $Receiver, $Code);
          
          // Or handle a it via callback
          } elseif ($this->___callback ('deferredReceiver', $Receiver, $Code) === false)
            return $this->cancelMail ();
          
          // Submit the next receiver
          if (($rc = $this->sendReceivers ()) === false)
            return $this->cancelMail ();
          elseif ($rc !== true)
            return;
          
          $this->cReceivers = null;
          
          // Check if at least one receiver was accepted
          if (count ($this->cAcceptedReceivers) == 0)
            return $this->cancelMail ();
          
          // Prepare to submit the mail
          $this->submitMail ();
          
          break;
        
        // Response for submission-request (DATA Response)
        case self::STATUS_DATA:
          // Check if server is ready to receive data
          if ($Code != 354)
            return $this->cancelMail ();
          
          // Try to submit the mail
          $this->submitMail ();
          
          break;
        
        // Data was sent, server sent response
        case self::STATUS_DATA_SENT:
          if ($Code < 400)
            $this->finishMail ();
          
          // Something went wrong
          else
            $this->cancelMail ();
          
          break;
        
        // Connection-close requested
        case self::STATUS_QUIT:
          $this->close ();
      }
    }
    // }}}
    
    // {{{ sendCommand
    /**
     * Send a command over the wire
     * 
     * @param string $Cmdline
     * @param enum $Status (optional)
     * 
     * @access private
     * @return bool
     **/
    private function sendCommand ($Cmdline, $Status = null) {
      // Don't send a command whenever we are waiting for a response
      if ($this->cWait)
        return false;
      
      // Submit the command
      if (!$this->mwrite ($Cmdline, "\r\n"))
        return false;
      
      // Set our status
      if ($Status !== null)
        $this->Status = $Status;
      
      // Put ourself into waiting mode
      return $this->cWait = true;
    }
    // }}}
    
    // {{{ finishMail
    /**
     * Finish the current mail-process
     * 
     * @access private
     * @return void
     **/
    private function finishMail () {
      // Fire up the callback
      $this->___callback ('acceptedMail');
      
      // Close the connection
      # TODO: RSET here instead of quit?
      $this->sendCommand ('QUIT', self::STATUS_QUIT);
    }
    // }}}
    
    // {{{ cancelMail
    /**
     * Cancel the submission of the current request
     * 
     * @access private
     * @return void
     **/
    private function cancelMail () {
      // Fire up the callback
      $this->___callback ('deferredMail');
      
      // Close the connection
      # TODO: RSET here instead of quit?
      $this->sendCommand ('QUIT', self::STATUS_QUIT);
    }
    // }}}
    
    // {{{ quit
    /**
     * Quit and close the connection
     * 
     * @access public
     * @return void
     **/
    public function quit () {
      $this->sendCommand ('QUIT', self::STATUS_QUIT);
    }
    // }}}
    
    // {{{ closed
    /**
     * Internal Callback: Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function closed () {
      $this->___callback ('disconnected', $this->Status == self::STATUS_QUIT);
      $this->unbind ();
    }
    // }}}
    
    // {{{ disconnected
    /**
     * Callback: Our client-connection was closed
     * 
     * @param bool $Expected
     * 
     * @access protected
     * @return void
     **/
    protected function disconnected ($Expected) { }
    // }}}
    
    // {{{ acceptedMail
    /**
     * Callback: The Mail was accepted by the server
     * 
     * @access protected
     * @return void
     **/
    protected function acceptedMail () { }
    // }}}
    
    // {{{ deferredMail
    /**
     * Callback: The Mail was rejected by the server
     * 
     * @access protected
     * @return void
     **/
    protected function deferredMail () { }
    // }}}
  }

?>