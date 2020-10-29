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
    
    /* Default HMAC to use when not specified */
    const DEFAULT_HMAC = 'hmac-sha256';
    
    /* List of supported HMAC-Algorithms (plus mapping to hash_hmac()) */
    private static $supportedAlgorihtms = array (
      'hmac-md5.sig-alg.reg.int' => 'md5',
      'hmac-sha1' => 'sha1',
      'hmac-sha224' => 'sha224',
      'hmac-sha256' => 'sha256',
      'hmac-sha384' => 'sha384',
      'hmac-sha512' => 'sha512',
    );
    
    private $algorithmName = null;
    private $timeSigned = 0;
    private $timeWindow = 0;
    private $macData = '';
    private $originalID = 0;
    private $errorCode = 0;
    private $otherData = '';
    
    // {{{ getRecordFromMessage
    /**
     * Retrive TSIG-Record from DNS-Message
     * 
     * @param qcEvents_Stream_DNS_Message $dnsMessage
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Record_TSIG
     **/
    public static function getRecordFromMessage (qcEvents_Stream_DNS_Message $dnsMessage) {
      $tsigRecord = null;
      
      foreach ($dnsMessage->getAdditionals () as $additionalRecord)
        if ($tsigRecord !== null)
          throw new InvalidArgumentException ('TSIG-Record not at last position');
        
        elseif ($additionalRecord instanceof qcEvents_Stream_DNS_Record_TSIG)
          $tsigRecord = $additionalRecord;
      
      return $tsigRecord;
    }
    // }}}
    
    // {{{ getKeyName
    /**
     * Retrive the name of a TSIG-Key used on a given DNS-Message
     * 
     * @param qcEvents_Stream_DNS_Message $dnsMessage
     * 
     * @access public
     * @return string
     **/
    public static function getKeyName (qcEvents_Stream_DNS_Message $dnsMessage) {
      try {
        if ($tsigRecord = static::getRecordFromMessage ($dnsMessage))
          return $tsigRecord->getLabel ();
      } catch (Throwable $error) {
        return null;
      }
    }
    // }}}
    
    // {{{ messageDigest
    /**
     * Create digest for a DNS-Message
     * 
     * @param qcEvents_Stream_DNS_Message $dnsMessage
     * @param qcEvents_Stream_DNS_Record_TSIG $tsigRecord
     * @param qcEvents_Stream_DNS_Message $initialMessage (optional)
     * 
     * @access public
     * @return string
     **/
    public static function messageDigest (qcEvents_Stream_DNS_Message $dnsMessage, qcEvents_Stream_DNS_Record_TSIG $tsigRecord, qcEvents_Stream_DNS_Message $initialMessage = null) {
      // Sanatize the message
      if ($dormantTSIG = static::getRecordFromMessage ($dnsMessage)) {
        $dnsMessage = clone $dnsMessage;
        $dnsMessage->removeAdditional (static::getRecordFromMessage ($dnsMessage));
      }
      
      if ($dnsMessage->getID () != $tsigRecord->getOriginalID ()) {
        $dnsMessage = clone $dnsMessage;
        
        $dnsMessage->setID ($tsigRecord->getOriginalID ());
      }
      
      // Gather all required data
      $dnsMessageData = $dnsMessage->toString ();
      $keyName = qcEvents_Stream_DNS_Message::setLabel ($tsigRecord->getLabel ());
      $algorithmName = qcEvents_Stream_DNS_Message::setLabel ($tsigRecord->getAlgorithm ());
      $otherData = $tsigRecord->getOtherData ();
      
      if ($initialMessage && $initialMessage->isQuestion () && ($initialTSIG = static::getRecordFromMessage ($initialMessage)))
        $initialMac = pack ('n', strlen ($initialTSIG->macData)) . $initialTSIG->macData;
      else
        $initialMac = '';
      
      // Pack the digest
      return
        $initialMac .
        pack (
          'a' . strlen ($dnsMessageData) . 'a' . strlen ($keyName) . 'nNa' . strlen ($algorithmName) . 'Nnnnna' . strlen ($otherData),
          $dnsMessage->toString (),
          $keyName,
          $tsigRecord->getClass (),
          $tsigRecord->getTTL (),
          $algorithmName,
          $tsigRecord->getSignatureTime () >> 16,
          $tsigRecord->getSignatureTime () & 0xFFFF,
          $tsigRecord->getTimeWindow (),
          $tsigRecord->getErrorCode (),
          strlen ($otherData),
          $otherData
        );
    }
    // }}}
    
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
      try {
        $tsigRecord = static::getRecordFromMessage ($dnsMessage);
        
        // No TSIG to check
        if (!$tsigRecord)
          return null;
      } catch (InvalidArgumentException $error) {
        return qcEvents_Stream_DNS_Message::ERROR_FORMAT;
      } catch (Throwable $error) {
        return null;
      }
      
      // Check for supported message-algorithm
      $algorithmName = substr ($tsigRecord->getAlgorithm (), 0, -1);
      
      if (!isset (static::$supportedAlgorihtms [$algorithmName]))
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_SIG << 16) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);
      
      // Check if the key is known
      $keyName = substr ($tsigRecord->getLabel (), 0, -1);

      if (!isset ($keyStore [$keyName]))
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_KEY << 16) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);

      // Check if the signature-time is valid
      if (abs (time () - $tsigRecord->getSignatureTime ()) > $tsigRecord->getTimeWindow ())
        return ((qcEvents_Stream_DNS_Message::ERROR_BAD_TIME << 16) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH);

      // Create MAC for that message
      $messageMac = hash_hmac (
        static::$supportedAlgorihtms [$algorithmName],
        static::messageDigest ($dnsMessage, $tsigRecord),
        base64_decode ($keyStore [$keyName]),
        true
      );
      
      return (strcmp ($messageMac, $tsigRecord->getSignature ()) == 0 ? qcEvents_Stream_DNS_Message::ERROR_NONE : ((qcEvents_Stream_DNS_Message::ERROR_BAD_SIG << 16) | qcEvents_Stream_DNS_Message::ERROR_NOT_AUTH));
    }
    // }}}
    
    // {{{ createErrorResponse
    /**
     * Create a signed TSIG-Error-Response
     * 
     * @param qcEvents_Stream_DNS_Message $dnsMessage
     * @param array $keyStore
     * @param int  $errorCode (optional)
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Message
     * @throws InvalidArgumentException
     **/
    public static function createErrorResponse (qcEvents_Stream_DNS_Message $dnsMessage, array $keyStore, $errorCode = null) {
      // Find the original TSIG-Record to respond to
      if (!is_object ($tsigRecord = static::getRecordFromMessage ($dnsMessage)))
        throw new InvalidArgumentException ('Cannot reply to DNS-Message without TSIG-Record');
      
      // Check wheter to auto-generate error-code
      if ($errorCode === null)
        $errorCode = static::verifyMessage ($dnsMessage);
      
      // Check wheter to sign the response
      $algorithmName = substr ($tsigRecord->algorithmName, 0, -1);
      
      if (!isset (static::$supportedAlgorihtms [$algorithmName]))
        $algorithmName = static::DEFAULT_HMAC;
      
      $keyName = substr ($tsigRecord->getLabel (), 0, -1);
      
      if (!isset ($keyStore [$keyName]))
        $signResponse = false;
      else
        $signResponse = true;
      
      // Create a response-message
      $dnsResponse = $dnsMessage->createResponse ();
      $dnsResponse->setOpcode ($dnsMessage->getOpcode ());
      $dnsResponse->setError ($errorCode & 0xFF);
      
      $responseRecord = new static;
      $responseRecord->setLabel (clone $tsigRecord->getLabel ());
      $responseRecord->algorithmName = clone $tsigRecord->algorithmName;
      $responseRecord->timeSigned = time ();
      $responseRecord->timeWindow = $tsigRecord->timeWindow;
      $responseRecord->originalID = $tsigRecord->originalID;
      $responseRecord->errorCode = (($errorCode >> 16) & 0xFFFF);
      $responseRecord->otherData = '';
      
      if ($signResponse)
        $responseRecord->macData = hash_hmac (
          static::$supportedAlgorihtms [$algorithmName],
          static::messageDigest ($dnsResponse, $responseRecord, $dnsMessage),
          base64_decode ($keyStore [$keyName]),
          true
        );
      
      $dnsResponse->addAdditional ($responseRecord);
      
      return $dnsResponse;
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
    
    // {{{ getClass
    /**
     * Retrive the class of this record
     * 
     * @access public
     * @return enum
     **/
    public function getClass () {
      return qcEvents_Stream_DNS_Message::CLASS_ANY;
    }
    // }}}
    
    // {{{ getAlgorithm
    /**
     * Retrive the used algorithm
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function getAlgorithm () {
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
      return
        qcEvents_Stream_DNS_Message::setLabel ($this->algorithmName, $dataOffset, $dnsLabels) .
        $this::writeInt48 ($this->timeSigned) .
        $this::writeInt16 ($this->timeWindow) .
        $this::writeInt16 (strlen ($this->macData)) .
        $this->macData .
        $this::writeInt16 ($this->originalID) .
        $this::writeInt16 ($this->errorCode) .
        $this::writeInt16 (strlen ($this->otherData)) .
        $this->otherData;
    }
    // }}}
  }

?>