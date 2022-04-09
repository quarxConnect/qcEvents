<?php

  /**
   * qcEvents - SSH Private Key
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
  
  class PrivateKey extends PublicKey {
    /* RSA Private Exponent */
    private $rsaPrivateExponent = null;
    
    /* RSA Primes */
    private $rsaPrime1 = null;
    private $rsaPrime2 = null;
    
    // {{{ loadFromString
    /**
     * Try to load a private key from string
     * 
     * @param string $keyData
     * 
     * @access public
     * @return PublicKey
     **/
    public static function loadFromString (string $keyData) : ?PublicKey {
      // Check for something PEM-Encoded and convert to DER
      if (substr ($keyData, 0, 10) == '-----BEGIN') {
        $pemOffset = strpos ($keyData, "\n") + 1;
        $keyData = base64_decode (substr ($keyData, $pemOffset, strpos ($keyData, '----END') - $pemOffset));
      }
      
      // Check for DER-Encoded something
      $derOffset = 0;
      $derLength = strlen ($keyData);
      $asnType = 0;
      
      if (
        (($asnSequence = self::asn1read ($keyData, $derOffset, $asnType, $derLength)) !== null) &&
        ($asnType == 0x30) &&
        ($derOffset == $derLength)
      ) {
        // Split the sequence
        $asnOffset = 0;
        $asnLength = strlen ($asnSequence);
        $asnItems = [ ];
        unset ($keyData);
        
        while ($asnOffset < $asnLength) {
          // Try to read the next item from the sequence
          if (($asnItem = self::asn1read ($asnSequence, $asnOffset, $asnType, $asnLength)) === null)
            return null;
          
          $asnItems [] = [ $asnType, $asnItem ];
        }
        
        // Check for RSA-Key
        if ((count ($asnItems) == 9) || (count ($asnItems) == 10)) {
          // Sanity-Check the types
          if (
            ($asnItems [1][0] != 0x02) ||
            ($asnItems [2][0] != 0x02) ||
            ($asnItems [3][0] != 0x02) ||
            ($asnItems [4][0] != 0x02) ||
            ($asnItems [5][0] != 0x02)
          )
            return null;
          
          // Create the key
          $keyInstance = new static ();
          $keyInstance->Type = $keyInstance::TYPE_RSA;
          $keyInstance->rsaModulus = gmp_import ($asnItems [1][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $keyInstance->rsaPublicExponent = gmp_import ($asnItems [2][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $keyInstance->rsaPrivateExponent = gmp_import ($asnItems [3][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $keyInstance->rsaPrime1 = gmp_import ($asnItems [4][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          $keyInstance->rsaPrime2 = gmp_import ($asnItems [5][1], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
          
          return $keyInstance;
        // Check for DSS-Key
        } elseif (count ($asnItems) == 6) {
          # TODO
        }
        
        return null;
      }
      
      // Check for SSH private-key
      $keyOffset = 0;
      $dbType = Message::readString ($keyData, $keyOffset, $derLength);
      
      if ($dbType === 'ssh-rsa') {
        $keyInstance = new static ();
        $keyInstance->Type = $keyInstance::TYPE_RSA;
        
        if (
          (($keyInstance->rsaPublicExponent = Message::readMPInt ($keyData, $keyOffset, $derLength)) === null) ||
          (($keyInstance->rsaModulus = Message::readMPInt ($keyData, $keyOffset, $derLength)) === null) ||
          (($keyInstance->rsaPrivateExponent = Message::readMPInt ($keyData, $keyOffset, $derLength)) === null) ||
          (($keyInstance->rsaPrime1 = Message::readMPInt ($keyData, $keyOffset, $derLength)) === null) ||
          (($keyInstance->rsaPrime2 = Message::readMPInt ($keyData, $keyOffset, $derLength)) === null)
        )
          return null;
        
        return $keyInstance;
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
     * @param string $sourceData
     * @param int $sourceOffset
     * @param int $asnType (optional)
     * @param int $sourceLength (optional)
     * 
     * @access private
     * @return string
     **/
    private static function asn1read (string &$sourceData, int &$sourceOffset, int &$asnType = null, int $sourceLength = null) : ?string {
      // Make sure we know the length of our input
      if ($sourceLength === null)
        $sourceLength = strlen ($sourceData);
      
      // Make sure we have enough data to read
      if ($sourceLength - $sourceOffset < 2)
        return null;
      
      $localOffset = $sourceOffset;
      $localType = ord ($sourceData [$localOffset++]);
      $localLength = ord ($sourceData [$localOffset++]);
      
      if ($localLength > 0x80) {
        // Check if there are enough bytes to read the extended length
        if ($sourceLength - $localOffset < $localLength - 0x80)
          return null;
        
        // Read the extended length
        $lengthBytes = $sourceLength - 0x80;
        $localLength = 0;
        
        for ($i = 0; $i < $lengthBytes; $i++)
          $localLength = ($localLength << 8) | ord ($sourceData [$localOffset++]);
      }
      
      // Make sure there are enough bytes to read the payload
      if ($sourceLength - $localOffset < $localLength)
        return null;
      
      // Read all data and move the offset
      $asnType = $localType;
      $sourceOffset = $localOffset + $localLength;
      
      return substr ($sourceData, $localOffset, $localLength);
    }
    // }}}
    
    // {{{ sign
    /**
     * Try to sign a given input with our private key
     * 
     * @param string $sourceData
     * 
     * @access public
     * @return string
     **/
    public function sign (string $sourceData) : ?string {
      if ($this->Type === self::TYPE_RSA) {
        // Determine the size of the padding
        $k = strlen (gmp_export ($this->rsaModulus, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST));
        
        // Create digest-info
        if (($sourceData = $this->rsaPKCS15encode ($sourceData, $k)) === null)
          return null;
        
        // Convert to integer (has to be a positive integer, we just assume it as we know the first byte of the input)
        $sourceData = gmp_import ($sourceData, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        // Make sure it's smaller than our modulus
        if (gmp_cmp ($sourceData, $this->rsaModulus) >= 0)
          return null;
        
        // Generate the signature
        $signatureData = gmp_powm ($sourceData, $this->rsaPrivateExponent, $this->rsaModulus);
        
        // Convert back to string
        return gmp_export ($signatureData, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
      }
      
      # TODO
      return null;
    }
    // }}}
    
    // {{{ signSSH
    /**
     * Generate signature with key-type prefixed
     * 
     * @param string $sourceData
     * 
     * @access public
     * @return string
     **/
    public function signSSH (string $sourceData) : ?string {
      $signatureData = $this->sign ($sourceData);
      
      if ($this->Type === self::TYPE_RSA)
        return
          Message::writeString ('ssh-rsa') .
          Message::writeString ($signatureData);
      
      if ($this->Type === self::TYPE_DSA)
        return
          Message::writeString ('ssh-dss') .
          Message::writeString ($signatureData);
      
      return null;
    }
    // }}}
    
    // {{{ exportPublicKey
    /**
     * Export public part of this key to a string
     * 
     * @access public
     * @return string
     **/
    public function exportPublicKey () : string {
      if ($this->Type === self::TYPE_RSA)
        return
          Message::writeString ('ssh-rsa') .
          Message::writeMPInt ($this->rsaPublicExponent) .
          Message::writeMPInt ($this->rsaModulus);
      
      if ($this->Type === self::TYPE_DSA)
        return
          Message::writeString ('ssh-dss') .
          Message::writeMPInt ($this->dsaP) .
          Message::writeMPInt ($this->dsaQ) .
          Message::writeMPInt ($this->dsaG) .
          Message::writeMPInt ($this->dsaY);
    }
    // }}}
  }
