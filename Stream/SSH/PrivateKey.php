<?PHP

  /**
   * qcEvents - SSH Private Key
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
  
  require_once ('qcEvents/File.php');
  require_once ('qcEvents/Stream/SSH/Message.php');
  require_once ('qcEvents/Stream/SSH/PublicKey.php');
  
  class qcEvents_Stream_SSH_PrivateKey extends qcEvents_Stream_SSH_PublicKey {
    /* RSA Private Exponent */
    private $rsaPrivateExponent = null;
    
    /* RSA Primes */
    private $rsaPrime1 = null;
    private $rsaPrime2 = null;
    
    // {{{ loadFromString
    /**
     * Try to load a private key from string
     * 
     * @param string $String
     * 
     * @access public
     * @return qcEvents_Stream_SSH_PublicKey
     **/
    public static function loadFromString ($String) : ?qcEvents_Stream_SSH_PublicKey {
      // Check for something PEM-Encoded and convert to DER
      if (substr ($String, 0, 10) == '-----BEGIN') {
        $Offset = strpos ($String, "\n") + 1;
        $String = base64_decode (substr ($String, $Offset, strpos ($String, '----END') - $Offset));
      }
      
      // Check for DER-Encoded something
      $Offset = 0;
      $Length = strlen ($String);
      $asnType = 0;
      
      if ((($asnSequence = self::asn1read ($String, $Offset, $asnType, $Length)) !== null) &&
          ($asnType == 0x30) &&
          ($Offset == $Length)) {
        // Split the sequence
        $Offset = 0;
        $Length = strlen ($asnSequence);
        $Items = array ();
        unset ($String);
        
        
        while ($Offset < $Length) {
          // Try to read the next item from the sequence
          if (($asnItem = self::asn1read ($asnSequence, $Offset, $asnType, $Length)) === null)
            return null;
          
          $Items [] = array ($asnType, $asnItem);
        }
        
        // Check for RSA-Key
        if ((count ($Items) == 9) || (count ($Items) == 10)) {
          // Sanity-Check the types
          if (($Items [1][0] != 0x02) || ($Items [2][0] != 0x02) || ($Items [3][0] != 0x02) || ($Items [4][0] != 0x02) || ($Items [5][0] != 0x02))
            return null;
          
          // Create the key
          $Instance = new static ();
          $Instance->Type = $Instance::TYPE_RSA;
          $Instance->rsaModulus = gmp_import ($Items [1][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $Instance->rsaPublicExponent = gmp_import ($Items [2][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $Instance->rsaPrivateExponent = gmp_import ($Items [3][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $Instance->rsaPrime1 = gmp_import ($Items [4][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $Instance->rsaPrime2 = gmp_import ($Items [5][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          
          return $Instance;
        // Check for DSS-Key
        } elseif (count ($Items) == 6) {
          # TODO
        }
        
        return null;
      }
      
      // Check for SSH private-key
      $Offset = 0;
      $dbType = qcEvents_Stream_SSH_Message::readString ($String, $Offset, $Length);
      
      if ($dbType === 'ssh-rsa') {
        $Instance = new static ();
        $Instance->Type = $Instance::TYPE_RSA;
        
        if ((($Instance->rsaPublicExponent = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null) ||
            (($Instance->rsaModulus = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null) ||
            (($Instance->rsaPrivateExponent = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null) ||
            (($Instance->rsaPrime1 = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null) ||
            (($Instance->rsaPrime2 = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null))
          return null;
        
        return $Instance;
      } elseif ($dbType === 'ssh-dss') {
        # TODO
      }
      
      return null;
    }
    // }}}
    
    // {{{ asn1read
    /**
     * Read an ASN.1-Bucket from a string
     * 
     * @param string $Data
     * @param int $Offset
     * @param int $Type (optional)
     * @param int $Length (optional)
     * 
     * @access private
     * @return string
     **/
    private static function asn1read (&$Data, &$Offset, &$Type = null, $Length = null) {
      // Make sure we know the length of our input
      if ($Length === null)
        $Length = strlen ($Data);
      
      // Make sure we have enough data to read
      if ($Length - $Offset < 2)
        return null;
      
      $pOffset = $Offset;
      $pType = ord ($Data [$pOffset++]);
      $pLength = ord ($Data [$pOffset++]);
      
      if ($pLength > 0x80) {
        // Check if there are enough bytes to read the extended length
        if ($Length - $pOffset < $pLength - 0x80)
          return null;
        
        // Read the extended length
        $b = $pLength - 0x80;
        $pLength = 0;
        
        for ($i = 0; $i < $b; $i++)
          $pLength = ($pLength << 8) | ord ($Data [$pOffset++]);
      }
      
      // Make sure there are enough bytes to read the payload
      if ($Length - $pOffset < $pLength)
        return null;
      
      // Read all data and move the offset
      $Type = $pType;
      $Offset = $pOffset + $pLength;
      
      return substr ($Data, $pOffset, $pLength);
    }
    // }}}
    
    // {{{ sign
    /**
     * Try to sign a given input with our private key
     * 
     * @param string $Data
     * 
     * @access public
     * @return string
     **/
    public function sign ($Data) {
      if ($this->Type === self::TYPE_RSA) {
        // Determine the size of the padding
        $k = strlen (gmp_export ($this->rsaModulus, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST));
        
        // Create digest-info
        if (($Data = $this->rsaPKCS15encode ($Data, $k)) === null)
          return null;
        
        // Convert to integer (has to be a positive integer, we just assume it as we know the first byte of the input)
        $Data = gmp_import ($Data, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        // Make sure it's smaller than our modulus
        if (gmp_cmp ($Data, $this->rsaModulus) >= 0)
          return null;
        
        // Generate the signature
        $Data = gmp_powm ($Data, $this->rsaPrivateExponent, $this->rsaModulus);
        
        // Convert back to string
        return gmp_export ($Data, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
      }
      
      # TODO
    }
    // }}}
    
    // {{{ signSSH
    /**
     * Generate signature with key-type prefixed
     * 
     * @param string $Data
     * 
     * @access public
     * @return string
     **/
    public function signSSH ($Data) {
      $Data = $this->sign ($Data);
      
      if ($this->Type === self::TYPE_RSA)
        return
          qcEvents_Stream_SSH_Message::writeString ('ssh-rsa') .
          qcEvents_Stream_SSH_Message::writeString ($Data);
      
      if ($this->Type === self::TYPE_DSA)
        return
          qcEvents_Stream_SSH_Message::writeString ('ssh-dss') .
          qcEvents_Stream_SSH_Message::writeString ($Data);
    }
    // }}}
    
    // {{{ exportPublicKey
    /**
     * Export public part of this key to a string
     * 
     * @access public
     * @return string
     **/
    public function exportPublicKey () {
      if ($this->Type === self::TYPE_RSA)
        return
          qcEvents_Stream_SSH_Message::writeString ('ssh-rsa') .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->rsaPublicExponent) .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->rsaModulus);
      
      if ($this->Type === self::TYPE_DSA)
        return
          qcEvents_Stream_SSH_Message::writeString ('ssh-dss') .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->dsaP) .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->dsaQ) .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->dsaG) .
          qcEvents_Stream_SSH_Message::writeMPInt ($this->dsaY);
    }
    // }}}
  }

?>