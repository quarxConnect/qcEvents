<?PHP

  /**
   * qcEvents - DNS DS Resource Record
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
  
  require_once ('qcEvents/Stream/DNS/Record.php');
  
  class qcEvents_Stream_DNS_Record_DS extends qcEvents_Stream_DNS_Record {
    const DEFAULT_TYPE = 0x2B;
    
    const DIGEST_SHA1 = 0x01;
    
    private $keyTag = 0x0000;
    private $Algorithm = 0x00;
    private $digestType = 0x00;
    private $Digest = '';
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' DS ' . $this->keyTag . ' ' . $this->Algorithm . ' ' . $this->digestType . ' ' . bin2hex ($this->Digest);
    }
    // }}}
    
    // {{{ getKeyTag
    /**
     * Retrive the Tag of the assigned key here
     * 
     * @access public
     * @return int
     **/
    public function getKeyTag () {
      return $this->keyTag;
    }
    // }}}
    
    // {{{ getAlgorithm
    /**
     * Retrive the identifier of the used algorithm
     * 
     * @access public
     * @return int
     **/
    public function getAlgorithm () {
      return $this->Algorithm;
    }
    // }}}
    
    // {{{ getDigestType
    /**
     * Retrive the identifier of the used digest
     * 
     * @access public
     * @return int
     **/
    public function getDigestType () {
      return $this->digestType;
    }
    // }}}
    
    // {{{ getDigest
    /**
     * Retrive the digest
     * 
     * @access public
     * @return string
     **/
    public function getDigest () {
      return $this->Digest;
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
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      $this->keyTag = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->Algorithm = ord ($dnsData [$dataOffset++]);
      $this->digestType = ord ($dnsData [$dataOffset++]);
      $this->Digest = substr ($dnsData, $dataOffset, $dataLength - $dataOffset);
      $dataOffset = $dataLength;
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
        self::buildInt16 ($this->keyTag) .
        chr ($this->Algorithm & 0xFF) .
        chr ($this->digestType & 0xFF) .
        $this->Digest;
    }
    // }}}
  }

?>