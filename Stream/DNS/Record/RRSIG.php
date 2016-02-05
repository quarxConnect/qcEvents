<?PHP

  /**
   * qcEvents - DNS RRSIG Resource Record
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
  
  require_once ('qcEvents/Stream/DNS/Record.php');
  require_once ('qcEvents/Stream/DNS/Record/DNSKEY.php');
  
  class qcEvents_Stream_DNS_Record_RRSIG extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x2E;
    
    private $typeCovered   = 0x0000;
    private $Algorithm     = 0x00;
    private $Labels        = 0x00;
    private $originalTTL   = 0x00000000;
    private $sigExpiration = 0x00000000;
    private $sigInception  = 0x00000000;
    private $keyTag        = 0x0000;
    private $SignersName   = '';
    private $Signature     = '';
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' RRSIG TYPE' . $this->typeCovered . ' ' . $this->Algorithm . ' ' . $this->Labels . ' ' . $this->originalTTL . ' ' . date ('YmdHis', $this->sigExpiration) . ' ' . date ('YmdHis', $this->sigInception) . ' ' . $this->keyTag . ' ' . $this->SignersName . ' ' . base64_encode ($this->Signature);
    }
    // }}}
    
    // {{{ getCoveredType
    /**
     * Retrive the type of record that is signed by this one
     * 
     * @access public
     * @return int
     **/
    public function getCoveredType () {
      return $this->typeCovered;
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
    
    // {{{ getAlgorithmObjectID
    /**
     * Retrive the Algoritm of this signature as ASN.1 Object ID
     * 
     * @access public
     * @return array
     **/
    public function getAlgorithmObjectID () {
      return qcEvents_Stream_DNS_Record_DNSKEY::algorithmIDtoObjectID ($this->Algorithm);
    }
    // }}}
    
    // {{{ getLabelCount
    /**
     * Retrive the number of label-elements that were signed by this record
     * 
     * @access public
     * @return int
     **/
    public function getLabelCount () {
      return $this->Labels;
    }
    // }}}
    
    // {{{ getOriginalTTL
    /**
     * Retrive the original time-to-live of the signed records
     * 
     * @access public
     * @return int
     **/
    public function getOriginalTTL () {
      return $this->originalTTL;
    }
    // }}}
    
    // {{{ getExpireDate
    /**
     * Retrive the unix-timestamp when this record expires
     * 
     * @access public
     * @return int
     **/
    public function getExpireDate () {
      return $this->sigExpiration;
    }
    // }}}
    
    // {{{ getInceptionDate
    /**
     * Retrive unix-timestamp when this record will be replaced by a new one
     * 
     * @access public
     * @return int
     **/
    public function getInceptionDate () {
      return $this->sigInception;
    }
    // }}}
    
    // {{{ getKeyTag
    /**
     * Retrive the key-tag of the public key that created this signature
     * 
     * @access public
     * @return int
     **/
    public function getKeyTag () {
      return $this->keyTag;
    }
    // }}}
    
    // {{{ getSigner
    /**
     * Retrive the dns-label of the one who signed this record
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function getSigner () {
      return $this->SignersName;
    }
    // }}}
    
    // {{{ getSignature
    /**
     * Retrive the actual signature carried on this record
     * 
     * @access public
     * @return string
     **/
    public function getSignature () {
      return $this->Signature;
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
      
      $oOffset = $Offset;
      
      if (($typeCovered = self::parseInt16 ($Data, $Offset)) === false)
        return false;
      
      if (($Algorithm = ord ($Data [$Offset++])) === false)
        return false;
      
      if (($Labels = ord ($Data [$Offset++])) === false)
        return false;
      
      if (($originalTTL = self::parseInt32 ($Data, $Offset)) === false)
        return false;
      
      if (($sigExpiration = self::parseInt32 ($Data, $Offset)) === false)
        return false;
      
      if (($sigInception = self::parseInt32 ($Data, $Offset)) === false)
        return false;
      
      if (($keyTag = self::parseInt16 ($Data, $Offset)) === false)
        return false;
      
      if (($SignersName = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset, false)) === false)
        return false;
      
      $this->typeCovered   = $typeCovered;
      $this->Algorithm     = $Algorithm;
      $this->Labels        = $Labels;
      $this->originalTTL   = $originalTTL;
      $this->sigExpiration = $sigExpiration;
      $this->sigInception  = $sigInception;
      $this->keyTag        = $keyTag;
      $this->SignersName   = $SignersName;
      $this->Signature     = substr ($Data, $Offset, $Length - ($Offset - $oOffset));
      
      return true;
    }
    // }}}
    
    // {{{ buildPayload
    /**
     * Retrive the payload of this record
     * 
     * @access public
     * @return string
     **/
    public function buildPayload ($Offset, &$Labels) {
      return 
        self::buildInt16 ($this->typeCovered) .
        chr ($this->Algorithm & 0xFF) .
        chr ($this->Labels & 0xFF) .
        self::buildInt32 ($this->originalTTL) .
        self::buildInt32 ($this->sigExpiration) .
        self::buildInt32 ($this->sigInception) .
        self::buildInt16 ($this->keyTag) .
        qcEvents_Stream_DNS_Message::setLabel ($this->SignersName) .
        $this->Signature;
    }
    // }}}
  }

?>