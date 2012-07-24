<?PHP

  require_once ('qcEvents/Socket/Stream/DNS/Header.php');
  require_once ('qcEvents/Socket/Stream/DNS/Question.php');
  require_once ('qcEvents/Socket/Stream/DNS/Record.php');
  
  class qcEvents_Socket_Stream_DNS_Message {
    /**
     * DNS-Classes
     **/
    const CLASS_INTERNET = 0x01;
    const CLASS_CSNET = 0x02; // Obsoleted
    const CLASS_CHAOS = 0x03;
    const CLASS_HESIOD = 0x04;
    
    /**
     * DNS-RR-Types
     **/
    const TYPE_A = 0x01;
    const TYPE_NS = 0x02;
    const TYPE_MD = 0x03; // Obsoleted
    const TYPE_MF = 0x04; // Obsoleted
    const TYPE_CNAME = 0x05;
    const TYPE_SOA = 0x06;
    const TYPE_MB = 0x07; // Experimental
    const TYPE_MG = 0x08; // Experimental
    const TYPE_MR = 0x09; // Experimental
    const TYPE_NULL = 0x0A; // Experimental
    const TYPE_WKS = 0x0B;
    const TYPE_PTR = 0x0C;
    const TYPE_HINFO = 0x0D;
    const TYPE_MINFO = 0x0E;
    const TYPE_MX = 0x0F;
    const TYPE_TXT = 0x10;
    
    const TYPE_ANY = 0xFF;
    
    private $Header = null;
    private $Question = array ();
    private $Answer = array ();
    private $Authority = array ();
    private $Additional = array ();
    
    // {{{ getLabel
    /**
     * Retrive a DNS-Label at a given offset
     * 
     * @param string $Data
     * @param int $Offset
     * 
     * @access public
     * @return string
     **/
    public static function getLabel ($Data, &$Offset) {
      // Create an empty label
      $Label = '';
      
      // Load the label
      while (($qLength = ord ($Data [$Offset++])) > 0) {
        // Handle a compressed label
        if (($qLength & 0xC0) == 0xC0) {
          $Label .= self::getLabel ($Data, (($qLength - 0xC0) << 8) + ord ($Data [$Offset++]));
          
          break;
        }
        
        // Handle a normal label
        $Label .= substr ($Data, $Offset, $qLength) . '.';
        $Offset += $qLength;
      }
      
      // Return the whole label
      return $Label;
    }
    // }}}
    
    // {{{ setLabel
    /**
     * Write a label for output
     * 
     * @param string $Label
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public static function setLabel ($Label, $Offset, &$Labels) {
      // Make sure we have the label split into parts
      if (!is_array ($Label))
        $Label = explode ('.', $Label);
      
      $Data = '';
      
      while (count ($Label) > 0) {
        # TODO: Add support for compression
        $Chunk = array_shift ($Label);
        
        $Data .= chr (strlen ($Chunk)) . $Chunk;
      }
      
      return $Data;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new DNS-Message
     * 
     * @param string $Data (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Data = null) {
      // Create sub-objects
      $this->Header = new qcEvents_Socket_Stream_DNS_Header;
      
      // Check wheter to parse an existing message
      if ($Data !== null)
        $this->parse ($Data);
    }
    // }}}
    
    // {{{ parse
    /**
     * Try to parse an DNS-Message
     * 
     * @param string $Data
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data) {
      if (strlen ($Data) < 12)
        return false;
      
      $Offset = 0;
      
      // Parse the header
      $Header = new qcEvents_Socket_Stream_DNS_Header;
      
      if (!$Header->parse ($Data, $Offset))
        return false;
      
      // Parse the questions
      $Questions = array ();
      $c = $Header->Questions;
      
      while ($c-- > 0) {
        $Question = new qcEvents_Socket_Stream_DNS_Question;
        
        if (!$Question->parse ($Data, $Offset))
          return false;
        
        $Questions [] = $Question;
      }
      
      // Parse the answers
      $Answers = array ();
      $c = $Header->Answers;
      
      while ($c-- > 0) {
        $Record = new qcEvents_Socket_Stream_DNS_Record;
        
        if (!$Record->parse ($Data, $Offset))
          return false;
        
        $Answers [] = $Record;
      }
      
      // Parse the authority nameservers
      $Authorities = array ();
      $c = $Header->Authorities;
      
      while ($c-- > 0) {
        $Record = new qcEvents_Socket_Stream_DNS_Record;
        
        if (!$Record->parse ($Data, $Offset))
          return false;
        
        $Authorities [] = $Record;
      }
      
      // Parse additional Records
      $Additionals = array ();
      $c = $Header->Additionals;
      
      while ($c-- > 0) {
        $Record = new qcEvents_Socket_Stream_DNS_Record;
        
        if (!$Record->parse ($Data, $Offset))
          return false;
        
        $Additionals [] = $Record;
      }
      
      // Commit to ourself
      $this->Header = $Header;
      $this->Question = $Questions;
      $this->Answer = $Answers;
      $this->Authority = $Authorities;
      $this->Additional = $Additionals;
      
      return true;
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this DNS-Message into a string
     * 
     * @access public
     * @return string
     **/
    public function toString () {
      // Convert the header into a string
      $Data = $this->Header->toString ();
      
      // Append Question-Section
      $Labels = array ();
      
      foreach ($this->Question as $Q)
        $Data .= $Q->toString (strlen ($Data), $Labels);
      
      // Append Answer-Section
      foreach ($this->Answer as $A)
        $Data .= $Q->toString (strlen ($Data), $Labels);
      
      // Append Authority-Section
      foreach ($this->Authority as $A)
        $Data .= $Q->toString (strlen ($Data), $Labels);
      
      // Append Additional-Section
      foreach ($this->Additional as $A)
        $Data .= $Q->toString (strlen ($Data), $Labels);
      
      // Return the buffer
      return $Data;
    }
    // }}}
    
    // {{{ isQuery
    /**
     * Check if this DNS-Message is a query
     * 
     * @access public
     * @return bool
     **/
    public function isQuery () {
      if (!is_object ($this->Header))
        return false;
      
      return !$this->Header->isResponse;
    }
    // }}}
    
    // {{{ setError
    /**
     * Set an error-condition here
     * 
     * @param enum $Error
     * 
     * @access public
     * @return void
     **/
    public function setError ($Error) {
      $this->Header->RCode = $Error;
    }
    // }}}
    
    // {{{ tuncate
    /**
     * Try to truncate data from this query
     * 
     * @access public
     * @return bool
     **/
    public function tuncate () {
      if (!($q = $this->isQuery ()) && (count ($this->Question) > 0))
        $this->Question = array ();
      elseif (count ($this->Additional) > 0)
        array_pop ($this->Additional);
      elseif (count ($this->Authority) > 0)
        array_pop ($this->Authority);
      elseif (count ($this->Answer) > 0)
        array_pop ($this->Answer);
      elseif ($q && (count ($this->Question) > 0))
        array_pop ($this->Question);
      else
        return false;
      
      return true;
    }
    // }}}
    
    // {{{ createResponse
    /**
     * Create an empty response for this query
     * 
     * @access public
     * @return object
     **/
    public function createResponse () {
      // Create a new message
      $Response = new $this;
      
      // Clone the header
      $Response->Header = clone $this->Header;
      
      // Reset the counters
      $Response->Header->RCode = qcEvents_Socket_Stream_DNS_Header::ERROR_NONE;
      $Response->Header->Questions = 0;
      $Response->Header->Answers = 0;
      $Response->Header->Authorities = 0;
      $Response->Header->Additionals = 0;
      
      // Invert the response-status
      $Response->Header->isResponse = !$this->Header->isResponse;
      $Response->Header->recursionAvailable = false;
      
      return $Response;
    }
    // }}}
    
    // {{{ createClonedResponse
    /**
     * Create a response from a copy of this Message
     * 
     * @access public
     * @return object
     **/
    public function createClonedResponse () {
      // Create a normal response
      if (!is_object ($Response = $this->createResponse ()))
        return false;
      
      // Copy all values
      $Response->Header->Questions = count ($this->Question);
      $Response->Header->Answers = count ($this->Answer);
      $Response->Header->Authorities = count ($this->Authority);
      $Response->Header->Additionals = count ($this->Additional);
      
      foreach ($this->Question as $Q)
        $Response->Question [] = clone $Q;
      
      foreach ($this->Answer as $A)
        $Response->Answer [] = clone $A;
      
      foreach ($this->Authority as $A)
        $Response->Authority [] = clone $A;
      
      foreach ($this->Additional as $A)
        $Response->Additional [] = clone $A;
      
      return $Response;
    }
    // }}}
  }

?>