<?php

  /**
   * quarxConnect Events - DNS DS Resource Record
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

  use quarxConnect\Events\Stream\DNS;

  class DS extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x2B;

    public const DIGEST_SHA1 = 0x01;

    private int $keyTag     = 0x0000;
    private int $Algorithm  = 0x00;
    private int $digestType = 0x00;
    private string $Digest  = '';

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' DS ' . $this->keyTag . ' ' . $this->Algorithm . ' ' . $this->digestType . ' ' . bin2hex ($this->Digest);
    }
    // }}}

    // {{{ getKeyTag
    /**
     * Retrieve the Tag of the assigned key here
     *
     * @access public
     * @return int
     **/
    public function getKeyTag (): int
    {
      return $this->keyTag;
    }
    // }}}

    // {{{ getAlgorithm
    /**
     * Retrieve the identifier of the used algorithm
     *
     * @access public
     * @return int
     **/
    public function getAlgorithm (): int
    {
      return $this->Algorithm;
    }
    // }}}

    // {{{ getDigestType
    /**
     * Retrieve the identifier of the used digest
     *
     * @access public
     * @return int
     **/
    public function getDigestType (): int
    {
      return $this->digestType;
    }
    // }}}

    // {{{ getDigest
    /**
     * Retrieve the digest
     *
     * @access public
     * @return string
     **/
    public function getDigest (): string
    {
      return $this->Digest;
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
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
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
     * Retrieve the payload of this record
     *
     * @param int $dataOffset
     * @param array $dnsLabels
     *
     * @access public
     * @return string
     **/
    public function buildPayload (int $dataOffset, array &$dnsLabels): string {
      return
        self::buildInt16 ($this->keyTag) .
        chr ($this->Algorithm & 0xFF) .
        chr ($this->digestType & 0xFF) .
        $this->Digest;
    }
    // }}}
  }
