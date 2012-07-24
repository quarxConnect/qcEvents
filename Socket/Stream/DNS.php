<?PHP

  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Socket/Stream/DNS/Message.php');
  
  abstract class qcEvents_Socket_Stream_DNS extends qcEvents_Socket {
    // {{{ parseQuery
    /**
     * Parse an DNS-Query
     * 
     * @param string $Data
     * 
     * @access protected
     * @return object
     **/
    protected function parseQuery ($Data) {
      $Message = new qcEvents_Socket_Stream_DNS_Message;
      
      if ($Message->parse ($Data))
        return $Message;
      
      return false;
    }
    // }}}
    
    // {{{ sendQuery
    /**
     * Write a DNS-Query to the wire
     * 
     * @param object $Query
     * 
     * @access protected
     * @return bool
     **/
    protected function sendQuery ($Query) {
      // Convert the Query into a string
      $Data = $Query->toString ();
      
      // Handle UDP-Writes
      if ($this->isUDP ()) {
        // Make sure that the payload is at most 512 Bytes
        while (strlen ($Data) > 512) {
          if (!$Query->truncate ())
            return false;
          
          $Data = $Query->toString ();
        }
        
        return $this->write ($Data);
      }
      
      // Write out TCP-Mode
      return $this->write (chr ((strlen ($Data) & 0xFF00) >> 8) . chr (strlen ($Data) & 0xFF) . $Data);
    }
    // }}}
  }

?>