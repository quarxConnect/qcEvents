<?PHP

  /**
   * qcEvents - DNS Resource Record
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/DNS/Message.php');
  require_once ('qcEvents/Stream/DNS/Record/A.php');
  require_once ('qcEvents/Stream/DNS/Record/NS.php');
  require_once ('qcEvents/Stream/DNS/Record/CNAME.php');
  require_once ('qcEvents/Stream/DNS/Record/SOA.php');
  require_once ('qcEvents/Stream/DNS/Record/PTR.php');
  require_once ('qcEvents/Stream/DNS/Record/MX.php');
  require_once ('qcEvents/Stream/DNS/Record/TXT.php');
  require_once ('qcEvents/Stream/DNS/Record/AAAA.php');
  require_once ('qcEvents/Stream/DNS/Record/SRV.php');
  require_once ('qcEvents/Stream/DNS/Record/EDNS.php');
  
  class qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = null;
    const DEFAULT_CLASS = null;
    const DEFAULT_TTL = null;
    
    /**
     * Registry for record-classes
     **/
    private static $Records = array (
      qcEvents_Stream_DNS_Message::TYPE_A     => 'qcEvents_Stream_DNS_Record_A',
      qcEvents_Stream_DNS_Message::TYPE_NS    => 'qcEvents_Stream_DNS_Record_NS',
      qcEvents_Stream_DNS_Message::TYPE_CNAME => 'qcEvents_Stream_DNS_Record_CNAME',
      qcEvents_Stream_DNS_Message::TYPE_SOA   => 'qcEvents_Stream_DNS_Record_SOA',
      qcEvents_Stream_DNS_Message::TYPE_PTR   => 'qcEvents_Stream_DNS_Record_PTR',
      qcEvents_Stream_DNS_Message::TYPE_MX    => 'qcEvents_Stream_DNS_Record_MX',
      qcEvents_Stream_DNS_Message::TYPE_TXT   => 'qcEvents_Stream_DNS_Record_TXT',
      qcEvents_Stream_DNS_Message::TYPE_AAAA  => 'qcEvents_Stream_DNS_Record_AAAA',
      qcEvents_Stream_DNS_Message::TYPE_SRV   => 'qcEvents_Stream_DNS_Record_SRV',
      qcEvents_Stream_DNS_Message::TYPE_OPT   => 'qcEvents_Stream_DNS_Record_EDNS',
    );
    
    /**
     * [QName] The Label that is asked for
     **/
    private $Label = '';
    
    /**
     * [QType] Type of this DNS-RR
     **/
    private $Type = qcEvents_Stream_DNS_Message::TYPE_ANY;
    
    /**
     * [QClass] Class of this DNS-RR
     **/
    private $Class = qcEvents_Stream_DNS_Message::CLASS_INTERNET;
    
    /**
     * [TTL] Time-to-live of this DNS-RR
     **/
    private $TTL = 0;
    
    /**
     * [RData] Payload of this DNS-RR
     **/
    public $Payload = '';
    
    // {{{ fromString
    /**
     * Try to create a new DNS-Record from string
     * 
     * @param string $Data
     * @param int $Offset
     * @parma int $Length (optional)
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Record
     **/
    public static function fromString ($Data, &$Offset, $Length = null) {
      // Check if there is enough data available
      if ($Length === null)
        $Length = strlen ($Data);
      
      if ($Length - $Offset < 10)
        return false;
      
      // Retrive the label of this record
      $Label = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
     
      // Retrive type, class and TTL
      $Type  = self::parseInt16 ($Data, $Offset);
      $Class = self::parseInt16 ($Data, $Offset);
      $TTL   = self::parseInt32 ($Data, $Offset);
      
      // Retrive the payload
      $rdLength = self::parseInt16 ($Data, $Offset);
      $Payload = substr ($Data, $Offset, $rdLength);
      $pOffset = $Offset;
      $Offset += $rdLength;
      
      // Create a new record
      if (isset (self::$Records [$Type]))
        $objClass = self::$Records [$Type];
      else
        $objClass = get_called_class ();
      
      $Record = new $objClass ($Label, $TTL, $Type, $Class);
      
      // Try to parse the payload
      $Record->Payload = $Payload;
      
      if (!$Record->parsePayload ($Data, $pOffset, $rdLength))
        return false;
      
      return $Record;
    }
    // }}}
    
    // {{{ registerRecordClass
    /**
     * Register a class for a given record-type
     * 
     * @param int $Type
     * @param string $Class
     * 
     * @access public
     * @return bool
     **/
    public static function registerRecordClass ($Type, $Class) {
      // Make sure that this is a child of ourself
      if (!is_a ($Class, __CLASS__, true))
        return false;
      
      // Store the classname
      self::$Records [$Type] = $Class;
      
      return true;
    }
    // }}}
    
    
    // {{{ __construct
    /**
     * Create a new DNS-Record
     * 
     * @param string $Label (optional)
     * @paran int $TTL (optional)
     * @param enum $Type (optional)
     * @param enum $Class (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Label = null, $TTL = null, $Type = null, $Class = null) {
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;
      
      if ($Class === null)
        $Class = $this::DEFAULT_CLASS;
      
      if ($TTL === null)
        $TTL = $this::DEFAULT_TTL;
      
      if ($Label !== null)
        $this->setLabel ($Label);
      
      if ($Type !== null)
        $this->setType ($Type);
      
      if ($Class !== null)
        $this->setClass ($Class);
      
      if ($TTL !== null)
        $this->setTTL ($TTL);
    }
    // }}}
    
    // {{{ getLabel
    /**
     * Retrive the label of this record
     * 
     * @access public
     * @return string
     **/
    public function getLabel () {
      return $this->Label;
    }
    // }}}
    
    // {{{ setLabel
    /**
     * Set the label of this record
     * 
     * @param string $Label
     * 
     * @access public
     * @return bool
     **/
    public function setLabel ($Label) {
      $this->Label = $Label;
      
      return true;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this record
     * 
     * @access public
     * @return enum
     **/
    public function getType () {
      return $this->Type;
    }
    // }}}
    
    // {{{ setType
    /**
     * Set the type of this record
     * 
     * @param enum $Type
     * 
     * @access public
     * @return bool
     **/
    public function setType ($Type) {
      # TODO: Validate the type
      
      $this->Type = $Type;
      
      return true;
    }
    // }}}
    
    // {{{ getClass
    /**
     * Retrive the class of this record
     * 
     * @access public
     * @return enum
     **/
    public function getClass () {
      return $this->Class;
    }
    // }}}
    
    protected function getClassName () {
      switch ($this->Class) {
        case qcEvents_Stream_DNS_Message::CLASS_INTERNET:
          return 'IN';
      }
      
      return $this->Class;
    }
    
    // {{{ setClass
    /**
     * Set the class of this record
     * 
     * @param enum $Class
     * 
     * @access public
     * @return bool
     **/
    public function setClass ($Class) {
      # TODO: Validate the class
      
      $this->Class = $Class;
      
      return true;
    }
    // }}}
    
    // {{{ getTTL
    /**
     * Retrive the time-to-live of this record
     * 
     * @access public
     * @return int
     **/
    public function getTTL () {
      return $this->TTL;
    }
    // }}}
    
    // {{{ setTTL
    /**
     * Set the TTL of this record
     * 
     * @param int $TTL
     * 
     * @access public
     * @return bool
     **/
    public function setTTL ($TTL) {
      if (($this->TTL < 0) || ($this->TTL > 0xFFFFFFFF))
        return false;
      
      $this->TTL = (int)$TTL;
      
      return true;
    }
    // }}}
    
    // {{{ getPayload
    /**
     * Retrive the entire payload-blob of this record
     * 
     * @access public
     * @return string
     **/
    public function getPayload () {
      return $this->Payload;
    }
    // }}}
    
    
    // {{{ parse
    /**
     * Parse binary data into this object
     * 
     * @param string $Data
     * @param int $Offset
     * @param int $Length (optional)
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data, &$Offset, $Length = null) {
      // Check if there is enough data available
      if ($Length === null)
        $Length = strlen ($Data);
      
      if ($Length - $Offset < 10)
        return false;
      
      // Retrive the label of this record
      $this->Label = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
      
      // Retrive type, class and TTL
      $this->Type  = self::parseInt16 ($Data, $Offset);
      $this->Class = self::parseInt16 ($Data, $Offset);
      $this->TTL   = self::parseInt32 ($Data, $Offset);
      
      // Retrive the payload
      $rdLength = self::parseInt16 ($Data, $Offset);
      $this->Payload = substr ($Data, $Offset, $rdLength);
      $pOffset = $Offset;
      $Offset += $rdLength;
      
      return $this->parsePayload ($Data, $pOffset, $rdLength);
    }
    // }}}
    
    // {{{ parseInt16
    /**
     * Read a 16-bit integer from binary
     * 
     * @param string $Data
     * @param int $Offset
     * 
     * @access protected
     * @return int
     **/
    protected static function parseInt16 ($Data, &$Offset) {
      return (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
    }
    // }}}
    
    // {{{ parseInt32
    /**
     * Read a 32-bit integer from binary
     * 
     * @param string $Data
     * @param int $Offset
     * 
     * @access protected
     * @return int
     **/
    protected static function parseInt32 ($Data, &$Offset) {
      return (ord ($Data [$Offset++]) << 24) | (ord ($Data [$Offset++]) << 16) |
             (ord ($Data [$Offset++]) <<  8) |  ord ($Data [$Offset++]);
    }
    // }}}
    
    // {{{ parsePayload
    /**
     * Parse a given payload
     * 
     * @param string $Data
     * @param int $Offset (optional)
     * @param int $Length (optional)
     * 
     * @access public
     * @return bool
     **/
    public function parsePayload ($Data, $Offset = 0, $Length = null) {
      if ($Length === null)
        $Length = strlen ($Data) - $Offset;
      
      // Handle the payload
      switch ($this->Type) {
        #case qcEvents_Stream_DNS_Message::TYPE_WKS:
        #   TODO: Address <8-bit protocol> <Bitmask>
        #   $this->Address = ord ($this->Payload [0]) . '.' . ord ($this->Payload [1]) . '.' . ord ($this->Payload [2]) . '.' . ord ($this->Payload [3]);
        #   break;
        
        // Hostnames
        case qcEvents_Stream_DNS_Message::TYPE_MB:
        case qcEvents_Stream_DNS_Message::TYPE_MD:
        case qcEvents_Stream_DNS_Message::TYPE_MF:
        case qcEvents_Stream_DNS_Message::TYPE_MG:
        case qcEvents_Stream_DNS_Message::TYPE_MR:
          $this->Hostname = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
          break;
        
        // Two hostnames
        case qcEvents_Stream_DNS_Message::TYPE_MINFO:
          #$this->Mailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
          #$this->errorMailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
          
          #break;
        
        // Specials
        case qcEvents_Stream_DNS_Message::TYPE_HINFO:
          // CPU / OS Character-Strings
      }
      
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
        qcEvents_Stream_DNS_Message::setLabel ($this->Label, $Offset, $Labels) .
        self::buildInt16 ($this->Type) .
        self::buildInt16 ($this->Class) . 
        self::buildInt32 ($this->TTL);
      
      // Retrive the payload
      if (($Payload = $this->buildPayload ($Offset + strlen ($Data) + 2, $Labels)) === false)
        return false;
      
      // Append the payload
      $Data .= self::buildInt16 (strlen ($Payload)) . $Payload;
      
      return $Data;
    }
    // }}}
    
    // {{{ buildPayload
    /**
     * Retrive the payload of this record
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function buildPayload ($Offset, &$Labels) {
      return $this->Payload;
    }
    // }}}
    
    // {{{ buildInt16
    /**
     * Create a binary representation of a 16-bit integer
     * 
     * @param int $Value
     * 
     * @access protected
     * @return string
     **/
    protected function buildInt16 ($Value) {
      return chr (($Value & 0xFF00) >> 8) . chr ($Value & 0xFF);
    }
    // }}}
    
    // {{{ buildInt32
    /**
     * Create a binary representation of a 32-bit integer
     * 
     * @param int $Value
     * 
     * @access protected
     * @return string
     **/
    protected function buildInt32 ($Value) {
      return
        chr (($Value & 0xFF000000) >> 24) . chr (($Value & 0x00FF0000) >> 16) .
        chr (($Value & 0x0000FF00) >>  8) . chr  ($Value & 0x000000FF);
    }
    // }}}
  }

?>