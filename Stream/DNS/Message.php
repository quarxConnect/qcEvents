<?PHP

  /**
   * qcEvents - DNS Messages
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/DNS/Header.php');
  require_once ('qcEvents/Stream/DNS/Question.php');
  require_once ('qcEvents/Stream/DNS/Record.php');
  require_once ('qcEvents/Stream/DNS/Record/EDNS.php');
  require_once ('qcEvents/Stream/DNS/Recordset.php');
  require_once ('qcEvents/Stream/DNS/Label.php');
  
  class qcEvents_Stream_DNS_Message {
    /**
     * DNS Opcodes
     * 
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-5
     **/
    const OPCODE_QUERY  = 0x00;
    const OPCODE_IQUERY = 0x01; // Obsoleted by RFC 3425
    const OPCODE_STATUS = 0x02;
    const OPCODE_NOTIFY = 0x04; // RFC 1996
    const OPCODE_UPDATE = 0x05; // RFC 2136
    
    /**
     * DNS Status/Error Codes
     * 
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-6
     **/
    const ERROR_NONE          = 0x00;
    const ERROR_FORMAT        = 0x01;
    const ERROR_SERVER        = 0x02;
    const ERROR_NAME          = 0x03;
    const ERROR_UNSUP         = 0x04;
    const ERROR_REFUSED       = 0x05;
    const ERROR_NAME_EXISTANT = 0x06; // RFC 2136
    const ERROR_RR_EXISTANT   = 0x07; // RFC 2136
    const ERROR_RR_MISSING    = 0x08; // RFC 2136
    const ERROR_NOT_AUTH      = 0x09; // RFC 2136, RFC 2845 TSIG
    const ERROR_NOT_IN_ZONE   = 0x0A; // RFC 2136
    
    const ERROR_BAD_SIG       = 0x10; // RFC 2845 TSIG
    const ERROR_BAD_KEY       = 0x11; // RFC 2845 TSIG
    const ERROR_BAD_TIME      = 0x12; // RFC 2845 TSIG
    
    /**
     * DNS Flags
     * 
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-12
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-13
     **/
    const FLAG_AUTHORITATIVE       = 0x01;
    const FLAG_TRUNCATED           = 0x02;
    const FLAG_RECURSION_DESIRED   = 0x04;
    const FLAG_RECURSION_AVAILABLE = 0x08;
    
    const FLAG_AUTHENTIC_DATA      = 0x20; // RFC 4035
    const FLAG_CHECKING_DISABLED   = 0x40; // RFC 4035
    
    const FLAG_DNSSEC_OK           = 0x400000; // EDNS, RFC 4035
    
    /**
     * DNS-Classes
     * 
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-2
     **/
    const CLASS_INTERNET = 0x0001;
    const CLASS_CSNET    = 0x0002; // Obsoleted
    const CLASS_CHAOS    = 0x0003;
    const CLASS_HESIOD   = 0x0004;
    const CLASS_NONE     = 0x00FE;
    const CLASS_ALL      = 0x00FF;
    
    /**
     * DNS-RR-Types
     * 
     * @see http://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml#dns-parameters-4
     **/
    const TYPE_A      = 0x01;	// RFC 1035
    const TYPE_NS     = 0x02;	// RFC 1035
    const TYPE_MD     = 0x03;	// RFC 1035, Obsoleted
    const TYPE_MF     = 0x04;	// RFC 1035, Obsoleted
    const TYPE_CNAME  = 0x05;	// RFC 1035
    const TYPE_SOA    = 0x06;	// RFC 1035
    const TYPE_MB     = 0x07;	// RFC 1035, Experimental
    const TYPE_MG     = 0x08;	// RFC 1035, Experimental
    const TYPE_MR     = 0x09;	// RFC 1035, Experimental
    const TYPE_NULL   = 0x0A;	// RFC 1035, Experimental
    const TYPE_WKS    = 0x0B;	// RFC 1035
    const TYPE_PTR    = 0x0C;	// RFC 1035
    const TYPE_HINFO  = 0x0D;	// RFC 1035
    const TYPE_MINFO  = 0x0E;	// RFC 1035
    const TYPE_MX     = 0x0F;	// RFC 1035
    const TYPE_TXT    = 0x10;	// RFC 1035
    const TYPE_AAAA   = 0x1C;	// RFC 3596
    const TYPE_SRV    = 0x21;	// RFC 2782
    const TYPE_OPT    = 0x29;	// RFC 6891 EDNS
    
    const TYPE_DS     = 0x2B;	// RFC 4034
    const TYPE_RRSIG  = 0x2E;	// RFC 4034
    const TYPE_NSEC   = 0x2F;	// RFC 4034
    const TYPE_DNSKEY = 0x30;	// RFC 4034
    
    const TYPE_TSIG   = 0xFA;   // RFC 2845 TSIG
    
    const TYPE_ANY    = 0xFF;	// RFC 1035
    
    private $Header = null;
    private $Question = array ();
    private $Answer = null;
    private $Authority = null;
    private $Additional = null;
    
    private $ednsRecord = null;
    
    // {{{ getLabel
    /**
     * Retrive a DNS-Label at a given offset
     * 
     * @param string $Data
     * @param int $Offset
     * @param bool $allowCompressed (optional) Handle compressed labels (default)
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public static function getLabel ($Data, &$Offset, $allowCompressed = true) {
      // Create an empty label
      $Label = array ();
      $l = strlen ($Data);
      
      // Load the label
      while (($Offset < $l) && (($qLength = ord ($Data [$Offset++])) > 0)) {
        // Handle a compressed label
        if (($qLength & 0xC0) == 0xC0) {
          $nextOffset = (($qLength - 0xC0) << 8) + ord ($Data [$Offset++]);
          
          foreach (self::getLabel ($Data, $nextOffset)->getParts () as $Part)
            $Label [] = $Part;
          
          if (!$allowCompressed)
            return false;
          
          break;
        }
        
        // Handle a normal label
        $Label [] = substr ($Data, $Offset, $qLength);
        $Offset += $qLength;
      }
      
      // Return the whole label
      return new qcEvents_Stream_DNS_Label ($Label);
    }
    // }}}
    
    // {{{ setLabel
    /**
     * Write a label for output
     * 
     * @param string $Label The label to convert
     * @param int $Offset The location in blob where the label should be
     * @param array &$Labels A set of already written labels
     * 
     * @access public
     * @return string
     **/
    public static function setLabel ($Label, $Offset = null, &$Labels = array ()) {
      // Make sure we have the label split into parts
      if ($Label instanceof qcEvents_Stream_DNS_Label) {
        $Name = strtolower ($Label);
        $Label = $Label->getParts ();
      } elseif (!is_array ($Label)) {
        $Name = strtolower ($Label);
        $Label = explode ('.', $Label);
        
        if ((($l = strlen ($Name)) < 1) || ($Name [$l - 1] != '.'))
          $Name .= '.';
      } else
        $Name = strtolower (implode ('.', $Label)) . '.';
      
      // Make sure the name is not too long
      if (strlen ($Name) > 255)
        return false;
      
      // Generate the binary label
      if (count ($Label) == 0)
        return chr (0x00);
      
      $Data = '';
      $Chunk = '';
      
      while (count ($Label) > 0) {
        // Check if we may compress the label
        if (isset ($Labels [$Name]))
          return $Data . chr (0xC0 + (($Labels [$Name] >> 8) & 0x3F)) . chr ($Labels [$Name] & 0xFF);
        
        // Retrive the current chunk
        $Chunk = array_shift ($Label);
        
        // Make sure the label is not too long
        if (($l = strlen ($Chunk)) > 63)
          return false;
        
        // Register the label
        if (($l > 0) && ($Offset !== null)) {
          $Labels [$Name] = $Offset;
          $Offset += $l + 1;
        }
        
        // Append to binary output
        $Data .= chr ($l) . $Chunk;
        
        // Truncate from output
        $Name = substr ($Name, ++$l);
      }
      
      if (strlen ($Chunk) > 0)
        $Data .= chr (0);
      
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
      $this->Header = new qcEvents_Stream_DNS_Header;
      $this->Answer = new qcEvents_Stream_DNS_Recordset;
      $this->Authority = new qcEvents_Stream_DNS_Recordset;
      $this->Additional = new qcEvents_Stream_DNS_Recordset;
      
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
     * @param bool $parseErrors (optional)
     * 
     * @access public
     * @return mixed - TRUE if everything was successfull, DNS-RCode if something went wrong
     **/
    public function parse ($Data, $parseErrors = false) {
      // Make sure we have sufficient data
      if (($Length = strlen ($Data)) < 12) {
        trigger_error ('DNS-Message too short');
        
        return false;
      }
      
      $Offset = 0;
      $errCode = null;
      
      // Parse the header
      $Header = new qcEvents_Stream_DNS_Header;
      
      if (!$Header->parse ($Data, $Offset, $Length)) {
        trigger_error ('Could not parse DNS-Header');
        
        return false;
      }
      
      // Parse the questions
      $Questions = array ();
      $c = $Header->Questions;
      
      while ($c-- > 0) {
        $Question = new qcEvents_Stream_DNS_Question;
        
        if (!$Question->parse ($Data, $Offset, $Length)) {
          trigger_error ('Failed to parse Question #' . ($Header->Questions - $c + 1));
          
          return false;
        }
        
        $Questions [] = $Question;
      }
      
      // Parse the answers
      $Answers = new qcEvents_Stream_DNS_Recordset;
      $c = $Header->Answers;
      
      while ($c-- > 0) {
        if (!is_object ($Record = qcEvents_Stream_DNS_Record::fromString ($Data, $Offset, $Length))) {
          trigger_error ('Failed to parse Answer #' . ($Header->Answers - $c + 1));
          
          return false;
        }
        
        $Answers [] = $Record;
      }
      
      // Parse the authority nameservers
      $Authorities = new qcEvents_Stream_DNS_Recordset;
      $c = $Header->Authorities;
      
      while ($c-- > 0) {
        if (!is_object ($Record = qcEvents_Stream_DNS_Record::fromString ($Data, $Offset))) {
          trigger_error ('Failed to parse Authority #' . ($Header->Authorities - $c + 1));
          
          return false;
        }
        
        $Authorities [] = $Record;
      }
      
      // Parse additional Records
      $Additionals = new qcEvents_Stream_DNS_Recordset;
      $c = $Header->Additionals;
      $ednsRecord = null;
      
      while ($c-- > 0) {
        // Try to parse the record
        if (!is_object ($Record = qcEvents_Stream_DNS_Record::fromString ($Data, $Offset))) {
          trigger_error ('Failed to parse Additional #' . ($Header->Additionals - $c + 1));
          
          return false;
        }
        
        // Check if this is an EDNS-Message
        if ($Record instanceof qcEvents_Stream_DNS_Record_EDNS) {
          // Fail if the record is present more than once or if the record has a non-empty label
          if (($ednsRecord !== null) || (strlen ($Record->getLabel ()) > 1)) {
            $errCode = qcEvents_Stream_DNS_Message::ERROR_FORMAT;
            
            if (!$parseErrors) {
              trigger_error ('Invalid EDNS-Data: ' . ($ednsRecord ? 'Dupplicate EDNS-Records' : 'EDNS-Record as label of size ' . strlen ($Record->getLabel ()) . strval ($Record->getLabel ())));
              
              return false;
            }
          }
          
          $ednsRecord = $Record;
        }
        
        $Additionals [] = $Record;
      }
      
      // Commit to ourself
      $this->Header = $Header;
      $this->Question = $Questions;
      $this->Answer = $Answers;
      $this->Authority = $Authorities;
      $this->Additional = $Additionals;
      $this->ednsRecord = $ednsRecord;
      
      return ($errCode === null ? true : $errCode);
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
      // Update the header
      $this->Header->Questions = count ($this->Question);
      $this->Header->Answers = count ($this->Answer);
      $this->Header->Authorities = count ($this->Authority);
      $this->Header->Additionals = count ($this->Additional);
      
      // Convert the header into a string
      $Data = $this->Header->toString ();
      
      // Append Question-Section
      $Labels = array ();
      
      foreach ($this->Question as $Q)
        $Data .= $Q->toString (strlen ($Data), $Labels);
      
      // Append Answer-Section
      foreach ($this->Answer as $A)
        $Data .= $A->toString (strlen ($Data), $Labels);
      
      // Append Authority-Section
      foreach ($this->Authority as $A)
        $Data .= $A->toString (strlen ($Data), $Labels);
      
      // Append Additional-Section
      foreach ($this->Additional as $A)
        $Data .= $A->toString (strlen ($Data), $Labels);
      
      // Return the buffer
      return $Data;
    }
    // }}}
    
    // {{{ isQuestion
    /**
     * Check if this DNS-Message is a question
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function isQuestion ($Toggle = null) {
      if (!is_object ($this->Header))
        return false;
      
      if ($Toggle !== null) {
        if (!($this->Header->isResponse = !$Toggle))
          $this->Header->recursionDesired = true;
        
        return true;
      }
      
      return !$this->Header->isResponse;
    }
    // }}}
    
    // {{{ isExtended
    /**
     * Check or set if this DNS-Message is an extended DNS Message
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function isExtended ($Toggle = null) {
      // Just return our extended-status
      if ($Toggle === null)
        return is_object ($this->ednsRecord);
      
      // Make sure we have no EDNS-Record
      if ($Toggle === false) {
        $this->ednsRecord = null;
        
        foreach ($this->Additional as $ID=>$Record)
          if ($Record->getType () === self::TYPE_OPT)
            unset ($this->Additional [$ID]);
        
        return true;
      // Check if the toggle is something wrong
      } elseif ($Toggle !== true)
        return false;
      
      // Check if we are already extended
      if ($this->ednsRecord)
        return true;
      
      $this->Additional [] = $this->ednsRecord = new qcEvents_Stream_DNS_Record_EDNS;
      
      return true;
    }
    // }}}
    
    // {{{ getHeader
    /**
     * Retrive the header of this message
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Header
     **/
    public function getHeader () {
      return $this->Header;
    }
    // }}}
    
    // {{{ getID
    /**
     * Retrive the ID of this message
     * 
     * @access public
     * @return int
     **/
    public function getID () {
      if (!is_object ($this->Header))
        return false;
      
      return $this->Header->ID;
    }
    // }}}
    
    // {{{ setID
    /**
     * Set the ID of this query
     * 
     * @param int $ID
     * 
     * @access public
     * @return bool
     **/
    public function setID ($ID) {
      if (($ID > 0xffff) || ($ID < 1))
        return false;
      
      if (!is_object ($this->Header))
        return false;
      
      $this->Header->ID = $ID;
      
      return true;
    }
    // }}}
    
    // {{{ setRandomID
    /**
     * Generate a random ID for our header
     * 
     * @access public
     * @return int
     **/
    public function setRandomID () {
      $this->setID ($ID = mt_rand (0, 0xffff));
      
      return $ID;
    }
    // }}}
    
    // {{{ getOpcode
    /**
     * Retrive the opcode of this DNS-Message
     * 
     * @access public
     * @return enum
     **/
    public function getOpcode () {
      return $this->Header->getOpcode ();
    }
    // }}}
    
    // {{{ setOpcode
    /**
     * Set a new opcode for this message
     * 
     * @param enum $Opcode
     * 
     * @access public
     * @return bool
     **/
    public function setOpcode ($Opcode) {
      return $this->Header->setOpcode ($Opcode);
    }
    // }}}
    
    // {{{ getFlags
    /**
     * Retrive all flags of this DNS-Message
     * 
     * @access public
     * @return int
     **/
    public function getFlags () {
      // Retrive traditional flags
      $Flags = $this->Header->getFlags ();
      
      // Add flags from EDNS
      if ($this->ednsRecord)
        $Flags = $Flags | ($this->ednsRecord->getFlags () << 7);
      
      return $Flags;
    }
    // }}}
    
    // {{{ setFlags
    /**
     * Set the flags of this DNS-Message
     * 
     * @param int $Flags
     * 
     * @access public
     * @return bool
     **/
    public function setFlags ($Flags) {
      // Check for extended flags
      if (($Flags > 0x7F) && !$this->ednsRecord)
        return false;
      
      // Forward traditional flags to header
      if (!$this->Header->setFlags ($Flags & 0x7F))
        return false;
      
      // Store extended flags on EDNS-Record
      if ($this->ednsRecord)
        $this->ednsRecord->setFlags ($Flags >> 7);
      
      return true;
    }
    // }}}
    
    // {{{ addFlag
    /**
     * Enable a given flag on this message (while leaving all others intact)
     * 
     * @param int $Flag
     * 
     * @access public
     * @return bool
     **/
    public function addFlag ($Flag) {
      if (($Flags = $this->getFlags ()) === false)
        return false;
      
      return $this->setFlags ($Flags | $Flag);
    }
    // }}}
    
    // {{{ getError
    /**
     * Retrive the error-code from the message
     * 
     * @access public
     * @return enum
     **/
    public function getError () {
      return ($this->Header->RCode & 0x0F) + ($this->ednsRecord ? ($this->ednsRecord->getReturnCode () << 4) : 0);
    }
    // }}}
    
    // {{{ setError
    /**
     * Set an error-condition here
     * 
     * @param enum $Error
     * 
     * @access public
     * @return bool
     **/
    public function setError ($Error) {
      // Check for an extended code
      if (($Error > 0x0F) && !$this->ednsRecord)
        return false;
      
      // Set the classic return-code on the header
      $this->Header->RCode = ($Error & 0x0F);
      
      // Update EDNS-Record
      if ($this->ednsRecord)
        $this->ednsRecord->setReturnCode ($Error >> 4);
      
      return true;
    }
    // }}}
    
    // {{{ getQuestions
    /**
     * Retrive all questions from this message
     * 
     * @access public
     * @return array
     **/
    public function getQuestions () {
      return $this->Question;
    }
    // }}}
    
    // {{{ addQuestion
    /**
     * Add a question to this message
     * 
     * @param qcEvents_Stream_DNS_Question $Question
     * 
     * @access public
     * @return void
     **/
    public function addQuestion (qcEvents_Stream_DNS_Question $Question) {
      $this->Question [] = $Question;
    }
    // }}}
    
    // {{{ getAnswers
    /**
     * Retrive all answers from this message
     * 
     * @access public
     * @return array
     **/
    public function getAnswers () {
      return $this->Answer;
    }
    // }}}
    
    // {{{ addAnswer
    /**
     * Add an answer to this message
     * 
     * @param qcEvents_Stream_DNS_Record $Answer
     * @param bool $First (optional) Put Record in first place
     * 
     * @access public
     * @return void
     **/
    public function addAnswer (qcEvents_Stream_DNS_Record $Answer, $First = false) {
      if ($First)
        array_unshift ($this->Answer, $Answer);
      else
        $this->Answer [] = $Answer;
    }
    // }}}
    
    // {{{ unsetAnswer
    /**
     * Remove all answer-records from this message
     * 
     * @access public
     * @return void
     **/
    public function unsetAnswer () {
      $this->Answer = array ();
    }
    // }}}
    
    // {{{ getAuthorities
    /**
     * Retrive all authority-answers from this message
     * 
     * @access public
     * @return array
     **/
    public function getAuthorities () {
      return $this->Authority;
    }
    // }}}
    
    // {{{ addAuthority
    /**
     * Add a record to authority-section
     * 
     * @param  qcEvents_Stream_DNS_Record $Record
     * 
     * @access public
     * @return void
     **/
    public function addAuthority (qcEvents_Stream_DNS_Record $Record) {
      $this->Authority [] = $Record;
    }
    // }}}
    
    // {{{ getAdditionals
    /**
     * Retrive all additional answers from this message
     * 
     * @access public
     * @return array
     **/
    public function getAdditionals () {
      return $this->Additional;
    }
    // }}}
    
    // {{{ getDatagramSize
    /**
     * Retrive the disired maximum size of datagrams
     * 
     * @access public
     * @return int
     **/
    public function getDatagramSize () {
      if ($this->ednsRecord)
        return max (512, $this->ednsRecord->getDatagramSize ());
      
      return 512;
    }
    // }}}
    
    // {{{ setDatagramSize
    /**
     * Set the maximum size of datagrams for this message
     * 
     * @param int $Size
     * 
     * @access public
     * @return bool
     **/
    public function setDatagramSize ($Size) {
      if (!$this->ednsRecord || ($Size < 512))
        return false;
      
      return $this->ednsRecord->setDatagramSize ($Size);
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
      // Try to remove all questions first (if we are the answer)
      if (!($q = $this->isQuestion ()) && (count ($this->Question) > 0))
        $this->Question = array ();
      
      // ... or additional informations
      elseif (count ($this->Additional) > ($this->ednsRecord ? 1 : 0)) {
        $RR = array_pop ($this->Additional);
        
        // Make sure we do not truncate the edns-record
        if ($RR == $this->ednsRecord) {
          array_pop ($this->Additional);
          array_unshift ($this->Additional, $RR);
        }
      
      // ... maybe no one cares about authorities
      } elseif (count ($this->Authority) > 0)
        array_pop ($this->Authority);
      
      // ... finally do the hard stuff
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
      
      $Response->Header->Opcode = qcEvents_Stream_DNS_Message::OPCODE_QUERY;
      $Response->Header->RCode = qcEvents_Stream_DNS_Message::ERROR_NONE;
      $Response->Header->setFlags ($this->Header->getFlags () & 0x44);
      
      // Reset the counters
      $Response->Header->Questions = 0;
      $Response->Header->Answers = 0;
      $Response->Header->Authorities = 0;
      $Response->Header->Additionals = 0;
      
      // Reset messages
      $Response->Question = array ();
      $Response->Answer = array ();
      $Response->Authority = array ();
      $Response->Additional = array ();
      
      // Make sure we keep the EDNS-Record if there is one
      if ($this->ednsRecord)
        $Response->Additional [] = $Response->ednsRecord = clone $this->ednsRecord;
      
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