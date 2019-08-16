<?PHP

  /**
   * qcEvents - SSH Stream Message
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  // Make sure we have GMP available
  if (!extension_loaded ('gmp') && (!function_exists ('dl') || !dl ('gmp.so'))) {
    trigger_error ('GMP required');

    return;
  }
  
  abstract class qcEvents_Stream_SSH_Message {
    const MESSAGE_TYPE = null;
    
    /* Type of this message */
    private $Type = null;
    
    // {{{ fromPacket
    /**
     * Try to parse a new message from a packet
     * 
     * @param string $Packet
     * 
     * @access public
     * @return qcEvents_Stream_SSH_Message
     **/
    public static function fromPacket ($Packet, $Length = null) : ?qcEvents_Stream_SSH_Message {
      // Make sure we know the length of the packet
      if ($Length === null)
        $Length = strlen ($Packet);
      
      // Make sure there is anything to read
      if ($Length < 1)
        return null;
      
      // Get the type of the message
      $Type = ord ($Packet [0]);
      
      // Create a new instance
      $Class = get_called_class ();
      
      if (constant ($Class . '::MESSAGE_TYPE') != $Type) {
        $Class = null;
        
        foreach (get_declared_classes () as $nClass)
          if (is_subclass_of ($nClass, __CLASS__) && (constant ($nClass . '::MESSAGE_TYPE') == $Type)) {
            $Class = $nClass;
            
            break;
          }
        
        if ($Class === null)
          return null;
      }
      
      $Instance = new $Class;
      $Instance->Type = $Type;
      
      // Try to unpack the payload
      if (!$Instance->unpack (substr ($Packet, 1), $Length - 1))
        return null;
      
      return $Instance;
    }
    // }}}
    
    // {{{ readBytes
    /**
     * Try to savely read bytes from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $Length
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return string
     **/
    public static function readBytes (&$Source, &$Offset, $Length, $sourceLength = null) {
      // Make sure we know the length of our source
      if ($sourceLength === null)
        $sourceLength = strlen ($Source);
      
      // Make sure there is enough to read
      if ($sourceLength - $Offset < $Length)
        return null;
      
      // Read from source
      $Data = substr ($Source, $Offset, $Length);
      
      // Move the offset
      $Offset += $Length;
      
      return $Data;
    }
    // }}}
    
    // {{{ writeBytes
    /**
     * Safely write a binary string of a given length
     * 
     * @param string $Bytes
     * @param int $Length;
     * 
     * @access public
     * @retrun string
     **/
    public static function writeBytes ($Bytes, $Length) {
      $aLength = strlen ($Bytes);
      
      if ($aLength < $Length)
        for ($i = $aLength; $i < $Length; $i++)
          $Bytes .= chr (0);
      elseif ($aLength > $Length)
        $Bytes = substr ($Bytes, 0, $Length);
      
      return $Bytes;
    }
    // }}}
    
    // {{{ readBoolean
    /**
     * Try to safely read a boolean from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return bool
     **/
    public static function readBoolean (&$Source, &$Offset, $sourceLength = null) {
      if (($Byte = self::readBytes ($Source, $Offset, 1, $sourceLength)) === null)
        return null;
      
      return (ord ($Byte) > 0);
    }
    // }}}
    
    // {{{ writeBoolean
    /**
     * Convert a boolean into binary
     * 
     * @param bool $Value
     * 
     * @access public
     * @return string
     **/
    public static function writeBoolean ($Value) {
      return chr ($Value ? 1 : 0);
    }
    // }}}
    
    // {{{ readUInt32
    /**
     * Try to safely read an unsigned 32-Bit integer from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return int
     **/
    public static function readUInt32 (&$Source, &$Offset, $sourceLength = null) {
      // Try to read the length of the string
      if (($Value = self::readBytes ($Source, $Offset, 4, $sourceLength)) === null)
        return null;
      
      // Unpack the integer
      $Value = unpack ('Nlength', $Value);
      
      // Return the result
      return $Value ['length'];
    }
    // }}}
    
    // {{{ writeUInt32
    /**
     * Convert an 32-Bit unsigned integer into binary
     * 
     * @param int $Value
     * 
     * @access public
     * @return string
     **/
    public static function writeUInt32 ($Value) {
      return pack ('N', $Value);
    }
    // }}}
    
    // {{{ readMPInt
    /**
     * Try to safely read a multi-precision-integer from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return mixed
     **/
    public static function readMPInt (&$Source, &$Offset, $sourceLength = null) {
      // Try to read as string first
      if (($Value = self::readString ($Source, $Offset, $sourceLength)) === null)
        return null;
      
      // Check the length of the value
      $Length = strlen ($Value);
      
      // Check wheter to use native representation
      if (($Length < PHP_INT_SIZE) ||
          (($Length == PHP_INT_SIZE) && !(ord ($Value [0]) & 0x80))) {
        // Pad the value
        if ($Length < PHP_INT_SIZE)
          $Value = str_repeat ("\x00", PHP_INT_SIZE - $Length) . $Value;
        
        $Value = unpack ((PHP_INT_SIZE == 4 ? 'N' : 'J') . 'value', $Value);
        
        return $Value ['value'];
      }
      
      // Return GMP-Instance
      return gmp_import ($Value, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
    }
    // }}}
    
    // {{{ writeMPInt
    /**
     * Convert a multi-precision-integer into binary
     * 
     * @param mixed $Value
     * 
     * @access public
     * @return string
     **/
    public static function writeMPInt ($Value) {
      // Check for a negative value
      $Negative = ($Value < 0);
      
      // Convert to binary
      if ($Value instanceof GMP)
        $Value = gmp_export ($Value, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
      else
        $Value = pack ('J', $Value);
      
      // Check the length of the binary
      $l = strlen ($Value);
      
      // Remove leading zeros
      while (($l > 0) && ($Value [0] == "\x00"))
        $Value = substr ($Value, 1, --$l);
      
      // Check if there is anything to output
      if ($l == 0)
        return self::writeUInt32 (0);
      
      // Make sure a positive value is positive
      if (!$Negative && (ord ($Value [0]) & 0x80)) {
        $Value = "\x00" . $Value;
        $l++;
      }
      
      // Write out
      return self::writeUInt32 ($l) . $Value;
    }
    // }}}
    
    // {{{ readString
    /**
     * Try to read a length-prefixed string from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return string
     **/
    public static function readString (&$Source, &$Offset, $sourceLength = null) {
      // Make sure we know the length of our source
      if ($sourceLength === null)
        $sourceLength = strlen ($Source);
      
      // Copy the offset to a local value
      $myOffset = $Offset;
      
      // Try to read the length of the string
      if (($Length = self::readUInt32 ($Source, $myOffset, $sourceLength)) === null)
        return null;
      
      // Make sure there is enough data to read
      if ($sourceLength - $myOffset < $Length)
        return null;
      
      // Read from the source
      $Data = substr ($Source, $myOffset, $Length);
      
      // Update the offset
      $Offset = $myOffset + $Length;
      
      return $Data;
    }
    // }}}
    
    // {{{ writeString
    /**
     * Convert a string into binary
     * 
     * @param string $String
     * 
     * @access public
     * @return string
     **/
    public static function writeString ($String) {
      return self::writeUInt32 (strlen ($String)) . $String;
    }
    // }}}
    
    // {{{ readNameList
    /**
     * Try to safely read a list of names from a source
     * 
     * @param string $Source
     * @param int $Offset
     * @param int $sourceLength (optional)
     * 
     * @access public
     * @return array
     **/
    public static function readNameList (&$Source, &$Offset, $sourceLength = null) {
      // Try to read the entire list
      if (($List = self::readString ($Source, $Offset, $sourceLength)) === null)
        return null;
      
      // Unpack the list
      return explode (',', $List);
    }
    // }}}
    
    // {{{ writeNameList
    /**
     * Convert an array of strings into binary
     * 
     * @param array $Names
     * 
     * @access public
     * @return string
     **/
    public static function writeNameList (array $Names) {
      return self::writeString (implode (',', $Names));
    }
    // }}}
    
    // {{{ toPacket
    /**
     * Generate a binary packet from this message
     * 
     * @access public
     * @return string
     **/
    public function toPacket () {
      // Make sure we have the type set
      if ($this->Type === null)
        $this->Type = $this::MESSAGE_TYPE;
      
      // Return the entire packet
      return chr ($this->Type) . $this->pack ();
    }
    // }}}
    
    // {{{ unpack
    /**
     * Try to unpack data from a packet into this message-instance
     * 
     * @param string $Packet
     * 
     * @access public
     * @return bool
     **/
    abstract public function unpack ($Packet);
    // }}}
    
    // {{{ pack
    /**
     * Convert this message into binary
     * 
     * @access public
     * @return string
     **/
    abstract public function pack ();
    // }}}
  }
  
  // Transport layer
  require_once ('qcEvents/Stream/SSH/Disconnect.php');		//   1
  require_once ('qcEvents/Stream/SSH/Ignore.php');		//   2
  require_once ('qcEvents/Stream/SSH/Unimplemented.php');	//   3
  require_once ('qcEvents/Stream/SSH/Debug.php');		//   4
  require_once ('qcEvents/Stream/SSH/ServiceRequest.php');	//   5
  require_once ('qcEvents/Stream/SSH/ServiceAccept.php');	//   6
  
  // Algorithm negotiation
  require_once ('qcEvents/Stream/SSH/KeyExchangeInit.php');	//  20
  require_once ('qcEvents/Stream/SSH/NewKeys.php');		//  21
  
  // Key-Exchange-Specific
  require_once ('qcEvents/Stream/SSH/KeyExchangeDHInit.php');	//  30
  require_once ('qcEvents/Stream/SSH/KeyExchangeDHReply.php');	//  31
  
  // User-Authentication
  require_once ('qcEvents/Stream/SSH/UserAuthRequest.php');	//  50
  require_once ('qcEvents/Stream/SSH/UserAuthFailure.php');	//  51
  require_once ('qcEvents/Stream/SSH/UserAuthSuccess.php');	//  52
  require_once ('qcEvents/Stream/SSH/UserAuthBanner.php');	//  53
  require_once ('qcEvents/Stream/SSH/UserAuthPublicKeyOK.php');	//  60
  
  // Connection-Protocol
  require_once ('qcEvents/Stream/SSH/GlobalRequest.php');	//  80
  require_once ('qcEvents/Stream/SSH/RequestSuccess.php');	//  81
  require_once ('qcEvents/Stream/SSH/RequestFailure.php');	//  82
  require_once ('qcEvents/Stream/SSH/ChannelOpen.php');		//  90
  require_once ('qcEvents/Stream/SSH/ChannelConfirmation.php');	//  91
  require_once ('qcEvents/Stream/SSH/ChannelRejection.php');	//  92
  require_once ('qcEvents/Stream/SSH/ChannelWindowAdjust.php');	//  93
  require_once ('qcEvents/Stream/SSH/ChannelData.php');		//  94
  require_once ('qcEvents/Stream/SSH/ChannelExtendedData.php');	//  95
  require_once ('qcEvents/Stream/SSH/ChannelEnd.php');		//  96
  require_once ('qcEvents/Stream/SSH/ChannelClose.php');	//  97
  require_once ('qcEvents/Stream/SSH/ChannelRequest.php');	//  98
  require_once ('qcEvents/Stream/SSH/ChannelSuccess.php');	//  99
  require_once ('qcEvents/Stream/SSH/ChannelFailure.php');	// 100

?>