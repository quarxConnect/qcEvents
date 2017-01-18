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
  
  require_once ('qcEvents/Stream/DNS.php');
  require_once ('qcEvents/Stream/DNS/Header.php');
  
  class qcEvents_Server_DNS extends qcEvents_Stream_DNS {
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
      // Register hooks
      $this->addHook ('dnsQuestionReceived', function (qcEvents_Server_DNS $Self, qcEvents_Stream_DNS_Message $Message) {
        // Store the ID as known
        $this->IDs [$Message->getID ()] = $Message;
        
        // Fire a callback
        if (!is_object ($rc = $this->___callback ('dnsQueryReceived', $Message)) || !($rc instanceof qcEvents_Stream_DNS_Message))
          return;
        
        // Overwrite the ID
        $rc->setID ($Message->getID ());
        
        // Write out the reply
        $this->dnsQueryReply ($rc);
      });
      
      $this->addHook ('dnsQuestionTimeout', function (qcEvents_Server_DNS $Self, qcEvents_Stream_DNS_Message $Message) {
        // Retrive the ID from that message
        $ID = $Message->getID ();
        
        // Check if the query is still queued
        if (!isset ($this->IDs [$ID]))
          return;
        
        // Create a response
        $Response = $this->IDs [$ID]->createClonedResponse ();
        $Response->setError (qcEvents_Stream_DNS_Message::ERROR_SERVER);
        
        // Write out the response
        $this->dnsQueryReply ($Response);
      });
    }
    // }}}
    
    // {{{ dnsQueryReply
    /**
     * Generate the reply for a queued query
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsQueryReply (qcEvents_Stream_DNS_Message $Message) {
      // Retrive the ID of that message
      $ID = $Message->getID ();
      
      // Make sure we have it queued
      if (!isset ($this->IDs [$ID]))
        return;
      
      unset ($this->IDs [$ID]);
      
      // Write out the reply
      return $this->dnsStreamSendMessage ($Message);
    }
    // }}}
    
    // {{{ dnsQueryDiscard
    /**
     * Discaed a cached DNS-Query
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsQueryDiscard (qcEvents_Stream_DNS_Message $Message) {
      // Retrive the ID of that message
      $ID = $Message->getID ();
      
      // Try to removed
      if (isset ($this->IDs [$ID]) && ($this->IDs [$ID] === $Message))
        unset ($this->IDs [$ID]);
    }
    // }}}
    
    
    // {{{ dnsQueryReceived
    /**
     * Callback: A DNS-Query was received
     * 
     * @param qcEvents_Stream_DNS_Message $Query
     * 
     * @access protected
     * @return qcEvents_Stream_DNS_Message If not NULL a direct reply is issued
     **/
    protected function dnsQueryReceived (qcEvents_Stream_DNS_Message $Query) { }
    // }}}
  }

?>