<?PHP

  /**
   * qcEvents - SMTP-Relay-Server Implementation
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
  
  require_once ('qcEvents/Socket/Server/SMTP.php');
  require_once ('qcEvents/Socket/Server/SMTP/Relay/Client.php');
  
  /**
   * SMTP-Relay
   * ----------
   * Generic SMTP-Relay Server that utilizes our SMTP-Server-Implementation
   * 
   * @class qcEvents_Socket_Server_SMTP_Relay
   * @extends qcEvents_Socket_Server_SMTP
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server_SMTP_Relay extends qcEvents_Socket_Server_SMTP {
    // SMTP-Clients for forwarding e-mails
    private $Clients = array ();
    
    // A queue for all receivers for preserve order
    private $rQueue = array ();
    private $haveReceivers = false;
    private $hadErrors = false;
    private $allErrors = true;
    
    // {{{ deferredOriginator
    /**
     * Callback: One of our clients rejected the originator
     * 
     * @param object $Client
     * @param string $Originator
     * @param int $Code
     * 
     * @access public
     * @return void
     **/
    public function deferredOriginator ($Client, $Originator, $Code) {
      # TODO
    }
    // }}}
    
    // {{{ forwardedReceiver
    /**
     * Callback: A receiver was accepted or rejected by our relay-destination
     * 
     * @param object $Client
     * @param string $Receiver
     * @parem int $Code
     * 
     * @access protected
     * @return void
     **/
    public function forwardedReceiver ($Client, $Receiver, $Code) {
      $this->rQueue [$Receiver] = $Code;
      
      foreach ($this->rQueue as $K=>$C) {
        if ($C === null)
          return;
        
        if ($C < 300)
          $this->haveReceivers = true;
        
        $this->respondWithCode ($C);
        
        unset ($this->rQueue [$K]);
      }
    }
    // }}}
    
    // {{{ acceptedMail
    /**
     * Internal Callback: The Mail was accepted by the server
     *    
     * @access public
     * @return void
     **/
    public function acceptedMail ($Client) {
      // Remove the all-error bit
      $this->allErrors = false;
      
      // Invoke local handler
      $this->___callback ('clientReady', $Client, true);
    }
    // }}}
    
    // {{{ deferredMail
    /**
     * Internal Callback: The Mail was rejected by the server
     *    
     * @access public
     * @return void
     **/
    public function deferredMail ($Client) {
      // Indicate that we had an error
      $this->hadErrors = true;
      
      // Invoke local handler
      $this->___callback ('clientReady', $Client, false);
    }
    // }}}
    
    // {{{ clientReady
    /**
     * Client has finished processing e-mail-forwarding
     * 
     * @param object $Client
     * @param bool $Success
     * 
     * @access protected
     * @return void
     **/
    protected function clientReady ($Client, $Success) {
      // Close the client-connection
      $Client->quit ();
      
      // Remove the client
      foreach ($this->Clients as $i=>$C)
        if ($C === $Client) {
          unset ($this->Clients [$i]);
          break;
        }
      
      // Check if all clients are ready
      if (count ($this->Clients) > 0)
        return;
      
      // Respond and reset
      $this->respond (250, 'Ok, thank you for the postcard');
      $this->reset ();
    }
    // }}}
    
    // {{{ reset
    /**
     * Forget about everything (but the command-stack)
     * 
     * @access public
     * @return void
     **/
    public function reset () {
      // Close all client-connections
      foreach ($this->Clients as $Client)
        $Client->quit ();
      
      // Forget about the clients
      $this->Clients = array ();
      $this->rQueue = array ();
      $this->haveReceivers = false;
      $this->hadErrors = false;
      $this->allErrors = true;
      
      // Let our parent do the rest
      return parent::reset ();
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
      // Forward the message to all clients
      foreach ($Clients as $Client)
        $Client->setPayload ($Message);
      
      return null;
    }
    // }}}
    
    // {{{ lookupDestination
    /**
     * Retrive the destination-host for a given receiver
     * 
     * @param string $R
     * 
     * @access protected
     * @return string
     **/
    protected function lookupDestination ($R) {
      # TODO
      return false;
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
      // Retrive the destination
      if (($Destination = $this->lookupDestination ($R)) === false)
        return false;
      
      // Check if we have an SMTP-Client available
      if (isset ($this->Clients [$Destination]))
        $Client = $this->Clients [$Destination];
      else {
        $this->Clients [$Destination] = $Client = new qcEvents_Socket_Server_SMTP_Relay_Client ($Destination);
        $Client->setOriginator ($this->getOriginator ());
      }
      
      $this->rQueue [$R] = null;
      
      return $Client->addReceiver ($R);
    }
    // }}}
    
    // {{{ haveReceivers
    /**
     * Check if we have receivers assigned
     *    
     * @access protected  
     * @return bool
     * @remark This function only returns true if the queue is empty and at least one receiver was accepted
     **/
    protected function haveReceivers () {
      return $this->haveReceivers && (count ($this->rQueue) == 0);
    }
    // }}}
  }

?>