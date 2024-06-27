<?php

  /**
   * quarxConnect Events - DNS Resource Record
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

  namespace quarxConnect\Events\Stream\DNS;

  use InvalidArgumentException;
  use LengthException;
  use RangeException;
  use UnexpectedValueException;

  class Record
  {
    protected const DEFAULT_TYPE = null;
    protected const DEFAULT_CLASS = null;
    protected const DEFAULT_TTL = null;

    /**
     * Registry for record-classes
     *
     * @var array
     **/
    private static array $Records = [];

    /**
     * [QName] The Label that is asked for
     *
     * @var Label|null
     **/
    private ?Label $Label = null;

    /**
     * [QType] Type of this DNS-RR
     *
     * @var int
     **/
    private int $Type = Message::TYPE_ANY;

    /**
     * [QClass] Class of this DNS-RR
     *
     * @var int
     **/
    private int $Class = Message::CLASS_INTERNET;

    /**
     * [TTL] Time-to-live of this DNS-RR
     *
     * @var int
     **/
    private int $TTL = 0;

    /**
     * [RData] Payload of this DNS-RR
     *
     * @var string
     **/
    public string $Payload = '';

    // {{{ fromString
    /**
     * Try to create a new DNS-Record from string
     *
     * @param string $dnsData
     * @param int $dataOffset
     * @param int|null $dataLength (optional)
     *
     * @access public
     * @return Record
     *
     * @throws InvalidArgumentException
     * @throws LengthException
     * @throws UnexpectedValueException
     **/
    public static function fromString (string &$dnsData, int &$dataOffset, int $dataLength = null): Record
    {
      // Check if there is enough data available
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      if ($dataLength < $dataOffset + 10)
        throw new LengthException ('DNS-Record too short');

      // Retrieve the label of this record
      $recordLabel = Message::getLabel ($dnsData, $dataOffset);

      // Retrieve type, class and TTL
      if ($dataLength < $dataOffset + 8)
        throw new LengthException ('DNS-Record too short');

      $recordType  = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordClass = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordTTL   = self::parseInt32 ($dnsData, $dataOffset, $dataLength);

      // Retrieve the payload
      $recordDataLength = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $recordDataOffset = $dataOffset;

      if ($dataLength < $dataOffset + $recordDataLength)
        throw new LengthException ('DNS-Record too short');

      $recordPayload = substr ($dnsData, $dataOffset, $recordDataLength);
      $dataOffset += $recordDataLength;

      // Create a new record
      if (isset (self::$Records [$recordType]))
        $recordImplementationClass = self::$Records [$recordType];
      elseif (($typeName = Message::getTypeName ($recordType)) && class_exists (__CLASS__ . '\\' . $typeName))
        $recordImplementationClass = __CLASS__ . '\\' . $typeName;
      else
        $recordImplementationClass = get_called_class ();

      $dnsRecord = new $recordImplementationClass ($recordLabel, $recordTTL, $recordType, $recordClass);

      // Try to parse the payload
      $dnsRecord->Payload = $recordPayload;

      if ($recordDataLength > 0)
        $dnsRecord->parsePayload ($dnsData, $recordDataOffset, $recordDataOffset + $recordDataLength);

      return $dnsRecord;
    }
    // }}}

    // {{{ registerRecordClass
    /**
     * Register a class for a given record-type
     *
     * @param int $Type
     * @param string $Class
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     **/
    public static function registerRecordClass (int $Type, string $Class): void
    {
      // Make sure that this is a child of ourselves
      if (!is_a ($Class, __CLASS__, true))
        throw new InvalidArgumentException ('Class given must extend ' . __CLASS__);

      // Store the classname
      self::$Records [$Type] = $Class;
    }
    // }}}

    // {{{ __construct
    /**
     * Create a new DNS-Record
     *
     * @param Label|string|null $Label (optional)
     * @param int|null $TTL (optional)
     * @param int|null $Type (optional)
     * @param int|null $Class (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Label|string $Label = null, int $TTL = null, int $Type = null, int $Class = null)
    {
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;

      if ($Class === null)
        $Class = $this::DEFAULT_CLASS;

      if ($TTL === null)
        $TTL = $this::DEFAULT_TTL;

      if ($Label !== null)
        $this->setLabel ($Label);

      if ($Type !== null)
        $this->setType ($Type);

      if ($Class !== null)
        $this->setClass ($Class);

      if ($TTL !== null)
        $this->setTTL ($TTL);
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
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' ' . Message::getTypeName ($this->getType ()) . ' ' . bin2hex ($this->Payload);
    }
    // }}}

    // {{{ getLabel
    /**
     * Retrieve the label of this record
     *
     * @access public
     * @return Label|null
     **/
    public function getLabel (): ?Label
    {
      return $this->Label;
    }
    // }}}

    // {{{ setLabel
    /**
     * Set the label of this record
     *
     * @param Label|string $recordLabel
     *
     * @access public
     * @return void
     **/
    public function setLabel (Label|string $recordLabel): void
    {
      if (!($recordLabel instanceof Label))
        $recordLabel = new Label (explode ('.', $recordLabel));

      $this->Label = $recordLabel;
    }
    // }}}

    // {{{ getType
    /**
     * Retrieve the type of this record
     *
     * @access public
     * @return int
     **/
    public function getType (): int
    {
      return $this->Type;
    }
    // }}}

    // {{{ setType
    /**
     * Set the type of this record
     *
     * @param int $Type
     *
     * @access public
     * @return void
     **/
    public function setType (int $Type): void
    {
      # TODO: Validate the type

      $this->Type = $Type;
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
      return $this->Class;
    }
    // }}}

    // {{{ getClassName
    /**
     * Retrieve a human-readable representation of our class
     *
     * @access protected
     * @retrun string
     **/
    protected function getClassName (): string
    {
      return Message::getClassName ($this->getClass ());
    }
    // }}}

    // {{{ setClass
    /**
     * Set the class of this record
     *
     * @param int $Class
     *
     * @access public
     * @return void
     **/
    public function setClass (int $Class): void
    {
      # TODO: Validate the class

      $this->Class = $Class;
    }
    // }}}

    // {{{ getTTL
    /**
     * Retrieve the time-to-live of this record
     *
     * @access public
     * @return int
     **/
    public function getTTL (): int
    {
      return $this->TTL;
    }
    // }}}

    // {{{ setTTL
    /**
     * Set the TTL of this record
     *
     * @param int $TTL
     *
     * @access public
     * @return void
     *
     * @throws RangeException
     **/
    public function setTTL (int $TTL): void
    {
      if (($this->TTL < 0) || ($this->TTL > 0xFFFFFFFF))
        throw new RangeException ('TTL must be an unsigned 32-bit integer');

      $this->TTL = $TTL;
    }
    // }}}

    // {{{ getPayload
    /**
     * Retrieve the entire payload-blob of this record
     *
     * @access public
     * @return string
     **/
    public function getPayload (): string
    {
      return $this->Payload;
    }
    // }}}

    
    // {{{ parse
    /**
     * Parse binary data into this object
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
    public function parse (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      // Check if there is enough data available
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      if ($dataLength < $dataOffset + 10)
        throw new LengthException ('DNS-Record too short');

      // Retrieve the label of this record
      $this->Label = Message::getLabel ($dnsData, $dataOffset);

      // Retrieve type, class and TTL
      $this->Type  = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->Class = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->TTL   = self::parseInt32 ($dnsData, $dataOffset, $dataLength);

      // Retrieve the payload
      $recordDataLength = self::parseInt16 ($dnsData, $dataOffset, $dataLength);
      $this->Payload = substr ($dnsData, $dataOffset, $recordDataLength);
      $recordDataOffset = $dataOffset;
      $dataOffset += $recordDataLength;

      if ($recordDataLength == 0)
        return;

      $this->parsePayload ($dnsData, $recordDataOffset, $recordDataOffset + $recordDataLength);
    }
    // }}}

    // {{{ parseInt
    /**
     * Try to read an integer of an arbitrary size from binary data
     *
     * @param int $intSize
     * @param string $inputData
     * @param int $inputOffset
     * @param int|null $inputLength (optional)
     *
     * @access protected
     * @return int
     *
     * @throws LengthException
     **/
    protected static function parseInt (int $intSize, string $inputData, int &$inputOffset, int $inputLength = null): int
    {
      if ($inputLength === null)
        $inputLength = strlen ($inputData);

      if ($inputLength < $inputOffset + $intSize)
        throw new LengthException ('Input-Data too short to read integer');

      $resultValue = 0;

      for ($i = 0; $i < $intSize; $i++)
        $resultValue = ($resultValue << 8) | ord ($inputData [$inputOffset++]);

      return $resultValue;
    }
    // }}}

    // {{{ parseInt16
    /**
     * Read a 16-bit integer from binary
     *
     * @param string $inputData
     * @param int $inputOffset
     * @param int|null $inputLength (optional)
     *
     * @access protected
     * @return int
     **/
    protected static function parseInt16 (string $inputData, int &$inputOffset, int $inputLength = null): int
    {
      return static::parseInt (2, $inputData, $inputOffset, $inputLength);
    }
    // }}}

    // {{{ parseInt32
    /**
     * Read a 32-bit integer from binary
     *
     * @param string $inputData
     * @param int $inputOffset
     * @param int|null $inputLength (optional)
     *
     * @access protected
     * @return int
     **/
    protected static function parseInt32 (string $inputData, int &$inputOffset, int $inputLength = null): int
    {
      return static::parseInt (4, $inputData, $inputOffset, $inputLength);
    }
    // }}}

    // {{{ parseInt48
    /**
     * Try to read a 48-bit integer from binary data
     *
     * @param string $inputData
     * @param int $inputOffset
     * @param int|null $inputLength (optional)
     *
     * @access protected
     * @return int
     **/
    protected static function parseInt48 (string $inputData, int &$inputOffset, int $inputLength = null): int
    {
      return self::parseInt (6, $inputData, $inputOffset, $inputLength);
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
      // No-Op
    }
    // }}}

    // {{{ toString
    /**
     * Convert this question into a string
     *
     * @param int $Offset
     * @param array &$Labels
     *
     * @access public
     * @return string
     **/
    public function toString (int $Offset, array &$Labels): string
    {
      // Create the record-header
      $Data =
        Message::setLabel ($this->Label, $Offset, $Labels) .
        $this::writeInt16 ($this->Type) .
        $this::writeInt16 ($this->getClass ()) .
        $this::writeInt32 ($this->TTL);

      // Retrieve the payload
      $Payload = $this->buildPayload ($Offset + strlen ($Data) + 2, $Labels);

      // Append the payload
      $Data .= $this::writeInt16 (strlen ($Payload)) . $Payload;

      return $Data;
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
      return $this->Payload;
    }
    // }}}

    // {{{ writeInt16
    /**
     * Create binary representation of a 16-bit-Integer
     *
     * @param int $intValue
     *
     * @access protected
     * @return string
     **/
    protected static function writeInt16 (int $intValue): string
    {
      return pack ('n', $intValue);
    }
    // }}}

    // {{{ writeInt32
    /**
     * Create binary representation of a 32-bit-Integer
     *
     * @param int $intValue
     *
     * @access protected
     * @return string
     **/
    protected static function writeInt32 (int $intValue): string
    {
      return pack ('N', $intValue);
    }
    // }}}

    // {{{ writeInt48
    /**
     * Create binary representation of a 48-bit-Integer
     *
     * @param int $intValue
     *
     * @access protected
     * @return string
     **/
    protected static function writeInt48 (int $intValue): string
    {
      return pack ('Nn', $intValue >> 16, $intValue & 0xFFFF);
    }
    // }}}

    // {{{ buildInt16
    /**
     * Create a binary representation of a 16-bit integer
     *
     * @param int $Value
     *
     * @access protected
     * @return string
     **/
    protected static function buildInt16 (int $Value): string
    {
      return static::writeInt16 ($Value);
    }
    // }}}

    // {{{ buildInt32
    /**
     * Create a binary representation of a 32-bit integer
     *
     * @param int $Value
     *
     * @access protected
     * @return string
     **/
    protected static function buildInt32 (int $Value): string
    {
      return static::writeInt32 ($Value);
    }
    // }}}
  }
