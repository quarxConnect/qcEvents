<?php

  /**
   * quarxConnect Events - DNS RRSIG Resource Record
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use ASN1\ObjectID;
  use InvalidArgumentException;
  use LengthException;
  use quarxConnect\Events\Stream\DNS;

  class RRSIG extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x2E;

    private int $typeCovered   = 0x0000;
    private int $Algorithm     = 0x00;
    private int $Labels        = 0x00;
    private int $originalTTL   = 0x00000000;
    private int $sigExpiration = 0x00000000;
    private int $sigInception  = 0x00000000;
    private int $keyTag        = 0x0000;
    private DNS\Label|null $SignersName = null;
    private string $Signature  = '';

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' RRSIG TYPE' . $this->typeCovered . ' ' . $this->Algorithm . ' ' . $this->Labels . ' ' . $this->originalTTL . ' ' . date ('YmdHis', $this->sigExpiration) . ' ' . date ('YmdHis', $this->sigInception) . ' ' . $this->keyTag . ' ' . $this->SignersName . ' ' . base64_encode ($this->Signature);
    }
    // }}}

    // {{{ getCoveredType
    /**
     * Retrieve the type of record that is signed by this one
     *
     * @access public
     * @return int
     **/
    public function getCoveredType (): int
    {
      return $this->typeCovered;
    }
    // }}}

    // {{{ getAlgorithm
    /**
     * Retrieve the DNSSEC-Identifier of the used algorithm
     *
     * @access public
     * @return int
     **/
    public function getAlgorithm (): int
    {
      return $this->Algorithm;
    }
    // }}}

    // {{{ getAlgorithmObjectID
    /**
     * Retrieve the Algorithm of this signature as ASN.1 Object ID
     *
     * @access public
     * @return ObjectID
     **/
    public function getAlgorithmObjectID (): ObjectID
    {
      return DNSKEY::algorithmIDtoObjectID ($this->Algorithm);
    }
    // }}}

    // {{{ getLabelCount
    /**
     * Retrieve the number of label-elements that were signed by this record
     *
     * @access public
     * @return int
     **/
    public function getLabelCount (): int
    {
      return $this->Labels;
    }
    // }}}

    // {{{ getOriginalTTL
    /**
     * Retrieve the original time-to-live of the signed records
     *
     * @access public
     * @return int
     **/
    public function getOriginalTTL (): int
    {
      return $this->originalTTL;
    }
    // }}}

    // {{{ getExpireDate
    /**
     * Retrieve the unix-timestamp when this record expires
     *
     * @access public
     * @return int
     **/
    public function getExpireDate (): int
    {
      return $this->sigExpiration;
    }
    // }}}

    // {{{ getInceptionDate
    /**
     * Retrieve unix-timestamp when this record will be replaced by a new one
     *
     * @access public
     * @return int
     **/
    public function getInceptionDate (): int
    {
      return $this->sigInception;
    }
    // }}}

    // {{{ getKeyTag
    /**
     * Retrieve the key-tag of the public key that created this signature
     *
     * @access public
     * @return int
     **/
    public function getKeyTag (): int
    {
      return $this->keyTag;
    }
    // }}}

    // {{{ getSigner
    /**
     * Retrieve the dns-label of the one who signed this record
     *
     * @access public
     * @return DNS\Label|null
     **/
    public function getSigner (): ?DNS\Label {
      return $this->SignersName;
    }
    // }}}

    // {{{ getSignature
    /**
     * Retrieve the actual signature carried on this record
     *
     * @access public
     * @return string
     **/
    public function getSignature (): string
    {
      return $this->Signature;
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
     * @throws InvalidArgumentException
     * @throws LengthException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      if ($dataLength < $dataOffset + 18)
        throw new LengthException ('DNS-Record too short (RRSIG)');

      $typeCovered = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $Algorithm = ord ($dnsData [$dataOffset++]);
      $Labels = ord ($dnsData [$dataOffset++]);
      $originalTTL = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $sigExpiration = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $sigInception = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $keyTag = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $SignersName = DNS\Message::getLabel ($dnsData, $dataOffset, false);

      $this->typeCovered   = $typeCovered;
      $this->Algorithm     = $Algorithm;
      $this->Labels        = $Labels;
      $this->originalTTL   = $originalTTL;
      $this->sigExpiration = $sigExpiration;
      $this->sigInception  = $sigInception;
      $this->keyTag        = $keyTag;
      $this->SignersName   = $SignersName;
      $this->Signature     = substr ($dnsData, $dataOffset, $dataLength - $dataOffset);
    }
    // }}}

    // {{{ buildPayload
    /**
     * Retrieve the payload of this record
     *
     * @param int $dataOffset
     * @param array $dnsLabels
     *
     * @access public
     * @return string
     **/
    public function buildPayload (int $dataOffset, array &$dnsLabels): string
    {
      return 
        self::buildInt16 ($this->typeCovered) .
        chr ($this->Algorithm & 0xFF) .
        chr ($this->Labels & 0xFF) .
        self::buildInt32 ($this->originalTTL) .
        self::buildInt32 ($this->sigExpiration) .
        self::buildInt32 ($this->sigInception) .
        self::buildInt16 ($this->keyTag) .
        DNS\Message::setLabel ($this->SignersName) .
        $this->Signature;
    }
    // }}}
  }
