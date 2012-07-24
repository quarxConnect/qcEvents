<?PHP

  require_once ('qcEvents/Socket/Stream/DNS/Message.php');
  
  class qcEvents_Socket_Stream_DNS_Question {
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
      // Retrive the label
      $this->Label = qcEvents_Socket_Stream_DNS_Message::getLabel ($Data, $Offset);
      
      // Retrive type and class
      $this->Type = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Class = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      
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
      return
        qcEvents_Socket_Stream_DNS_Message::setLabel ($this->Label, $Offset, $Labels) .
        chr (($this->Type & 0xFF00) >> 8) . chr ($this->Type & 0xFF) .
        chr (($this->Class & 0xFF00) >> 8) . chr ($this->Class & 0xFF);
    }
    // }}}
  }

?>