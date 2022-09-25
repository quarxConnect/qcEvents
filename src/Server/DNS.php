<?php

  /**
   * quarxCOnnect Events - Asyncronous DNS Server
   * Copyright (C) 2013-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Server;
  use \quarxConnect\Events;
  use \quarxConnect\Events\Stream;
  
  class DNS extends Stream\DNS {
    /* IDs of known DNS-Queries */
    private $IDs = [ ];
    
    // {{{ __construct   
    /**
     * Create a new DNS Server
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Register hooks
      $this->addHook (
        'dnsQuestionReceived',
        function (DNS $Self, Stream\DNS\Message $Message) {
          // Store the ID as known
          $this->IDs [$Message->getID ()] = $Message;
         
          // Fire a callback
          if (!is_object ($rc = $this->___callback ('dnsQueryReceived', $Message)) || !($rc instanceof Stream\DNS\Message))
            return;
          
          // Overwrite the ID
          $rc->setID ($Message->getID ());
          
          // Write out the reply
          $this->dnsQueryReply ($rc);
        }
      );
      
      $this->addHook (
        'dnsQuestionTimeout',
        function (DNS $Self, Stream\DNS\Message $Message) {
          // Retrive the ID from that message
          $ID = $Message->getID ();
          
          // Check if the query is still queued
          if (!isset ($this->IDs [$ID]))
            return;
          
          // Create a response
          $Response = $this->IDs [$ID]->createClonedResponse ();
          $Response->setError (Stream\DNS\Message::ERROR_SERVER);
          
          // Write out the response
          $this->dnsQueryReply ($Response);
        }
      );
    }
    // }}}
    
    // {{{ dnsQueryReply
    /**
     * Generate the reply for a queued query
     * 
     * @param Stream\DNS\Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsQueryReply (Stream\DNS\Message $Message) : void {
      // Retrive the ID of that message
      $ID = $Message->getID ();
      
      // Make sure we have it queued
      if (!isset ($this->IDs [$ID]))
        return;
      
      unset ($this->IDs [$ID]);
      
      // Write out the reply
      $this->dnsStreamSendMessage ($Message);
    }
    // }}}
    
    // {{{ dnsQueryDiscard
    /**
     * Discaed a cached DNS-Query
     * 
     * @param Stream\DNS\Message $Message
     * 
     * @access public
     * @return void
     **/
    public function dnsQueryDiscard (Stream\DNS\Message $Message) : void {
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
     * @param Stream\DNS\Message $Query
     * 
     * @access protected
     * @return Stream\DNS\Message If not NULL a direct reply is issued
     **/
    protected function dnsQueryReceived (Stream\DNS\Message $Query) : ?Stream\DNS\Message {
      return null;
    }
    // }}}
  }
