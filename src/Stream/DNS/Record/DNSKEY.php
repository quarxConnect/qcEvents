<?php

  /**
   * quarxConnect Events - DNS DNSKEY Resource Record
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\DNS\Record;
  use quarxConnect\Events\Stream\DNS;
  
  class DNSKEY extends DNS\Record {
    protected const DEFAULT_TYPE = 0x30;
    
    public const FLAG_ZONEKEY    = 0x0100;
    public const FLAG_ENTRYPOINT = 0x0001;
    
    public const ALGO_RSAMD5        = 0x01; /* RFC 4034 */
    public const ALGO_DH            = 0x02;
    public const ALGO_DSA           = 0x03;
    public const ALGO_RSASHA1       = 0x05; /* RFC 4034 */
    public const ALGO_RSASHA1_NSEC3 = 0x07; /* RFC 5155 */
    public const ALGO_RSASHA256     = 0x08; /* RFC 5702 */
    public const ALGO_RSASAH512     = 0x0A; /* RFC 5702 */
    public const ALGO_ECC_GOST      = 0x0C; /* RFC 5933 */
    
    public const ALGO_INDIRECT      = 0xFC;
    public const ALGO_PRIVATEDNS    = 0xFD;
    public const ALGO_PRIVATEOID    = 0xFE;
    
    private $Flags = 0x00;
    private $Protocol = 0x03;
    private $Algorithm = 0x00;
    private $PublicKey = '';
    
    /* Cached Key-Tag of this DNS-Key */
    private $keyTag = null;
    
    /* Cached X.509 Public Key */
    private $x509Key = null;
    
    // {{{ algorithmIDToObjectID
    /**
     * Convert a DNSKEY/RRSIG-Algoritm-ID into an ASN.1 Object ID
     * 
     * @param enum $Algorithm
     * 
     * @access public
     * @return array
     **/
    public static function algorithmIDToObjectID ($Algorithm) {
      static $algorithmMap = [
        self::ALGO_RSAMD5        => [ 1, 2, 840, 113549, 1, 1, 4 ],
        self::ALGO_RSASHA1       => [ 1, 2, 840, 113549, 1, 1, 5 ],
        self::ALGO_RSASHA1_NSEC3 => [ 1, 2, 840, 113549, 1, 1, 5 ],
        self::ALGO_RSASHA256     => [ 2, 16, 840, 1, 101, 3, 4, 2, 1 ],
        self::ALGO_RSASAH512     => [ 2, 16, 840, 1, 101, 3, 4, 2, 3 ],
      ];
      
      if (($Algorithm === null) || !isset ($algorithmMap [$Algorithm]))
        return false;
      
      return \ASN1\ObjectID::newInstance ($algorithmMap [$Algorithm]);
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
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' DNSKEY ' . $this->Flags . ' ' . $this->Protocol . ' ' . $this->Algorithm . ' ' . base64_encode ($this->PublicKey);
    }
    // }}}
    
    // {{{ getFlags
    /**
     * Retrive flags of this DNSKEY
     * 
     * @access public
     * @return int
     **/
    public function getFlags () {
      return $this->Flags;
    }
    // }}}
    
    // {{{ getAlgorithm
    /**
     * Retrive the DNSSEC-Identifier of the used algorithm
     * 
     * @access public
     * @return enum
     **/
    public function getAlgorithm () {
      return $this->Algorithm;
    }
    // }}}
    
    // {{{ getKeyTag
    /**
     * Retrive the Key-Tag for this DNS-Key
     * 
     * @access public
     * @return int
     **/
    public function getKeyTag () {
      // Check if we have done this before
      if ($this->keyTag !== null)
        return $this->keyTag;
      
      // A small hack for algorithm 1 (not tested as no one should use it)
      if ($this->Algorithm == 1) {
        trigger_error ('Just found a DNSKEY thats uses MD5. Plase check if keytag is calculated correctly!');
        
        return ($this->keyTag = ((ord ($this->PublicKey [strlen ($this->PublicKey) - 4]) << 8) | ord ($this->PublicKey [strlen ($this->PublicKey) - 3])));
      }
      
      // Retrive the payload
      $n = null;
      $Data = $this->buildPayload ($n, $n);
      $KeyTag = 0;
      
      // Calculate the keytag
      for ($i = 0; $i < strlen ($Data); $i++)
        $KeyTag += ($i & 1) ? ord ($Data [$i]) : (ord ($Data [$i]) << 8);
      
      $KeyTag += ($KeyTag >> 16) & 0xFFFF;
      
      return ($this->keyTag = ($KeyTag & 0xFFFF));
    }
    // }}}
    
    // {{{ getPublicKey
    /**
     * Retrive the data of the public key
     * 
     * @access public
     * @return string
     **/
    public function getPublicKey () {
      return $this->PublicKey;
    }
    // }}}
    
    // {{{ toX509
    /**
     * Convert this DNS-Key into an X.509 Public Key
     * 
     * @access public
     * @return X509_Subject_PublicKey
     **/
    public function toX509 () {
      // Check if the key was already prepared
      if ($this->x509Key)
        return $this->x509Key;
      
      // Try to generate the key based on algorithm
      switch ($this->Algorithm) {
        // RSA-Keys
        case $this::ALGO_RSAMD5:
          trigger_error ('Usage of MD5-Keys is prohibited as of RFC 6944');
        
        case $this::ALGO_RSASHA1:
        case $this::ALGO_RSASHA1_NSEC3:
        case $this::ALGO_RSASHA256:
        case $this::ALGO_RSASHA512:
        case $this::ALGO_ECC_GOST:
          // Make sure the exponent-length is there
          if (($l = strlen ($this->PublicKey)) < 1)
            return false;
          
          // Read length of the exponent from key
          $expLength = ord ($this->PublicKey [0]);
          
          if ($expLength == 0) {
            // Make sure there is still enough data
            if ($l < 3)
              return false;
            
            // Read extended length
            $expLength = (ord ($this->PublicKey [1]) << 8) + ord ($this->PublicKey [2]);
            $Offset = 3;
          } else
            $Offset = 1;
          
          // Extract exponent
          if ($l < $Offset + $expLength)
            return false;
          
          $Exponent = \ASN1\Integer::newInstance (0);
          $Exponent->setBinaryBE (substr ($this->PublicKey, $Offset, $expLength));
          
          // Extract modulus
          $Modulus = \ASN1\Integer::newInstance (0);
          $Modulus->setBinaryBE (substr ($this->PublicKey, $Offset + $expLength));
          
          // Create a public key from this
          $Key = \X509\Key\Public\RSA::factory ();
          $Key->setExponent ($Exponent);
          $Key->setModulus ($Modulus);
          
          // We need a Subject to use the key with OpenSSL
          return ($this->x509Key = \X509\Subject\PublicKey::newInstance ($Key));
        # case $this::ALGO_DSA:
        # case $this::ALGO_DSA_NSEC3:
        #   # SHA_DIGEST_LENGTH = 20
        #   # We need at least SHA_DIGEST_LENGTH * 2 + 1 bytes
        #   # First Byte is called T and not used
        #   # Read Big Number R of size SHA_DIGEST_LENGTH from 0x01
        #   # Read Big Number S of size SHA_DIGEST_LENGTH from 0x01 + SHA_DIGEST_LENGTH
        #   # Generate ASN.1 representation of DSA-Key
        # case $this::ALGO_ECDSAP256SHA256:
        # case $this::ALGO_ECDSAP384SHA384:
        #   # Make sure the length is even and bigger than 32
        #   # Read two Big Numbers r, s from public key, both half length of the key
        #   # Generate ASN.1 representation
        # case $this::ALGO_DH:
        #   # Unimplemented
        #   # Read 16-bit prime length
        #   # Fail if length >2 and length <16 or length <1
        #   # Lookup table if length <3
        #   # Read prime of length if length >16
        #   # Read 16-bit generator length
        #   # Read generator of length
        #   # Read 16-bit public value length
        #   # Read public value of length
      }
      
      return false;
    }
    // }}}
    
    // {{{ verifySignature
    /**
     * Try to verify a given signature
     * 
     * @param string $Data
     * @param mixed $Signature
     * @param enum $Algorithm (optional)
     * 
     * @access public
     * @return bool
     **/
    public function verifySignature ($Data, $Signature, $Algorithm = null) {
      // Expand signature
      if ($Signature instanceof RRSIG) {
        $Algorithm = $Signature->getAlgorithm ();
        $Signature = $Signature->getSignature ();
      }
      
      // Map the algorithm
      if (($Algorithm = self::algorithmIDToObjectID ($Algorithm)) === false)
        return false;
      
      // Create an X.509-Key from this DNS-Key
      if (!is_object ($X509 = $this->toX509 ()))
        return false;
      
      // Let X.509 handle that stuff
      return $X509->verifySignature ($Data, $Signature, $Algorithm);
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
     * @throws \LengthException
     * @throws \UnexpectedValueException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      // Make sure we know the length of our input-buffer
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      if ($dataLength < $dataOffset + 6)
        throw new \LengthException ('DNS-Record too short (DNSKEY)');
      
      $Flags = self::parseInt16 ($dnsData, $dataOffset);
      $Protocol = ord ($dnsData [$dataOffset++]);
      $Algorithm = ord ($dnsData [$dataOffset++]);
      $PublicKey = substr ($dnsData, $dataOffset, $dataLength - $dataOffset);
      $dataOffset = $dataLength;
      
      if ($Protocol != 3)
        throw new \UnexpectedValueException ('Invalid protocol (DNSKEY)');
      
      $this->Flags = $Flags;
      $this->Protocol = $Protocol;
      $this->Algorithm = $Algorithm;
      $this->PublicKey = $PublicKey;
      $this->keyTag = null;
      $this->x509Key = null;
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
      return
        self::buildInt16 ($this->Flags) .
        chr ($this->Protocol & 0xFF) .
        chr ($this->Algorithm & 0xFF) .
        $this->PublicKey;
    }
    // }}}
  }
