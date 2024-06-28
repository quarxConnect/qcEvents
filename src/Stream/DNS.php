<?php

  /**
   * quarxConnect Events - Generic DNS Handling
   * Copyright (C) 2018-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream;

  use InvalidArgumentException;
  use quarxConnect\Events;
  use RuntimeException;
  use Throwable;

  class DNS implements Events\ABI\Consumer, Events\ABI\Stream\Consumer
  {
    use Events\Feature\Hookable;

    /* Internal DNS-Buffer for TCP-Mode */
    private string $dnsBuffer = '';

    /* Expected length of dnsBuffer */
    private int|null $dnsLength = null;

    /* Active queries */
    private array $dnsQueries = [];

    /* Timeouts of queries */
    private array $dnsTimeouts = [];

    /* Timeout for queries */
    private float $dnsTimeout = 4;

    /* Forced Datagram-size for EDNS-Messages */
    private int $dnsDatagramSize = 0;

    /* Source of our pipe */
    private Events\ABI\Source|null $dataSource = null;

    // {{{ getSource
    /**
     * Retrieve the source for this DNS-Stream
     *
     * @access public
     * @return Events\ABI\Source
     **/
    public function getSource (): Events\ABI\Source
    {
      return $this->dataSource;
    }
    // }}}

    // {{{ setTimeout
    /**
     * Set timeout for DNS-Questions
     *
     * @param float $queryTimeout
     *
     * @access public
     * @return void
     **/
    public function setTimeout (float $queryTimeout): void
    {
      $this->dnsTimeout = $queryTimeout;
    }
    // }}}

    // {{{ dnsParseMessage
    /**
     * Parse an DNS-Message
     *
     * @param string $Data
     *
     * @access protected
     * @return DNS\Message
     *
     * @throws InvalidArgumentException
     **/
    protected function dnsParseMessage (string $Data): DNS\Message
    {
      $Message = new DNS\Message ();

      // Try to parse the message and push back if everything was successful
      $rCode = $Message->parse ($Data);

      if ($rCode === DNS\Message::ERROR_NONE)
        return $Message;

      // Check for a generic failure
      trigger_error ('Received Malformed DNS-Message, dropping with error-response');

      // Handle a DNS-Error
      $Response = $Message->createClonedResponse ();
      $Response->setError ($rCode);

      $this->dnsStreamSendMessage ($Response);

      throw new InvalidArgumentException ('Failed to parse the message');
    }
    // }}}

    // {{{ dnsStreamSendMessage
    /**
     * Write a DNS-Message to the wire
     *
     * @param DNS\Message $Message
     *
     * @access public
     * @return Events\Promise
     **/
    public function dnsStreamSendMessage (DNS\Message $Message): Events\Promise
    {
      // Make sure we have a source available
      if (!$this->dataSource)
        return Events\Promise::reject (new RuntimeException ('No source available'));

      // Check if this is a query
      $Query = $Message->isQuestion ();

      // Try to override datagram-size
      if ($this->dnsDatagramSize > 512) {
        $Message->isExtended (true);
        $Message->setDatagramSize ($this->dnsDatagramSize);
      }

      // Convert the Message into a string
      $Data = $Message->toString ();

      // Handle UDP-Writes
      if (
        ($this->dataSource instanceof Events\Socket) &&
        ($this->dataSource->isUDP ())
      ) {
        // Check the maximum size for datagram-transport
        if (!$Query && isset ($this->dnsQueries [$Message->getID ()]))
          $Size = $this->dnsQueries [$Message->getID ()]->getDatagramSize ();
        else
          $Size = $Message->getDatagramSize ();

        // Make sure that the payload is not too long
        while (strlen ($Data) > $Size) {
          try {
            $Message->truncate ();
          } catch (Throwable $truncateError) {
            return Events\Promise::reject ($truncateError);
          }

          $Data = $Message->toString ();
        }

      // Handle TCP-Writes
      } else
        $Data = chr ((strlen ($Data) & 0xFF00) >> 8) . chr (strlen ($Data) & 0xFF) . $Data;

      // Add to local storage if it is a query
      if (
        $Query &&
        ($this->dataSource instanceof Events\ABI\Common)
      ) {
        $this->dnsQueries [$Message->getID ()] = $Message;
        $this->dnsAddTimeout ($Message);

      // Or remove from queue if this is a response
      } elseif (!$Query)
        unset ($this->dnsQueries [$Message->getID ()]);

      return $this->dataSource->write ($Data);
    }
    // }}}

    // {{{ dnsStreamParse
    /**
     * Parse a received DNS-Message
     *
     * @param string $Data
     *
     * @access private
     * @return void
     **/
    private function dnsStreamParse (string $Data): void
    {
      // Try to parse the 
      $Message = $this->dnsParseMessage ($Data);

      // Fire initial callback
      $this->___callback ('dnsMessageReceived', $Message);

      // Process depending on message-type
      if (!$Message->isQuestion ()) {
        $mID = $Message->getID ();

        // Check if we have the corresponding query saved
        unset ($this->dnsQueries [$mID]);

        // Make sure our timeout is removed
        if (isset ($this->dnsTimeouts [$mID])) {
          $this->dnsTimeouts [$mID]->cancel ();

          unset ($this->dnsTimeouts [$mID]);
        }

        $this->___callback ('dnsResponseReceived', $Message);

        return;
      }

      $this->dnsQueries [$Message->getID ()] = $Message;
      $this->dnsAddTimeout ($Message);

      $this->___callback ('dnsQuestionReceived', $Message);
    }
    // }}}

    // {{{ dnsAddTimeout
    /**
     * Set up a timeout for a given message
     *
     * @param DNS\Message $Message
     *
     * @access private
     * @return void
     **/
    private function dnsAddTimeout (DNS\Message $Message): void
    {
      // Check if we can set up timeouts at all
      if (
        !$this->dataSource ||
        !($eventBase = $this->dataSource->getEventBase ())
      )
        return;

      $mID = $Message->getID ();

      // Check whether just to restart an old timeout
      if (isset ($this->dnsTimeouts [$mID])) {
        $this->dnsTimeouts [$mID]->restart ();

        return;
      }

      // Create a new timeout
      $this->dnsTimeouts [$mID] = $eventBase->addTimeout ($this->dnsTimeout);
      $this->dnsTimeouts [$mID]->then (
        function () use ($Message, $mID): void {
          // Check if the query already vanished
          if (!isset ($this->dnsQueries [$mID]))
            return;

          // Remove from the queue
          unset ($this->dnsQueries [$mID]);
          unset ($this->dnsTimeouts [$mID]);

          // Fire a callback
          $this->___callback ('dnsQuestionTimeout', $Message);
        }
      );
    }
    // }}}

    // {{{ consume
    /**
     * Internal Callback: Data was received over the wire
     *
     * @param string $Data
     * @param Events\ABI\Source $Source
     *
     * @access public
     * @return void
     **/
    public function consume ($Data, Events\ABI\Source $Source): void
    {
      // Make sure we have a source
      if (!$this->dataSource) {
        trigger_error ('consume() without Source assigned');

        return;
      }

      // Just forward the data in UDP-Mode
      if (
        ($this->dataSource instanceof Events\Socket) &&
        $this->dataSource->isUDP ()
      ) {
        $this->dnsStreamParse ($Data);

        return;
      }

      // Append the data to our buffer
      $this->dnsBuffer .= $Data;
      unset ($Data);

      while (($l = strlen ($this->dnsBuffer)) > 0) {
        // Check if we know the length we expect
        if ($this->dnsLength === null) {
          // We need at least two bytes here
          if ($l < 2)
            return;

          // Get the length
          $this->dnsLength = (ord ($this->dnsBuffer [0]) << 8) + ord ($this->dnsBuffer [1]);
          $this->dnsBuffer = substr ($this->dnsBuffer, 2);
          $l -= 2;
        }

        // Check if the buffer is big enough
        if ($l < $this->dnsLength)
          return;

        // Get the data from the buffer
        $dnsPacket = substr ($this->dnsBuffer, 0, $this->dnsLength);
        $this->dnsBuffer = substr ($this->dnsBuffer, $this->dnsLength);
        $this->dnsLength = null;

        // Dispatch complete packet
        $this->dnsStreamParse ($dnsPacket);
        unset ($dnsPacket);
      }
    }
    // }}}

    // {{{ close
    /**
     * Close this event-interface
     *
     * @access public
     * @return Events\Promise
     **/
    public function close (): Events\Promise
    {
      // Just raise callbacks
      $this->___callback ('eventClosed');

      return Events\Promise::resolve ();
    }
    // }}}

    // {{{ reset
    /**
     * Reset our internal state
     *
     * @access private
     * @return void
     **/
    private function reset (): void
    {
      $this->dnsBuffer = '';
      $this->dnsLength = null;
      $this->dnsQueries = [];
    }
    // }}}

    // {{{ initConsumer
    /**
     * Setup ourselves to consume data from a source
     *
     * @param Events\ABI\Source $dataSource
     *
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Events\ABI\Source $dataSource): Events\Promise
    {
      // Check if this source is already set
      if ($this->dataSource === $dataSource)
        return Events\Promise::resolve ();

      // Check if there is an existing source
      if ($this->dataSource)
        $deinitPromise = $this->deinitConsumer ($this->dataSource)->catch (function () { });
      else
        $deinitPromise = Events\Promise::resolve ();

      return $deinitPromise->then (
        function () use ($dataSource) {
          // Reset ourself
          $this->reset ();

         // Set the new source
         $this->dataSource = $dataSource;

          // Raise an event for this
          $this->___callback ('eventPiped', $dataSource);
        }
      );
    }
    // }}}

    // {{{ initStreamConsumer
    /**
     * Set up ourselves to consume data from a stream
     *
     * @param Events\ABI\Stream $sourceStream
     *
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $sourceStream): Events\Promise
    {
      // Check if this source is already set
      if ($this->dataSource === $sourceStream)
        return Events\Promise::resolve ();

      // Check if there is an existing source
      if ($this->dataSource)
        $Promise = $this->deinitConsumer ($this->dataSource)->catch (function () { });
      else
        $Promise = Events\Promise::resolve ();

      return $Promise->then (
        function () use ($sourceStream) {
          // Reset ourself
          $this->reset ();

          // Set the new source
          $this->dataSource = $sourceStream;

          // Raise an event for this
          $this->___callback ('eventPipedStream', $sourceStream);
        }
      );
    }
    // }}}

    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     *
     * @param Events\ABI\Source $Source
     *
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $Source): Events\Promise
    {
      // Check if this is the right source
      if ($this->dataSource !== $Source)
        return Events\Promise::reject ('Invalid source');

      // Unset the source
      $this->dataSource = null;

      // Raise an event for this
      $this->___callback ('eventUnpiped', $Source);

      return Events\Promise::resolve ();
    }
    // }}}

    // {{{ dnsMessageReceived
    /**
     * Callback: A DNS-Message was received
     *
     * @param DNS\Message $Message
     *
     * @access protected
     * @return void
     **/
    protected function dnsMessageReceived (DNS\Message $Message): void
    {
      // No-Op
    }
    // }}}

    // {{{ dnsQuestionReceived
    /**
     * Callback: A DNS-Question was received
     *
     * @param DNS\Message $Message
     *
     * @access protected
     * @return void
     **/
    protected function dnsQuestionReceived (DNS\Message $Message): void
    {
      // No-Op
    }
    // }}}

    // {{{ dnsResponseReceived
    /** 
     * Callback: A DNS-Response was received
     *
     * @param DNS\Message $Message
     *
     * @access protected
     * @return void
     **/  
    protected function dnsResponseReceived (DNS\Message $Message): void
    {
      // No-Op
    }
    // }}}

    // {{{ dnsQuestionTimeout
    /**
     * Callback: A DNS-Question as been timed out
     *
     * @param DNS\Message $Message
     *
     * @access protected
     * @return void
     **/
    protected function dnsQuestionTimeout (DNS\Message $Message): void
    {
      // No-Op
    }
    // }}}

    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     *
     * @access protected
     * @return void
     **/
    protected function eventReadable (): void
    {
      // No-Op
    }
    // }}}

    // {{{ eventClosed
    /**
     * Callback: This stream was closed
     *
     * @access protected
     * @return void
     **/
    protected function eventClosed (): void
    {
      // No-Op
    }
    // }}}

    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     *
     * @param Events\ABI\Source $Source
     *
     * @access protected
     * @return void
     **/
    protected function eventPiped (Events\ABI\Source $Source): void
    {
      // No-Op
    }
    // }}}

    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     *
     * @param Events\ABI\Stream $Source
     *
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (Events\ABI\Stream $Source): void
    {
      // No-Op
    }
    // }}}

    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     *
     * @param Events\ABI\Source $Source
     *
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (Events\ABI\Source $Source): void
    {
      // No-Op
    }
    // }}}
  }
