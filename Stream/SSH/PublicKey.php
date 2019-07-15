<?PHP

  /**
   * qcEvents - SSH Public Key
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
  
  class qcEvents_Stream_SSH_PublicKey {
    /* Type of this private key */
    const TYPE_RSA = 0;
    const TYPE_DSA = 1;     # Unsupported
    const TYPE_ECDSA = 2;   # Unsupported
    const TYPE_ED25519 = 3; # Unsupported
    
    protected $Type = qcEvents_Stream_SSH_PublicKey::TYPE_RSA;
    
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
     * @param qcEvents_Base $Base
     * @param string $Filename
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function loadFromFile (qcEvents_Base $Base, $Filename) : qcEvents_Promise {
      // Try to read all contents from the file first
      return qcEvents_File::readFileContents ($Base, $Filename)->then (
        function ($Data) {
          // Try to parse the key
          if (!is_object ($Key = static::loadFromString ($Data)))
            throw new exception ('Failed to load key');
          
          // Forward the result
          return $Key;
        }
      );
    }
    // }}}
    
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
      // Check for SSH private-key
      $Offset = 0;
      $Length = strlen ($String);
      $dbType = qcEvents_Stream_SSH_Message::readString ($String, $Offset, $Length);
      
      if ($dbType === 'ssh-rsa') {
        $Instance = new static ();
        $Instance->Type = $Instance::TYPE_RSA;
        
        if ((($Instance->rsaPublicExponent = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null) ||
            (($Instance->rsaModulus = qcEvents_Stream_SSH_Message::readMPInt ($String, $Offset, $Length)) === null))
          return null;
        
        return $Instance;
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
    public function getType () {
      return $this->Type;
    }
    // }}}
    
    public function verify ($Data, $Signature) {
      // Verify an RSA-Signature
      if ($this->Type == self::TYPE_RSA) {
        // Determine the size of the signature
        $k = strlen (gmp_export ($this->rsaModulus, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST));
        
        // Check size of the signature
        if (strlen ($Signature) != $k)
          return false;
        
        // Convert to an integer
        $Signature = gmp_import ($Signature, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        // Make sure it's smaller than our modulus
        if (gmp_cmp ($Signature, $this->rsaModulus) >= 0)
          return null;
        
        // Generate the verifycation
        $Signature = gmp_powm ($Signature, $this->rsaPublicExponent, $this->rsaModulus);
        
        // Convert back to string
        $Signature = "\x00" . gmp_export ($Signature, 1, GMP_BIG_ENDIAN | GMP_MSW_FIRST);
        
        // Create input-message
        $Message = $this->rsaPKCS15encode ($Data, $k);
        
        return (strcmp ($Message, $Signature) == 0);
      }
      
      # TODO
    }
    
    // {{{ verifySSH
    /**
     * Verify a signature taken from SSH
     * 
     * @param string $Data
     * @param string $Signature
     * 
     * @access public
     * @return bool
     **/
    public function verifySSH ($Data, $Signature) {
      // Split the signature into it's pieces
      $Offset = 0;
      $Length = strlen ($Signature);
      
      if ((($Type = qcEvents_Stream_SSH_Message::readString ($Signature, $Offset, $Length)) === null) ||
          (($Signature = qcEvents_Stream_SSH_Message::readString ($Signature, $Offset, $Length)) === null) ||
          ($Length != $Offset))
        return false;
      
      // Make sure the signature-type matches our key-type
      if (($this->Type != self::TYPE_RSA) || ($Type != 'ssh-rsa'))
        return false;
      
      // Try to verify the signature
      return $this->verify ($Data, $Signature);
    }
    // }}}
    
    // {{{ rsaPKCS15encode
    /**
     * Encode a PKCS1 1.5 message
     * 
     * @param string $Data
     * @param int $k
     * 
     * @access protected
     * @return string
     **/
    protected function rsaPKCS15encode ($Data, $k) {
      // Create digest-info
      $DigestInfo = "\x30\x21\x30\x09\x06\x05\x2b\x0e\x03\x02\x1a\x05\x00\x04\x14" . hash ('sha1', $Data, true);
      $DigestInfoLength = strlen ($DigestInfo);
      
      // Check the length
      if ($k < $DigestInfoLength + 11)
        return null;
      
      // Generate the result
      return "\x00\x01" . str_repeat ("\xff", $k - $DigestInfoLength - 3) . "\x00" . $DigestInfo;
    }
    // }}}
  }

?>