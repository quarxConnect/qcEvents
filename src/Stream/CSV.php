<?php

  /**
   * qcEvents - CSV-Stream
   * Copyright (C) 2016-2024 Bernd Holzmueller <bernd@quarxconnect.de>
   *
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   *
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types=1);

  namespace quarxConnect\Events\Stream;

  use quarxConnect\Events\Hookable;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\ABI;

  class CSV extends Hookable implements ABI\Consumer, ABI\Stream\Consumer {
    /**
     * Source-Stream for CSV-Reader
     *
     * @var ABI\Source|null
     **/
    private ABI\Source|null $dataSource = null;

    /**
     * Separator of fields on CSV-Stream
     *
     * @var string
     **/
    private string $csvSeparator = ',';

    /**
     * Enclosure for field-values
     *
     * @var string
     **/
    private string $csvEnclosure = '"';

    /**
     * End-of-Record-marker
     *
     * @var string
     **/
    private string $csvLineEnding = "\r\n";

    /**
     * Use CSV-Header
     *
     * @var boolean|array
     **/
    private bool|array $csvHeader = true;

    /**
     * Internal buffer
     *
     * @var string
     **/
    private string $csvBuffer = '';

    /**
     * Length of internal buffer
     *
     * @var integer
     **/
    private int $csvBufferLength = 0;

    /**
     * Currently parsed record
     *
     * @var array
     **/
    private array $csvRecord = [];

    // {{{ __construct
    /**
     * Create a new CSV-stream
     *
     * @param string $columnSeparator (optional) Separator-Character on stream
     * @param string $valueEnclosure (optional) Enclosure-Character on stream
     * @param string $lineEnd (optional) Line-Ending-Character on stream
     * @param array|bool $withHeader (optional) Treat first record as header (default)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (string $columnSeparator = null, string $valueEnclosure = null, string $lineEnd = null, array|bool $withHeader = null) {
      if ($columnSeparator !== null)
        $this->csvSeparator = $columnSeparator;

      if ($valueEnclosure !== null)
        $this->csvEnclosure = $valueEnclosure;

      if ($lineEnd !== null)
        $this->csvLineEnding = $lineEnd;

      if ($withHeader !== null)
        $this->csvHeader = (is_array ($withHeader) ? array_values ($withHeader) : $withHeader);
    }
    // }}}

    // {{{ consume
    /**
     * Consume a set of data
     *
     * @param mixed $sourceData
     * @param ABI\Source $dataSource
     *
     * @access public
     * @return void
     **/
    public function consume ($sourceData, ABI\Source $dataSource): void {
      // Append to internal buffer
      $this->csvBuffer .= $sourceData;
      $this->csvBufferLength += strlen ($sourceData);

      unset ($sourceData);

      $csvOffset = 0;
      $csvSeparatorLength = strlen ($this->csvSeparator);
      $csvLineEndLength = strlen ($this->csvLineEnding);
      $csvEnclosureLength = strlen ($this->csvEnclosure);

      while ($csvOffset < $this->csvBufferLength) {
        // Check if the field is escaped
        if (substr ($this->csvBuffer, $csvOffset, $csvEnclosureLength) === $this->csvEnclosure) {
          // Find next occurrence of enclosure
          $csvEnclosureOffset = $csvOffset + $csvEnclosureLength;

          do {
            // Find the next enclosure (if none was found, skip here)
            $csvEnclosureOffset = strpos ($this->csvBuffer, $this->csvEnclosure, $csvEnclosureOffset);

            if ($csvEnclosureOffset === false)
              break (2);

            // Check if its just an escaped enclosure
            if (substr ($this->csvBuffer, $csvEnclosureOffset + $csvEnclosureLength, $csvEnclosureLength) === $this->csvEnclosure) {
              $csvEnclosureOffset += $csvEnclosureLength + $csvEnclosureLength;

              continue;
            }

            break;
          } while ($csvEnclosureOffset < $this->csvBufferLength);

          // Check if there is data after the enclosure on the buffer
          if ($csvEnclosureOffset + $csvEnclosureLength >= $this->csvBufferLength)
            break;

          // Read the entire field
          $this->csvRecord [] = str_replace (
            $this->csvEnclosure . $this->csvEnclosure,
            $this->csvEnclosure,
            substr ($this->csvBuffer, $csvOffset + $csvEnclosureLength, $csvEnclosureOffset - $csvOffset - $csvEnclosureLength)
          );

          // Move the pointer
          $csvOffset = $csvEnclosureOffset + $csvEnclosureLength;

          unset ($csvEnclosureOffset);

          // Check for line-ending
          if (substr ($this->csvBuffer, $csvOffset, $csvLineEndLength) === $this->csvLineEnding) {
            $csvOffset += $csvLineEndLength;

            $this->csvPushRecord ();
          } elseif (substr ($this->csvBuffer, $csvOffset, $csvSeparatorLength) !== $this->csvSeparator)
            # TODO: How to handle this?
            trigger_error ('No separator next to enclosure');
          else
            $csvOffset += $csvSeparatorLength;

        // Check if there is the end of a normal field on the buffer
        } else {
          // Find relevant positions
          $separatorOffset = strpos ($this->csvBuffer, $this->csvSeparator, $csvOffset);
          $lineEndingOffset = strpos ($this->csvBuffer, $this->csvLineEnding, $csvOffset);

          // A separator was found before line-ending
          if (
            ($separatorOffset !== false) &&
            (($lineEndingOffset === false) || ($separatorOffset < $lineEndingOffset))
          ) {
            $this->csvRecord [] = substr ($this->csvBuffer, $csvOffset, $separatorOffset - $csvOffset);
            $csvOffset = $separatorOffset + $csvSeparatorLength;

          // A line-ending was found
          } elseif ($lineEndingOffset !== false) {
            $this->csvRecord [] = substr ($this->csvBuffer, $csvOffset, $lineEndingOffset - $csvOffset);
            $csvOffset = $lineEndingOffset + $csvLineEndLength;

            // Run callback and reset
            $this->csvPushRecord ();

          // Nothing relevant was found
          } else
            break;
        }
      }

      // Truncate the buffer
      $this->csvBuffer = substr ($this->csvBuffer, $csvOffset);
      $this->csvBufferLength -= $csvOffset;
    }
    // }}}

    // {{{ csvPushRecord
    /**
     * Push the currently cached record to the callback
     *
     * @access private
     * @return void
     **/
    private function csvPushRecord (): void {
      // Peek the record and reset
      $csvRecord = $this->csvRecord; 
      $this->csvRecord = [];

      // Ignore empty records
      $recordLength = count ($csvRecord);

      if ($recordLength == 0)
        return;

      // Check whether to use this as a header
      if ($this->csvHeader === true) {
        $this->csvHeader = $csvRecord;

        $this->___callback ('csvHeaderReceived', $this->csvHeader);

        return;
      }

      // Check whether to apply a header
      if ($this->csvHeader !== false) {
        $headerLength = count ($this->csvHeader);

        // Add empty columns to record
        if ($recordLength < $headerLength) {
          for (; $recordLength < $headerLength; $recordLength++)
            $csvRecord [] = null;

        // Truncate columns from record
        } elseif ($recordLength > $headerLength) {
          trigger_error ('Truncating record to match headers length');

          $csvRecord = array_slice ($csvRecord, 0, $headerLength);
        }

        $csvRecord = array_combine ($this->csvHeader, $csvRecord);
      }

      // Run the callback
      $this->___callback ('csvRecordReceived', $csvRecord);
    }
    // }}}

    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     *
     * @param ABI\Source $dataSource
     *
     * @access public
     * @return Promise
     **/
    public function initConsumer (ABI\Source $dataSource): Promise {
      // Assign the source
      $this->dataSource = $dataSource;

      // Run the callback
      return Promise::resolve ();
    }
    // }}}

    // {{{ initStreamConsumer
    /** 
     * Setup ourself to consume data from a stream
     *
     * @param ABI\Stream $dataSource
     *
     * @access public
     * @return Promise
     **/
    public function initStreamConsumer (ABI\Stream $dataSource): Promise {
      // Assign the source
      $this->dataSource = $dataSource;

      // Return solved promise
      return Promise::resolve ();
    }
    // }}}

    // {{{ deInitConsumer
    /**
     * Callback: A source was removed from this consumer
     *
     * @param ABI\Source $dataSource
     *
     * @access public
     * @return Promise
     **/
    public function deInitConsumer (ABI\Source $dataSource): Promise {
      // Remove the source
      $this->dataSource = null;

      $this->csvRecord = [];
      $this->csvBuffer = '';
      $this->csvBufferLength = 0;

      if (is_array ($this->csvHeader))
        $this->csvHeader = true;

      // Run the callback
      return Promise::resolve ();
    }
    // }}}

    // {{{ close
    /**   
     * Close this event-interface
     *
     * @access public
     * @return Promise
     **/
    public function close (): Promise {
      if ($this->csvBufferLength > 0)
        $this->consume ($this->csvLineEnding, $this->dataSource);

      $this->___callback ('eventClosed');

      return Promise::resolve ();
    }
    // }}}

    // {{{ eventClosed
    /**
     * Callback: We were closed
     *
     * @access protected
     * @return void
     **/
    protected function eventClosed (): void { }
    // }}}

    // {{{ csvHeaderReceived
    /**
     * The header (first record) of a csv was received from stream
     *
     * @param array $csvHeader Indexed array with header
     *
     * @access protected
     * @return void
     **/
    protected function csvHeaderReceived (array $csvHeader): void { }
    // }}}

    // {{{ csvRecordReceived
    /**
     * A record was received from stream
     *
     * @param array $csvRecord Associative array with csv-values
     *
     * @access protected
     * @return void
     **/
    protected function csvRecordReceived (array $csvRecord): void { }
    // }}}
  }
