<?php

  /**
   * quarxConnect Events - TSIG DNS Resource Record
   * Copyright (C) 2020-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use InvalidArgumentException;
  use LengthException;
  use quarxConnect\Events\Stream\DNS;
  use UnexpectedValueException;

  class TSIG extends DNS\Record
  {
    /* Default Type of this record */
    protected const DEFAULT_TYPE = DNS\Message::TYPE_TSIG;

    /* Don't allow this record-type to be cached */
    protected const ALLOW_CACHING = false;

    /* Default HMAC to use when not specified */
    protected const DEFAULT_HMAC = 'hmac-sha256';

    /**
     * List of supported HMAC-Algorithms (plus mapping to hash_hmac())
     *
     * @var string[]
     */
    private static array $supportedAlgorithms = [
      'hmac-md5.sig-alg.reg.int' => 'md5',
      'hmac-sha1' => 'sha1',
      'hmac-sha224' => 'sha224',
      'hmac-sha256' => 'sha256',
      'hmac-sha384' => 'sha384',
      'hmac-sha512' => 'sha512',
    ];

    /**
     * Name of the algorithm used here
     *
     * @var DNS\Label|null
     **/
    private ?DNS\Label $algorithmName = null;
    private int $timeSigned = 0;
    private int $timeWindow = 0;
    private string $macData = '';
    private int $originalID = 0;
    private int $errorCode = 0;
    private string $otherData = '';

    // {{{ getRecordFromMessage
    /**
     * Retrieve TSIG-Record from DNS-Message
     *
     * @param DNS\Message $dnsMessage
     *
     * @access public
     * @return TSIG|null
     *
     * @throws InvalidArgumentException
     **/
    public static function getRecordFromMessage (DNS\Message $dnsMessage): ?TSIG
    {
      $tsigRecord = null;

      foreach ($dnsMessage->getAdditionals () as $additionalRecord)
        if ($tsigRecord !== null)
          throw new InvalidArgumentException ('TSIG-Record not at last position');

        elseif ($additionalRecord instanceof TSIG)
          $tsigRecord = $additionalRecord;

      return $tsigRecord;
    }
    // }}}

    // {{{ getKeyName
    /**
     * Retrieve the name of a TSIG-Key used on a given DNS-Message
     *
     * @param DNS\Message $dnsMessage
     *
     * @access public
     * @return string|null
     **/
    public static function getKeyName (DNS\Message $dnsMessage): ?string
    {
      $tsigRecord = static::getRecordFromMessage ($dnsMessage);

      if ($tsigRecord)
        return (string)$tsigRecord->getLabel ();

      return null;
    }
    // }}}

    // {{{ messageDigest
    /**
     * Create digest for a DNS-Message
     * 
     * @param DNS\Message $dnsMessage
     * @param TSIG $tsigRecord
     * @param DNS\Message|null $initialMessage (optional)
     *
     * @access public
     * @return string
     **/
    public static function messageDigest (DNS\Message $dnsMessage, TSIG $tsigRecord, DNS\Message $initialMessage = null): string
    {
      // Sanitize the message
      $dormantTSIG = static::getRecordFromMessage ($dnsMessage);

      if ($dormantTSIG) {
        $dnsMessage = clone $dnsMessage;
        $dnsMessage->removeAdditional (static::getRecordFromMessage ($dnsMessage));
      }

      if ($dnsMessage->getID () !== $tsigRecord->getOriginalID ()) {
        $dnsMessage = clone $dnsMessage;

        $dnsMessage->setID ($tsigRecord->getOriginalID ());
      }

      // Gather all required data
      $dnsMessageData = $dnsMessage->toString ();
      $keyName = DNS\Message::setLabel ($tsigRecord->getLabel ());
      $algorithmName = DNS\Message::setLabel ($tsigRecord->getAlgorithm ());
      $otherData = $tsigRecord->getOtherData ();

      if (
        $initialMessage &&
        $initialMessage->isQuestion () &&
        ($initialTSIG = static::getRecordFromMessage ($initialMessage))
      )
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
     * Verify the signature on a DNS-Message
     *
     * @param DNS\Message $dnsMessage
     * @param array $keyStore
     *
     * @access public
     * @return int
     **/
    public static function verifyMessage (DNS\Message $dnsMessage, array $keyStore): int
    {
      // Try to find a tsig-record
      try {
        $tsigRecord = static::getRecordFromMessage ($dnsMessage);
        
        // No TSIG to check
        if (!$tsigRecord)
          return DNS\Message::ERROR_NONE;
      } catch (InvalidArgumentException) {
        return DNS\Message::ERROR_FORMAT;
      }

      // Check for supported message-algorithm
      $algorithmName = substr ((string)$tsigRecord->getAlgorithm (), 0, -1);

      if (!isset (static::$supportedAlgorithms [$algorithmName]))
        return ((DNS\Message::ERROR_BAD_SIG << 16) | DNS\Message::ERROR_NOT_AUTH);

      // Check if the key is known
      $keyName = substr ((string)$tsigRecord->getLabel (), 0, -1);

      if (!isset ($keyStore [$keyName]))
        return ((DNS\Message::ERROR_BAD_KEY << 16) | DNS\Message::ERROR_NOT_AUTH);

      // Check if the signature-time is valid
      if (abs (time () - $tsigRecord->getSignatureTime ()) > $tsigRecord->getTimeWindow ())
        return ((DNS\Message::ERROR_BAD_TIME << 16) | DNS\Message::ERROR_NOT_AUTH);

      // Create MAC for that message
      $messageMac = hash_hmac (
        static::$supportedAlgorithms [$algorithmName],
        static::messageDigest ($dnsMessage, $tsigRecord),
        base64_decode ($keyStore [$keyName]),
        true
      );

      return (strcmp ($messageMac, $tsigRecord->getSignature ()) == 0 ? DNS\Message::ERROR_NONE : ((DNS\Message::ERROR_BAD_SIG << 16) | DNS\Message::ERROR_NOT_AUTH));
    }
    // }}}

    // {{{ createErrorResponse
    /**
     * Create a signed TSIG-Error-Response
     *
     * @param DNS\Message $dnsMessage
     * @param array $keyStore
     * @param int|null  $errorCode (optional)
     *
     * @access public
     * @return DNS\Message
     *
     * @throws InvalidArgumentException
     **/
    public static function createErrorResponse (DNS\Message $dnsMessage, array $keyStore, int $errorCode = null): DNS\Message
    {
      // Find the original TSIG-Record to respond to
      $tsigRecord = static::getRecordFromMessage ($dnsMessage);

      if (!is_object ($tsigRecord))
        throw new InvalidArgumentException ('Cannot reply to DNS-Message without TSIG-Record');

      // Check whether to auto-generate error-code
      if ($errorCode === null)
        $errorCode = self::verifyMessage ($dnsMessage, $keyStore);

      // Check whether to sign the response
      $algorithmName = substr ((string)$tsigRecord->algorithmName, 0, -1);

      if (!isset (static::$supportedAlgorithms [$algorithmName]))
        $algorithmName = static::DEFAULT_HMAC;

      $tsigLabel = $tsigRecord->getLabel ();
      $keyName = substr ((string)$tsigLabel, 0, -1);

      if (!isset ($keyStore [$keyName]))
        $signResponse = false;
      else
        $signResponse = true;

      // Create a response-message
      $dnsResponse = $dnsMessage->createResponse ();
      $dnsResponse->setOpcode ($dnsMessage->getOpcode ());
      $dnsResponse->setError ($errorCode & 0xFF);

      $responseRecord = new TSIG ();
      $responseRecord->setLabel (clone $tsigLabel);
      $responseRecord->algorithmName = clone $tsigRecord->algorithmName;
      $responseRecord->timeSigned = time ();
      $responseRecord->timeWindow = $tsigRecord->timeWindow;
      $responseRecord->originalID = $tsigRecord->originalID;
      $responseRecord->errorCode = (($errorCode >> 16) & 0xFFFF);
      $responseRecord->otherData = '';

      if ($signResponse)
        $responseRecord->macData = hash_hmac (
          static::$supportedAlgorithms [$algorithmName],
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
    public function __toString (): string
    {
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
     * Retrieve the class of this record
     *
     * @access public
     * @return int
     **/
    public function getClass (): int
    {
      return DNS\Message::CLASS_ANY;
    }
    // }}}

    // {{{ getAlgorithm
    /**
     * Retrieve the used algorithm
     *
     * @access public
     * @return DNS\Label|null
     **/
    public function getAlgorithm (): ?DNS\Label
    {
      return $this->algorithmName;
    }
    // }}}

    // {{{ getSignature
    /**
     * Retrieve the Signature/MAC from this record
     *
     * @access public
     * @return string
     **/
    public function getSignature (): string
    {
      return $this->macData;
    }
    // }}}

    // {{{ getSignatureTime
    /**
     * Retrieve timestamp when the signature was supposed to be generated
     *
     * @access public
     * @return int
     **/
    public function getSignatureTime (): int
    {
      return $this->timeSigned;
    }
    // }}}

    // {{{ getTimeWindow
    /**
     * Retrieve the time-window the signature is supposed to be valid
     *
     * @access public
     * @return int
     **/
    public function getTimeWindow (): int
    {
      return $this->timeWindow;
    }
    // }}}

    // {{{ getOriginalID
    /**
     * Retrieve the original ID used for the dns-message
     *
     * @access public
     * @return int
     **/
    public function getOriginalID (): int
    {
      return $this->originalID;
    }
    // }}}

    // {{{ getErrorCode
    /**
     * Retrieve error-code from this record
     *
     * @access public
     * @return int
     **/
    public function getErrorCode (): int
    {
      return $this->errorCode;
    }
    // }}}

    // {{{ getOtherData
    /**
     * Retrieve other data from this record
     *
     * @access public
     * @return string
     **/
    public function getOtherData (): string
    {
      return $this->otherData;
    }
    // }}}

    // {{{ parsePayload
    /**
     * Parse a given payload
     *
     * @param string $dnsData
     * @param int $dataOffset
     * @param int|null $dataLength (optional)
     *
     * @access public
     * @return void
     *
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      if (!($algorithmName = DNS\Message::getLabel ($dnsData, $dataOffset)))
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
     * Retrieve the payload of this record
     *
     * @param int $dataOffset
     * @param array &$dnsLabels
     *
     * @access public
     * @return string
     **/
    public function buildPayload (int $dataOffset, array &$dnsLabels): string
    {
      return
        DNS\Message::setLabel ($this->algorithmName, $dataOffset, $dnsLabels) .
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
