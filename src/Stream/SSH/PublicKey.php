<?php

  /**
   * qcEvents - SSH Public Key
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
  use \quarxConnect\Events;
  
  class PublicKey {
    /* Type of this private key */
    public const TYPE_RSA = 0;
    public const TYPE_DSA = 1;     # Unsupported
    public const TYPE_ECDSA = 2;   # Unsupported
    public const TYPE_ED25519 = 3; # Unsupported
    
    protected $Type = PublicKey::TYPE_RSA;
    
    /* RSA Modulus */
    protected $rsaModulus = null;
    
    /* RSA Public Exponent */
    protected $rsaPublicExponent = null;
    
    /* DSA-Parameters (unsupported) */
    protected $dsaP = null;
    protected $dsaQ = null;
    protected $dsaG = null;
    protected $dsaY = null;
    
    // {{{ loadFromFile
    /**
     * Try to load a private key from a file
     * 
     * @param Events\Base $eventBase
     * @param string $fileName
     * 
     * @access public
     * @return Events\Promise
     **/
    public static function loadFromFile (Events\Base $eventBase, string $fileName) : Events\Promise {
      // Try to read all contents from the file first
      return Events\File::readFileContents ($eventBase, $fileName)->then (
        function (string $keyData) {
          // Try to parse the key
          if (!is_object ($keyInstance = static::loadFromString ($keyData)))
            throw new \Exception ('Failed to load key');
          
          // Forward the result
          return $keyInstance;
        }
      );
    }
    // }}}
    
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
      // Check for SSH private-key
      $keyOffset = 0;
      $keyLength = strlen ($keyData);
      $dbType = Message::readString ($keyData, $keyOffset, $keyLength);
      
      if ($dbType === 'ssh-rsa') {
        $keyInstance = new static ();
        $keyInstance->Type = $keyInstance::TYPE_RSA;
        
        if (
          (($keyInstance->rsaPublicExponent = Message::readMPInt ($keyData, $keyOffset, $keyLength)) === null) ||
          (($keyInstance->rsaModulus = Message::readMPInt ($keyData, $keyOffset, $keyLength)) === null)
        )
          return null;
        
        return $keyInstance;
      } elseif ($dbType === 'ssh-dss') {
        # TODO
      }
      
      return null;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this key
     * 
     * @access public
     * @return enum
     **/
    public function getType () : int {
      return $this->Type;
    }
    // }}}
    
    // {{{ verify
    /**
     * Verify a signature
     * 
     * @param string $sourceData
     * @param string $signatureData
     * 
     * @access public
     * @return bool
     **/
    public function verify (string $sourceData, string $signatureData) : bool {
      // Verify an RSA-Signature
      if ($this->Type == self::TYPE_RSA) {
        // Determine the size of the signature
        $k = strlen (gmp_export ($this->rsaModulus, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST));
        
        // Check size of the signature
        if (strlen ($signatureData) != $k)
          return false;
        
        // Convert to an integer
        $signatureData = gmp_import ($signatureData, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        // Make sure it's smaller than our modulus
        if (gmp_cmp ($signatureData, $this->rsaModulus) >= 0)
          return null;
        
        // Generate the verifycation
        $signatureData = gmp_powm ($signatureData, $this->rsaPublicExponent, $this->rsaModulus);
        
        // Convert back to string
        $signatureData = "\x00" . gmp_export ($signatureData, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
        
        // Create input-message
        $sourceData = $this->rsaPKCS15encode ($sourceData, $k);
        
        return (strcmp ($sourceData, $signatureData) == 0);
      }
      
      # TODO
      return false;
    }
    // }}}
    
    // {{{ verifySSH
    /**
     * Verify a signature taken from SSH
     * 
     * @param string $sourceData
     * @param string $signatureData
     * 
     * @access public
     * @return bool
     **/
    public function verifySSH (string $sourceData, string $signatureData) : bool {
      // Split the signature into it's pieces
      $signatureOffset = 0;
      $signatureLength = strlen ($signatureData);
      
      if (
        (($Type = Message::readString ($signatureData, $signatureOffset, $signatureLength)) === null) ||
        (($signatureData = Message::readString ($signatureData, $signatureOffset, $signatureLength)) === null) ||
        ($signatureLength != $signatureOffset)
      )
        return false;
      
      // Make sure the signature-type matches our key-type
      if (($this->Type != self::TYPE_RSA) || ($Type != 'ssh-rsa'))
        return false;
      
      // Try to verify the signature
      return $this->verify ($sourceData, $signatureData);
    }
    // }}}
    
    // {{{ rsaPKCS15encode
    /**
     * Encode a PKCS1 1.5 message
     * 
     * @param string $sourceData
     * @param int $k
     * 
     * @access protected
     * @return string
     **/
    protected function rsaPKCS15encode (string $sourceData, int $k) : ?string {
      // Create digest-info
      $digestInfo = "\x30\x21\x30\x09\x06\x05\x2b\x0e\x03\x02\x1a\x05\x00\x04\x14" . hash ('sha1', $sourceData, true);
      $digestInfoLength = strlen ($digestInfo);
      
      // Check the length
      if ($k < $digestInfoLength + 11)
        return null;
      
      // Generate the result
      return "\x00\x01" . str_repeat ("\xff", $k - $digestInfoLength - 3) . "\x00" . $digestInfo;
    }
    // }}}
  }
