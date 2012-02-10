<?PHP

  /**
   * qcEvents - SMTP-Relay-Client, a helper for our SMTP-Relay
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
  
  require_once ('qcEvents/Socket/Client/SMTP.php');
  
  /**
   * SMTP-Relay-Client
   * -----------------
   * Helper class for direct mail-relaying
   * 
   * @class qcEvents_Socket_Server_SMTP_Relay_Client
   * @extends qcEvents_Socket_Client_SMTP
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server_SMTP_Relay_Client extends qcEvents_Socket_Client_SMTP {
    private $Parent = null;
    
    private $Receivers = array ();
    private $onReceivers = true;
    
    
    function __construct ($Parent, $Host, $Port = 25) {
      $this->Parent = $Parent;
      
      parent::__construct ($Host, $Port);
    }
    
    // {{{ addReceiver
    /**
     * Append a receiver to our queue
     * 
     * @param string $Receiver
     * 
     * @access public
     * @return void
     **/
    public function addReceiver ($R) {
      if (!$this->onReceivers)
        return false;
      
      $this->Receivers [] = $R;
      
      return null;
    }
    // }}}
    
    // {{{ deferredOriginator
    /**
     * Callback: The Originator was not accepted by our relay-destination
     * 
     * @param string $Originator
     * @param int $Code
     * 
     * @access protected
     * @return void
     **/
    protected function deferredOriginator ($Originator, $Code) {
      $this->Parent->deferredOriginator ($this, $Originator, $Code);
    }
    // }}}
    
    // {{{ acceptedReceiver
    /**
     * Callback: A receiver was accepted by our relay-destination
     * 
     * @param string $Receiver
     * @parem int $Code
     * 
     * @access protected
     * @return void
     **/
    protected function acceptedReceiver ($Receiver, $Code) {
      $this->Parent->forwardedReceiver ($this, $Receiver, $Code);
    }
    // }}}
    
    // {{{ deferredReceiver
    /**
     * Callback: A receiver was rejected by our relay-destination
     * 
     * @param string $Receiver
     * @parem int $Code
     * 
     * @access protected
     * @return void
     **/
    protected function deferredReceiver ($Receiver, $Code) {
      $this->Parent->forwardedReceiver ($this, $Receiver, $Code);
    }
    // }}}
    
    // {{{ getNextReceiver
    /**
     * Retrive the receiver to send next
     * 
     * @access protected
     * @return string Returns a string is a receiver is available
     **/
    protected function getNextReceiver () {
      // Check if we are currently being feeded
      if (!$this->onReceivers)
        return true;
      
      // Check if we have receivers buffered
      if (count ($this->Receivers) == 0)
        return null;
      
      return array_shift ($this->Receivers);
    }
    // }}}
    
    // {{{ acceptedMail
    /**
     * Internal Callback: The Mail was accepted by the server
     * 
     * @access protected
     * @return void
     **/
    protected function acceptedMail () {
      $this->Parent->acceptedMail ($this);
    }
    // }}}
    
    // {{{ deferredMail
    /**
     * Internal Callback: The Mail was rejected by the server
     * 
     * @access protected
     * @return void
     **/
    protected function deferredMail () {
      $this->Parent->deferredMail ($this);
    }
    // }}}
  }

?>