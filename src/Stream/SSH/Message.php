<?PHP

  /**
   * qcEvents - SSH Stream Message
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Stream\SSH;
  
  abstract class Message {
    protected const MESSAGE_TYPE = null;
    
    private static $messageTypes = [
      // Transport layer
        1 => Disconnect::class,
        2 => Ignore::class,
        3 => Unimplemented::class,
        4 => Debug::class,
        5 => ServiceRequest::class,
        6 => ServiceAccept::class,
      
      // Algorithm negotiation
       20 => KeyExchangeInit::class,
       21 => NewKeys::class,
      
      // Key-Exchange-Specific
       30 => KeyExchangeDHInit::class,
       31 => KeyExchangeDHReply::class,
      
      // User-Authentication
       50 => UserAuthRequest::class,
       51 => UserAuthFailure::class,
       52 => UserAuthSuccess::class,
       53 => UserAuthBanner::class,
       60 => UserAuthPublicKeyOK::class,
      
      // Connection-Protocol
       80 => GlobalRequest::class,
       81 => RequestSuccess::class,
       82 => RequestFailure::class,
       90 => ChannelOpen::class,
       91 => ChannelConfirmation::class,
       92 => ChannelRejection::class,
       93 => ChannelWindowAdjust::class,
       94 => ChannelData::class,
       95 => ChannelExtendedData::class,
       96 => ChannelEnd::class,
       97 => ChannelClose::class,
       98 => ChannelRequest::class,
       99 => ChannelSuccess::class,
      100 => ChannelFailure::class,
    ];
    
    /* Type of this message */
    private $messageType = null;
    
    // {{{ fromPacket
    /**
     * Try to parse a new message from a packet
     * 
     * @param string $packetData
     * @param int $packetLength (optional)
     * 
     * @access public
     * @return Message
     **/
    public static function fromPacket (string $packetData, int $packetLength = null) : ?Message {
      // Make sure we know the length of the packet
      if ($packetLength === null)
        $packetLength = strlen ($packetData);
      
      // Make sure there is anything to read
      if ($packetLength < 1)
        return null;
      
      // Get the type of the message
      $messageType = ord ($packetData [0]);
      
      // Create a new instance
      $messageClass = get_called_class ();
      
      if (constant ($messageClass . '::MESSAGE_TYPE') != $messageType) {
        if (!isset (self::$messageTypes [$messageType])) {
          $messageClass = null;
          
          foreach (get_declared_classes () as $declaredClass)
            if (
              is_subclass_of ($declaredClass, __CLASS__) &&
              (constant ($declaredClass . '::MESSAGE_TYPE') == $messageType)
            ) {
              $messageClass = $declaredClass;
              
              break;
            }
          
          if ($messageClass === null)
            return null;
        } else
          $messageClass = self::$messageTypes [$messageType];
      }
      
      $messageInstance = new $messageClass ();
      $messageInstance->messageType = $messageType;
      
      // Try to unpack the payload
      if (!$messageInstance->unpack (substr ($packetData, 1), $packetLength - 1))
        return null;
      
      return $messageInstance;
    }
    // }}}
    
    // {{{ readBytes
    /**
     * Try to savely read bytes from a source
     * 
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $targetLength
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return string
     **/
    public static function readBytes (string &$sourceData, int &$sourceOffset, int $targetLength, int $sourceLength = null) : ?string {
      // Make sure we know the length of our source
      if ($sourceLength === null)
        $sourceLength = strlen ($sourceData);
      
      // Make sure there is enough to read
      if ($sourceLength - $sourceOffset < $targetLength)
        return null;
      
      // Read from source
      $targetData = substr ($sourceData, $sourceOffset, $targetLength);
      
      // Move the offset
      $sourceOffset += $targetLength;
      
      return $targetData;
    }
    // }}}
    
    // {{{ writeBytes
    /**
     * Safely write a binary string of a given length
     * 
     * @param string $sourceBytes
     * @param int $targetLength;
     * 
     * @access public
     * @retrun string
     **/
    public static function writeBytes (string $sourceBytes, int $targetLength) : string {
      $sourceLength = strlen ($sourceBytes);
      
      if ($sourceLength < $targetLength)
        for ($i = $sourceLength; $i < $targetLength; $i++)
          $sourceBytes .= chr (0);
      elseif ($sourceLength > $targetLength)
        $sourceBytes = substr ($sourceBytes, 0, $targetLength);
      
      return $sourceBytes;
    }
    // }}}
    
    // {{{ readBoolean
    /**
     * Try to safely read a boolean from a source
     * 
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return bool
     **/
    public static function readBoolean (string &$sourceData, int &$sourceOffset, int $sourceLength = null) : ?bool {
      if (($byteValue = self::readBytes ($sourceData, $sourceOffset, 1, $sourceLength)) === null)
        return null;
      
      return (ord ($byteValue) > 0);
    }
    // }}}
    
    // {{{ writeBoolean
    /**
     * Convert a boolean into binary
     * 
     * @param bool $fromValue
     * 
     * @access public
     * @return string
     **/
    public static function writeBoolean (bool $fromValue) : string {
      return chr ($fromValue ? 1 : 0);
    }
    // }}}
    
    // {{{ readUInt32
    /**
     * Try to safely read an unsigned 32-Bit integer from a source
     * 
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return int
     **/
    public static function readUInt32 (string &$sourceData, int &$sourceOffset, int $sourceLength = null) : ?int {
      // Try to read the length of the string
      if (($byteValue = self::readBytes ($sourceData, $sourceOffset, 4, $sourceLength)) === null)
        return null;
      
      // Unpack the integer
      $byteValue = unpack ('Nvalue', $byteValue);
      
      // Return the result
      return $byteValue ['value'];
    }
    // }}}
    
    // {{{ writeUInt32
    /**
     * Convert an 32-Bit unsigned integer into binary
     * 
     * @param int $fromValue
     * 
     * @access public
     * @return string
     **/
    public static function writeUInt32 (int $fromValue) : string {
      return pack ('N', $fromValue);
    }
    // }}}
    
    // {{{ readMPInt
    /**
     * Try to safely read a multi-precision-integer from a source
     * 
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return int|GMP|null
     **/
    public static function readMPInt (string &$sourceData, int &$sourceOffset, int $sourceLength = null) {
      // Try to read as string first
      if (($valueBytes = self::readString ($sourceData, $sourceOffset, $sourceLength)) === null)
        return null;
      
      $valueLength = strlen ($valueBytes);
      
      // Check wheter to use native representation
      if (
        ($valueLength < PHP_INT_SIZE) ||
        (($valueLength == PHP_INT_SIZE) && !(ord ($valueBytes [0]) & 0x80))
      ) {
        // Pad the value
        if ($valueLength < PHP_INT_SIZE)
          $valueBytes = str_repeat ("\x00", PHP_INT_SIZE - $valueLength) . $valueBytes;
        
        $valueBytes = unpack ((PHP_INT_SIZE == 4 ? 'N' : 'J') . 'value', $valueBytes);
        
        return $valueBytes ['value'];
      }
      
      // Return GMP-Instance
      return gmp_import ($valueBytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
    }
    // }}}
    
    // {{{ writeMPInt
    /**
     * Convert a multi-precision-integer into binary
     * 
     * @param int|GMP $fromValue
     * 
     * @access public
     * @return string
     **/
    public static function writeMPInt ($fromValue) : string {
      // Check for a negative value
      $isNegative = ($fromValue < 0);
      
      // Convert to binary
      if ($fromValue instanceof \GMP)
        $fromValue = gmp_export ($fromValue, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
      else
        $fromValue = pack ('J', $fromValue);
      
      // Check the length of the binary
      $sourceLength = strlen ($fromValue);
      
      // Remove leading zeros
      while (($sourceLength > 0) && ($fromValue [0] == "\x00"))
        $fromValue = substr ($fromValue, 1, --$sourceLength);
      
      // Check if there is anything to output
      if ($sourceLength == 0)
        return self::writeUInt32 (0);
      
      // Make sure a positive value is positive
      if (!$isNegative && (ord ($fromValue [0]) & 0x80)) {
        $fromValue = "\x00" . $fromValue;
        $sourceLength++;
      }
      
      // Write out
      return self::writeUInt32 ($sourceLength) . $fromValue;
    }
    // }}}
    
    // {{{ readString
    /**
     * Try to read a length-prefixed string from a source
     * 
     * @param string $sourceData
     * @param int $soruceOffset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return string
     **/
    public static function readString (string &$sourceData, int &$sourceOffset, int $sourceLength = null) :?string {
      // Make sure we know the length of our source
      if ($sourceLength === null)
        $sourceLength = strlen ($sourceData);
      
      // Copy the offset to a local value
      $myOffset = $sourceOffset;
      
      // Try to read the length of the string
      if (($stringLength = self::readUInt32 ($sourceData, $myOffset, $sourceLength)) === null)
        return null;
      
      // Make sure there is enough data to read
      if ($sourceLength - $myOffset < $stringLength)
        return null;
      
      // Read from the source
      $stringData = substr ($sourceData, $myOffset, $stringLength);
      
      // Update the offset
      $sourceOffset = $myOffset + $stringLength;
      
      return $stringData;
    }
    // }}}
    
    // {{{ writeString
    /**
     * Convert a string into binary
     * 
     * @param string $stringData
     * 
     * @access public
     * @return string
     **/
    public static function writeString (string $stringData) : string {
      return self::writeUInt32 (strlen ($stringData)) . $stringData;
    }
    // }}}
    
    // {{{ readNameList
    /**
     * Try to safely read a list of names from a source
     * 
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return array
     **/
    public static function readNameList (string &$sourceData, int &$sourceOffset, int $sourceLength = null) : ?array {
      // Try to read the entire list
      if (($nameList = self::readString ($sourceData, $sourceOffset, $sourceLength)) === null)
        return null;
      
      // Unpack the list
      return explode (',', $nameList);
    }
    // }}}
    
    // {{{ writeNameList
    /**
     * Convert an array of strings into binary
     * 
     * @param array $nameList
     * 
     * @access public
     * @return string
     **/
    public static function writeNameList (array $nameList) : string {
      return self::writeString (implode (',', $nameList));
    }
    // }}}
    
    // {{{ toPacket
    /**
     * Generate a binary packet from this message
     * 
     * @access public
     * @return string
     **/
    public function toPacket () : string {
      // Make sure we have the type set
      if ($this->messageType === null)
        $this->messageType = $this::MESSAGE_TYPE;
      
      // Return the entire packet
      return chr ($this->messageType) . $this->pack ();
    }
    // }}}
    
    // {{{ unpack
    /**
     * Try to unpack data from a packet into this message-instance
     * 
     * @param string $packetData
     * 
     * @access public
     * @return bool
     **/
    abstract public function unpack (string $packetData) : bool;
    // }}}
    
    // {{{ pack
    /**
     * Convert this message into binary
     * 
     * @access public
     * @return string
     **/
    abstract public function pack () : string;
    // }}}
  }
