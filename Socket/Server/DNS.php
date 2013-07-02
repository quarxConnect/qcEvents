<?PHP

  /**
   * qcEvents - Asyncronous DNS Server
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
  
  require_once ('qcEvents/Socket/Stream/DNS.php');
  require_once ('qcEvents/Socket/Stream/DNS/Header.php');
  
  class qcEvents_Socket_Server_DNS extends qcEvents_Socket_Stream_DNS {
    /* IDs of known DNS-Queries */
    private $IDs = array ();
    
    // {{{ __construct   
    /**
     * Create a new DNS Server
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
        
      // Register hooks
      $this->addHook ('dnsQuestionReceived', array ($this, 'dnsServerQuery'));
    }
    // }}}
    
    // {{{ dnsQueryReply
    /**
     * Generate the reply for a queued query
     * 
     * @param qcEvents_Socket_Stream_DNS_Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsQueryReply (qcEvents_Socket_Stream_DNS_Message $Message) {
      $ID = $Message->getID ();
      
      if (!isset ($this->IDs [$ID]))
        return;
      
      unset ($this->IDs [$ID]);
      
      return $this->dnsSendMessage ($Message);
    }
    // }}}
    
    // {{{ dnsServerQuery
    /**
     * Internal Callback: A DNS-Query was received
     * 
     * @param qcEvents_Socket_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected final function dnsServerQuery (qcEvents_Socket_Stream_DNS_Message $Message) {
      // Store the ID as known
      $this->IDs [$Message->getID ()] = $Message;
      
      // Setup a timeout for this Query
      $this->addTimeout (4, false, array ($this, 'dnsServerQueryTimeout'), $Message->getID ());
      
      // Fire a callback
      if (!is_object ($rc = $this->___callback ('dnsQueryReceived', $Message)) || !($rc instanceof qcEvents_Socket_Stream_DNS_Message))
        return;
      
      // Overwrite the ID
      $rc->setID ($Message->getID ());
      
      // Write out the reply
      $this->dnsQueryReply ($rc);
    }
    // }}}
    
    // {{{ dnsServerQueryTimeout
    /** 
     * Generate a timeout for a queued DNS-Query
     * 
     * @param int $ID
     * 
     * @access public
     * @return void
     **/
    public final function dnsServerQueryTimeout ($ID) {
      // Check if the query is still queued
      if (!isset ($this->IDs [$ID]))
        return;
      
      // Create a response
      $Response = $this->IDs [$ID]->createClonedResponse ();
      $Response->setError (qcEvents_Socket_Stream_DNS_Header::ERROR_SERVER);
      
      // Write out the response
      $this->dnsQueryReply ($Response);
    }
    // }}}
    
    
    // {{{ dnsQueryReceived
    /**
     * Callback: A DNS-Query was received
     * 
     * @param qcEvents_Socket_Stream_DNS_Message $Query
     * 
     * @access protected
     * @return qcEvents_Socket_Stream_DNS_Message If not NULL a direct reply is issued
     **/
    protected function dnsQueryReceived (qcEvents_Socket_Stream_DNS_Message $Query) { }
    // }}}
  }

?>