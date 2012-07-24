<?PHP

  require_once ('qcEvents/Socket/Stream/DNS.php');
  require_once ('qcEvents/Socket/Stream/DNS/Header.php');
  
  class qcEvents_Socket_Server_DNS extends qcEvents_Socket_Stream_DNS {
    private $inputBuffer = '';
    private $inputLength = null;
    
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
      // Run in UDP-Mode and take the data as whole query
      if ($this->isUDPServerClient ())
        return $this->dispatchQuery ($Data);
      
      // Run in TCP-Mode
      $this->inputBuffer .= $Data;
      unset ($Data);
      
      while (strlen ($this->inputBuffer) > 0) {
        // Check if we know the required length of the input-buffer
        if ($this->inputLength === null) {
          // We need at least two bytes
          if (strlen ($this->inputBuffer) < 2)
            break;
          
          // Retrive the desired length
          $this->inputLength = (ord ($this->inputBuffer [0]) << 8) + ord ($this->inputBuffer [1]);
          $this->inputBuffer = substr ($this->inputBuffer, 2);
        }
        
        // Check if there is enough data for the query
        if (strlen ($this->inputBuffer) < $this->inputLength)
          break;
        
        // Retrive the query from the buffer
        $Data = substr ($this->inputBuffer, 0, $this->inputLength);
        
        // Truncate the buffer
        $this->inputBuffer = substr ($this->inputBuffer, $this->inputLength);
        $this->inputLength = null;
        
        // Run the query
        $this->dispatchQuery ($Data);
        unset ($Data);
      }
    }
    // }}}
    
    // {{{ dispatchQuery
    /**
     * Parse and run a query
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function dispatchQuery ($Data) {
      // Try to parse the query (or discard it)
      if (!is_object ($Message = $this->parseQuery ($Data)))
        return;
      
      // Discard responses (as we are a server)
      if (!$Message->isQuery ())
        return;
      
      // Try to fetch an result
      if (!is_object ($Response = $this->getResponse ($Message))) {
        $Response = $Message->createClonedResponse ();
        $Response->setError (qcEvents_Socket_Stream_DNS_Header::ERROR_REFUSED);
      }
      
      $this->sendQuery ($Response);
    }
    // }}}
    
    // {{{ getResponse
    /**
     * Retrive the Response for a given query
     * 
     * @param object $Query
     * 
     * @access protected
     * @return object
     **/
    protected function getResponse ($Query) { }
    // }}}
  }

?>