<?PHP

  require_once ('qcEvents/Socket/Stream/DNS/Message.php');
  
  class qcEvents_Socket_Stream_DNS_Record {
    /**
     * [QName] The Label that is asked for
     **/
    public $Label = '';
    
    /**
     * [QType] Type of DNS-RRs that is asked for
     **/
    public $Type = qcEvents_Socket_Stream_DNS_Message::TYPE_ANY;
    
    /**
     * [QClass] Class of DNS-RRs that is asked for
     **/
    public $Class = qcEvents_Socket_Stream_DNS_Message::CLASS_INTERNET;
    
    /**
     * [TTL] Time-to-live of this DNS-RR
     **/
    public $TTL = 0;
    
    /**
     * [RData] Payload of this DNS-RR
     **/
    public $Payload = '';
    
    // {{{ parse
    /**
     * Parse binary data into this object
     * 
     * @param string $Data
     * @param int $Offset
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data, &$Offset) {
      // Retrive the label of this record
      $this->Label = qcEvents_Socket_Stream_DNS_Message::getLabel ($Data, $Offset);
      
      // Retrive type, class and TTL
      $this->Type = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Class = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->TTL = (ord ($Data [$Offset++]) << 24) + (ord ($Data [$Offset++]) << 16) +
                   (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      
      // Retrive the payload
      $rdLength = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Payload = substr ($Data, $Offset, $rdLength);
      $Offset += $rdLength;
      
      return true;
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this question into a string
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function toString ($Offset, &$Labels) {
      // Create the record-header
      $Data =
        qcEvents_Socket_Stream_DNS_Message::setLabel ($this->Label, $Offset, $Labels) .
        chr (($this->Type & 0xFF00) >> 8) . chr ($this->Type & 0xFF) .
        chr (($this->Class & 0xFF00) >> 8) . chr ($this->Class & 0xFF) .
        chr (($this->TTL & 0xFF000000) >> 24) . chr (($this->TTL & 0xFF0000) >> 16) .
        chr (($this->TTL & 0xFF00) >> 8) . chr ($this->TTL & 0xFF);
      
      // Retrive the payload
      $Payload = $this->getData ($Offset + strlen ($Data) + 2, &$Labels);
      
      // Append the payload
      $Data .= chr ((strlen ($Payload) & 0xFF00) >> 8) . chr (strlen ($Payload) & 0xFF) . $Payload;
      
      return $Data;
    }
    // }}}
    
    // {{{ getData
    /**
     * Retrive the payload of this record
     * 
     * @access public
     * @return string
     **/
    public function getData () {
      return '';
    }
    // }}}
  }

?>