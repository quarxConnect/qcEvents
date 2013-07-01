<?PHP

  /**
   * qcEvents - DNS Messages
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
    const TYPE_A = 0x01;	// RFC 1035
    const TYPE_NS = 0x02;	// RFC 1035
    const TYPE_MD = 0x03;	// RFC 1035, Obsoleted
    const TYPE_MF = 0x04;	// RFC 1035, Obsoleted
    const TYPE_CNAME = 0x05;	// RFC 1035
    const TYPE_SOA = 0x06;	// RFC 1035
    const TYPE_MB = 0x07;	// RFC 1035, Experimental
    const TYPE_MG = 0x08;	// RFC 1035, Experimental
    const TYPE_MR = 0x09;	// RFC 1035, Experimental
    const TYPE_NULL = 0x0A;	// RFC 1035, Experimental
    const TYPE_WKS = 0x0B;	// RFC 1035
    const TYPE_PTR = 0x0C;	// RFC 1035
    const TYPE_HINFO = 0x0D;	// RFC 1035
    const TYPE_MINFO = 0x0E;	// RFC 1035
    const TYPE_MX = 0x0F;	// RFC 1035
    const TYPE_TXT = 0x10;	// RFC 1035
    const TYPE_AAAA = 0x1C;	// RFC 3596
    const TYPE_SRV = 0x21;	// RFC 2782
    
    const TYPE_ANY = 0xFF;	// RFC 1035
    
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
          $nextOffset = (($qLength - 0xC0) << 8) + ord ($Data [$Offset++]);
          $Label .= self::getLabel ($Data, $nextOffset);
          
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
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function isQuery ($Toggle = null) {
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
    
    // {{{ addQuestion
    /**
     * Add a question to this message
     * 
     * @param qcEvents_Socket_Stream_DNS_Question $Question
     * 
     * @access public
     * @return void
     **/
    public function addQuestion (qcEvents_Socket_Stream_DNS_Question $Question) {
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
    
    // {{{ tuncate
    /**
     * Try to truncate data from this query
     * 
     * @access public
     * @return bool
     **/
    public function tuncate () {
      // Try to remove all questions first (if we are the answer)
      if (!($q = $this->isQuery ()) && (count ($this->Question) > 0))
        $this->Question = array ();
      
      // ... or additional informations
      elseif (count ($this->Additional) > 0)
        array_pop ($this->Additional);
      
      // ... maybe no one cares about authorities
      elseif (count ($this->Authority) > 0)
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
      
      // Reset the counters
      $Response->Header->RCode = qcEvents_Socket_Stream_DNS_Header::ERROR_NONE;
      $Response->Header->Questions = 0;
      $Response->Header->Answers = 0;
      $Response->Header->Authorities = 0;
      $Response->Header->Additionals = 0;
      
      // Reset messages
      $Response->Question = array ();
      $Response->Answer = array ();
      $Response->Authority = array ();
      $Response->Additional = array ();
      
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