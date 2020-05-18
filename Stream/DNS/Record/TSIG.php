<?PHP

  /**
   * qcEvents - TSIG DNS Resource Record
   * Copyright (C) 2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Stream/DNS/Record.php');
  
  class qcEvents_Stream_DNS_Record_TSIG extends qcEvents_Stream_DNS_Record {
    /* Default Type of this record */
    const DEFAULT_TYPE = qcEvents_Stream_DNS_Message::TYPE_TSIG;
    
    /* Don't allow this record-type to be cached */
    const ALLOW_CACHING = false;
    
    private $algorithmName = '';
    private $timeSigned = 0;
    private $timeWindow = 0;
    private $macData = '';
    private $originalID = 0;
    private $errorCode = 0;
    private $otherData = '';
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return
        $this->getLabel () . ' ' .
        $this->getTTL () . ' ' .
        $this->getClassName () . ' ' .
        'TSIG ' .
        $this->algorithmName . ' ' .
        $this->timeSigned . ' ' .
        $this->timeWindow . ' ' .
        strlen ($this->macData) . ' ' .
        base64_encode ($this->macData) . ' ' .
        $this->originalID . ' ' .
        $this->errorCode . ' ' . # TODO: Convert this to a human-friendly string
        strlen ($this->otherData) . ' ' .
        base64_encode ($this->otherData);
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
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public function parsePayload (&$dnsData, &$dataOffset, $dataLength = null) {
      if (!($algorithmName = qcEvents_Stream_DNS_Message::getLabel ($dnsData, $dataOffset)))
        throw new UnexpectedValueException ('Failed to read label of DNS-Record (TSIG)');
      
      $timeSigned = $this::parseInt48 ($dnsData, $dataOffset, $dataLength);
      $timeWindow = $this::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $macSize = $this::parseInt16 ($dnsData, $dataOffset, $dataLength);
      
      if ($dataLength < $dataOffset + $macSize)
        throw new LengthException ('DNS-Record too short (TSIG)');
      
      $macData = substr ($dnsData, $dataOffset, $macSize);
      $dataOffset += $macSize;
      
      $originalID = $this::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $errorCode = $this::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $otherLength = $this::parseInt16 ($dnsData, $dataOffset, $dataLength);
      
      if ($dataLength < $dataOffset + $otherLength)
        throw new LengthException ('DNS-Record too short (TSIG)');
      
      $otherData = substr ($dnsData, $dataOffset, $otherLength);
      $dataOffset += $otherLength;
      
      $this->algorithmName = $algorithmName;
      $this->timeSigned = $timeSigned;
      $this->timeWindow = $timeWindow;
      $this->macData = $macData;
      $this->originalID = $originalID;
      $this->errorCode = $errorCode;
      $this->otherData = $otherData;
    }
    // }}}
    
    // {{{ buildPayload
    /**
     * Retrive the payload of this record
     * 
     * @param int $dataOffset
     * @param array &$dnsLabels
     * 
     * @access public
     * @return string
     **/
    public function buildPayload ($dataOffset, &$dnsLabels) {
      return qcEvents_Stream_DNS_Message::setLabel ($this->algorithmName, $dataOffset, $dnsLabels);
    }
    // }}}
  }

?>