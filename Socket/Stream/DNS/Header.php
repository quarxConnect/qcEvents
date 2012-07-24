<?PHP

  class qcEvents_Socket_Stream_DNS_Header {
    /**
     * DNS Opcodes
     **/
    const OPCODE_QUERY = 0x00;
    const OPCODE_IQUERY = 0x01;
    const OPCODE_STATUS = 0x02;
    
    /**
     * DNS Status Codes
     **/
    const ERROR_NONE = 0x00;
    const ERROR_FORMAT = 0x01;
    const ERROR_SERVER = 0x02;
    const ERROR_NAME = 0x03;
    const ERROR_UNSUP = 0x04;
    const ERROR_REFUSED = 0x05;
    
    /**
     * [ID] ID of the query
     **/
    public $ID = 0x0000;
    
    /**
     * [QR] This query is an response
     **/
    public $isResponse = false;
    
    /**
     * [Opcode] Kind of this query
     **/
    public $Opcode = qcEvents_Socket_Stream_DNS_Header::OPCODE_QUERY;
    
    /**
     * [AA] This query is an authoritativ answer
     **/
    public $Authoritative = false;
    
    /**
     * [TC] This query was truncated
     **/
    public $Truncated = false;
    
    /**
     * [RD] Recursive query is desired
     **/
    public $recursionDesired = false;
    
    /**
     * [RA] Recursive query is available
     **/
    public $recursionAvailable = false;
    
    /**
     * [RCode] Response-Code on this query
     **/
    public $RCode = qcEvents_Socket_Stream_DNS_Header::ERROR_NONE;
    
    /**
     * [QDCount] Number of questions on payload
     **/
    public $Questions = 0;
    
    /**
     * [ANCount] Number of answers on payload
     **/
    public $Answers = 0;
    
    /**
     * [NSCount] Number of authoritative records on payload
     **/
    public $Authorities = 0;
    
    /**
     * [ARCount] Number of additional records on the payload
     **/
    public $Additionals = 0;
    
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
      if (strlen ($Data) < $Offset + 12)
        return false;
      
      $this->ID = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->isResponse = (($b = ord ($Data [$Offset++])) & 0x80) == 0x80;
      $this->Opcode = ($b & 0x78) >> 3;
      $this->Authoritative = ($b & 0x04) == 0x04;
      $this->Truncated = ($b & 0x02) == 0x02;
      $this->recursionDesired = ($b & 0x01) == 0x01;
      $this->recursionAvailable = (($b = ord ($Data [$Offset++])) & 0x80) == 0x80;
      $Z = ($b & 0x70) >> 4;
      $this->RCode = ($b & 0x0F);
      
      $this->Questions = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Answers = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Authorities = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Additionals = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      
      return true;
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this header into a string
     * 
     * @access public
     * @return string
     **/
    public function toString () {
      return
        chr (($this->ID & 0xFF00) >> 8) . chr ($this->ID & 0xFF) . // Convert the ID
        chr (($this->isResponse ? 0x80 : 0x00) + ($this->Opcode << 3) + ($this->Authoritative ? 0x04 : 0x00) + ($this->Truncated ? 0x02 : 0x00) + ($this->recursionDesired ? 0x01 : 0x00)) .
        chr (($this->recursionAvailable ? 0x80 : 0x00) + ($this->RCode & 0x0F)) .
        chr (($this->Questions & 0xFF00) >> 8) . chr ($this->Questions & 0xFF) .
        chr (($this->Answers & 0xFF00) >> 8) . chr ($this->Answers & 0xFF) .
        chr (($this->Authorities & 0xFF00) >> 8) . chr ($this->Authorities & 0xFF) .
        chr (($this->Additionals & 0xFF00) >> 8) . chr ($this->Additionals & 0xFF);
    }
    // }}}
  }

?>