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
    
    private $algorithmName = null;
    private $timeSigned = 0;
    private $timeWindow = 0;
    private $macData = '';
    private $originalID = 0;
    private $errorCode = 0;
    private $otherData = '';
    
    // {{{ verifyMessage
    /**
     * Verfiy the signature on a DNS-Message
     * 
     * @param qcEvents_Stream_DNS_Message $dnsMessage
     * @param array $keyStore
     * 
     * @access public
     * @return int
     **/
    public static function verifyMessage (qcEvents_Stream_DNS_Message $dnsMessage, array $keyStore) {
      // Try to find a tsig-record
      $tsigRecord = null;

      foreach ($dnsMessage->getAdditionals () as $additionalRecord) {
        // Make sure there is no TSIG-Record before the last record
        if ($tsigRecord !== null)
          return qcEvents_Stream_DNS_Message::ERROR_FORMAT;
        
        elseif ($additionalRecord instanceof qcEvents_Stream_DNS_Record_TSIG)
          $tsigRecord = $additionalRecord;
      }

      // No TSIG to check
      if (!$tsigRecord)
        return null;
      
      // Check for supported message-algorithm
      if ($tsigRecord->getAlgorithm () != 'hmac-sha256.')
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_SIG << 8) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);

      // Check if the key is known
      $keyName = substr ($tsigRecord->getLabel (), 0, -1);

      if (!isset ($keyStore [$keyName]))
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_KEY << 8) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);

      // Check if the signature-time is valid
      if (abs (time () - $tsigRecord->getSignatureTime ()) > $tsigRecord->getTimeWindow ())
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_SIG << 8) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);

      // Create a copy of this message
      $messageCopy = clone $dnsMessage;
      $messageCopy->removeAdditional ($messageCopy->getAdditionals ()->getRecords (qcEvents_Stream_DNS_Message::TYPE_TSIG)->pop ());
      
      if ($messageCopy->getID () != $tsigRecord->getOriginalID ())
        $messageCopy->setID ($tsigRecord->getOriginalID ());
      
      // Create MAC for that message
      $messageMac = hash_hmac (
        'sha256',
        $messageCopy->toString () .
        qcEvents_Stream_DNS_Message::setLabel ($tsigRecord->getLabel ()) .
        pack ('nN', $tsigRecord->getClass (), $tsigRecord->getTTL ()) .
        qcEvents_Stream_DNS_Message::setLabel ($tsigRecord->getAlgorithm ()) .
        pack ('Nnnnn', $tsigRecord->getSignatureTime () >> 16, $tsigRecord->getSignatureTime () & 0xFFFF, $tsigRecord->getTimeWindow (), $tsigRecord->getErrorCode (), strlen ($tsigRecord->getOtherData ())) .
        $tsigRecord->getOtherData (),
        base64_decode ($keyStore [$keyName]),
        true
      );
      
      return (strcmp ($messageMac, $tsigRecord->getSignature ()) == 0 ? qcEvents_Stream_DNS_Message::ERROR_NONE : ((qcEvents_Stream_DNS_Message::ERROR_BAD_SIG << 8) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH));
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
    
    // {{{ getAlgorithm
    /**
     * Retrive the used algorithm
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function getAlgorithm () : ?qcEvents_Stream_DNS_Label {
      return $this->algorithmName;
    }
    // }}}
    
    // {{{ getSignature
    /**
     * Retrive the Signature/MAC from this record
     * 
     * @access public
     * @return string
     **/
    public function getSignature () {
      return $this->macData;
    }
    // }}}

    // {{{ getSignatureTime
    /**
     * Retrive timestamp when the signature was supposed to be generated
     * 
     * @access public
     * @return int
     **/
    public function getSignatureTime () {
      return $this->timeSigned;
    }
    // }}}
    
    // {{{ getTimeWindow
    /**
     * Retrive the time-window the signature is supposed to be valid
     * 
     * @access public
     * @return int
     **/
    public function getTimeWindow () {
      return $this->timeWindow;
    }
    // }}}
    
    // {{{ getOriginalID
    /**
     * Retrive the original ID used for the dns-message
     * 
     * @access public
     * @return int
     **/
    public function getOriginalID () {
      return $this->originalID;
    }
    // }}}
    
    // {{{ getErrorCode
    /**
     * Retrive error-code from this record
     * 
     * @access public
     * @return int
     **/
    public function getErrorCode () {
      return $this->errorCode;
    }
    // }}}
    
    // {{{ getOtherData
    /**
     * Retrive other data from this record
     * 
     * @access public
     * @return string
     **/
    public function getOtherData () {
      return $this->otherData;
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