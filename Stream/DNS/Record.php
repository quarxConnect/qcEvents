<?PHP

  /**
   * qcEvents - DNS Resource Record
   * Copyright (C) 2014-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Stream/DNS/Record/TSIG.php');
  
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
      qcEvents_Stream_DNS_Message::TYPE_TSIG  => 'qcEvents_Stream_DNS_Record_TSIG',
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
     * @param string $dnsData
     * @param int $dataOffset
     * @parma int $dataLength (optional)
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Record
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public static function fromString (&$dnsData, &$dataOffset, $dataLength = null) : qcEvents_Stream_DNS_Record {
      // Check if there is enough data available
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if ($dataLength < $dataOffset + 10)
        throw new LengthException ('DNS-Record too short');
      
      // Retrive the label of this record
      $recordLabel = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset);
     
      // Retrive type, class and TTL
      if ($dataLength < $dataOffset + 8)
        throw new LengthException ('DNS-Record too short');
      
      $recordType  = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordClass = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordTTL   = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      
      // Retrive the payload
      $recordDataLength = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordDataOffset = $dataOffset;
      
      if ($dataLength < $dataOffset + $recordDataLength)
        throw new LengthException ('DNS-Record too short');
      
      $recordPayload = substr ($dnsData, $dataOffset, $recordDataLength);
      $dataOffset += $recordDataLength;
      
      // Create a new record
      if (isset (self::$Records [$recordType]))
        $recordImplementationClass = self::$Records [$recordType];
      else
        $recordImplementationClass = get_called_class ();
      
      $dnsRecord = new $recordImplementationClass ($recordLabel, $recordTTL, $recordType, $recordClass);
      
      // Try to parse the payload
      $dnsRecord->Payload = $recordPayload;
      
      if ($recordDataLength > 0)
        $dnsRecord->parsePayload ($dnsData, $recordDataOffset, $recordDataOffset + $recordDataLength);
      
      return $dnsRecord;
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
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' ' . qcEvents_Stream_DNS_Message::getTypeName ($this->getType ()) . ' ' . bin2hex ($this->Payload);
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
    
    // {{{ getClassName
    /**
     * Retrive a human readable representation of our class
     * 
     * @access protected
     * @retrun string
     **/
    protected function getClassName () {
      return qcEvents_Stream_DNS_Message::getClassName ($this->getClass ());
    }
    // }}}
    
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
     * @param string $dnsData
     * @param int $dataOffset
     * @param int $dataLength (optional)
     * 
     * @access public
     * @return void
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public function parse ($dnsData, &$dataOffset, $dataLength = null) {
      // Check if there is enough data available
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if ($dataLength < $dataOffset + 10)
        throw new LengthException ('DNS-Record too short');
      
      // Retrive the label of this record
      $this->Label = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset);
      
      // Retrive type, class and TTL
      $this->Type  = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->Class = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->TTL   = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      
      // Retrive the payload
      $recordDataLength = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->Payload = substr ($dnsData, $dataOffset, $recordDataLength);
      $recordDataOffset = $dataOffset;
      $dataOffset += $recordDataLength;
      
      if ($recordDataLength == 0)
        return;
      
      return $this->parsePayload ($dnsData, $recordDataOffset, $recordDataOffset + $recordDataLength);
    }
    // }}}
    
    // {{{ parseInt
    /**
     * Try to read an integer of an arbitrary size from binary data
     * 
     * @param int $intSize
     * @param string $inputData
     * @param int $inputOffset
     * @param int $inputLength (optional)
     * 
     * @access protected
     * @return int
     **/
    protected static function parseInt ($intSize, &$inputData, &$inputOffset, $inputLength = null) {
      if ($inputLength === null)
        $inputLength = strlen ($inputData);
      
      if ($inputLength < $inputOffset + $intSize)
        throw new LengthException ('Input-Data too short to read integer');
      
      $resultValue = 0;
      
      for ($i = 0; $i < $intSize; $i++)
        $resultValue = ($resultValue << 8) | ord ($inputData [$inputOffset++]);
      
      return $resultValue;
    }
    // }}}
    
    // {{{ parseInt16
    /**
     * Read a 16-bit integer from binary
     * 
     * @param string $inputData
     * @param int $inputOffset
     * @param int $inputLength (optional)
     * 
     * @access protected
     * @return int
     **/
    protected static function parseInt16 (&$inputData, &$inputOffset, $inputLength = null) {
      return static::parseInt (2, $inputData, $inputOffset, $inputLength);
    }
    // }}}
    
    // {{{ parseInt32
    /**
     * Read a 32-bit integer from binary
     * 
     * @param string $inputData
     * @param int $inputOffset
     * @param int $inputLength (optional)
     * 
     * @access protected
     * @return int
     **/
    protected static function parseInt32 (&$inputData, &$inputOffset, $inputLength = null) {
      return static::parseInt (4, $inputData, $inputOffset, $inputLength);
    }
    // }}}
    
    // {{{ parseInt48
    /**
     * Try to read a 48-bit integer from binary data
     * 
     * @param string $inputData
     * @param int $inputOffset
     * @param int $inputLength (optional)
     * 
     * @access protected
     + @return int
     **/
    protected static function  parseInt48 (&$inputData, &$inputOffset, $inputLength = null) {
      return self::parseInt (6, $inputData, $inputOffset, $inputLength);
    }
    // }}}
    
    // {{{ parsePayload
    /**
     * Parse a given payload
     * 
     * @param string $dnsData
     * @param int $dataOffset
     * @param int $dataLength (optional)
     * 
     * @access public
     * @return void
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
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
          $this->Hostname = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset);
          break;
        
        // Two hostnames
        case qcEvents_Stream_DNS_Message::TYPE_MINFO:
          #$this->Mailbox = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset);
          #$this->errorMailbox = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset);
          
          #break;
        
        // Specials
        case qcEvents_Stream_DNS_Message::TYPE_HINFO:
          // CPU / OS Character-Strings
      }
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
        $this::writeInt16 ($this->Type) .
        $this::writeInt16 ($this->getClass ()) .
        $this::writeInt32 ($this->TTL);
      
      // Retrive the payload
      if (($Payload = $this->buildPayload ($Offset + strlen ($Data) + 2, $Labels)) === false)
        return false;
      
      // Append the payload
      $Data .= $this::writeInt16 (strlen ($Payload)) . $Payload;
      
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
    
    // {{{ writeInt16
    /**
     * Create binary representation of a 16-bit-Integer
     * 
     * @param int $intValue
     * 
     * @access protected
     * @return string
     **/
    protected static function writeInt16 ($intValue) {
      return pack ('n', $intValue);
    }
    // }}}
    
    // {{{ writeInt32
    /**
     * Create binary representation of a 32-bit-Integer
     * 
     * @param int $intValue
     * 
     * @access protected
     * @return string
     **/
    protected static function writeInt32 ($intValue) {
      return pack ('N', $intValue);
    }
    // }}}
    
    // {{{ writeInt48
    /**
     * Create binary representation of a 48-bit-Integer
     * 
     * @param int $intValue
     * 
     * @access protected
     * @return string
     **/
    protected static function writeInt48 ($intValue) {
      return pack ('Nn', $intValue >> 16, $intValue & 0xFFFF);
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
    protected static function buildInt16 ($Value) {
      return static::writeInt16 ($Value);
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
    protected static function buildInt32 ($Value) {
      return static::writeInt32 ($Value);
    }
    // }}}
  }

?>