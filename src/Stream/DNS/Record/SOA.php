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

  namespace quarxConnect\Events\Stream\DNS\Record;

  use InvalidArgumentException;
  use LengthException;
  use quarxConnect\Events\Stream\DNS;

  class SOA extends DNS\Record
  {
    protected const DEFAULT_TYPE = 0x06;

    private DNS\Label $Nameserver;
    private DNS\Label $Mailbox;
    private int $Serial = 0;
    private int $Refresh = 0;
    private int $Retry = 0;
    private int $Expire = 0;
    private int $Minimum = 0;

    // {{{ __toString
    /**
     * Create a human-readable representation from this
     *
     * @access friendly
     * @return string  
     **/
    public function __toString (): string
    {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' SOA ' . $this->Nameserver . ' ' . $this->Mailbox . ' ' . $this->Serial . ' ' . $this->Refresh . ' ' . $this->Retry . ' ' . $this->Expire . ' ' . $this->Minimum;
    }
    // }}}

    // {{{ getNameserver
    /**
     * Retrieve name of the primary nameserver for this zone
     *
     * @access public
     * @return DNS\Label
     **/
    public function getNameserver (): DNS\Label
    {
      return $this->Nameserver;
    }
    // }}}

    // {{{ setNameserver
    /**
     * Set the primary nameserver for this zone
     *
     * @param DNS\Label $Nameserver
     *
     * @access public
     * @return void
     **/
    public function setNameserver (DNS\Label $Nameserver): void
    {
      $this->Nameserver = $Nameserver;
    }
    // }}}

    // {{{ getMailbox
    /**
     * Retrieve the e-mail-address pf the dns-administrator in DNS-Form
     *
     * @access public
     * @return DNS\Label
     **/
    public function getMailbox (): DNS\Label
    {
      return $this->Mailbox;
    }
    // }}}

    // {{{ setMailbox
    /**
     * Set the e-mail-address of the dns-administrator in DNS-Form
     *
     * @param DNS\Label|string $Mailbox
     *
     * @access public
     * @return void
     **/
    public function setMailbox (DNS\Label|string $Mailbox): void
    {
      if (is_string ($Mailbox))
        $Mailbox = new DNS\Label (explode ('.', str_replace ('@', '.', $Mailbox)));

      $this->Mailbox = $Mailbox;
    }
    // }}}

    // {{{ getSerial
    /**
     * Retrieve the incrementing serial-number of this zone
     *
     * @access public
     * @return int
     **/
    public function getSerial (): int
    {
      return $this->Serial;
    }
    // }}}

    // {{{ setSerial
    /**
     * Set the serial number of this zone
     *
     * @param int $Serial
     *
     * @access public
     * @return void
     **/
    public function setSerial (int $Serial): void
    {
      $this->Serial = $Serial;
    }
    // }}}

    // {{{ getRefresh
    /**
     * Retrieve amount of seconds secondary nameservers should wait between refreshes
     *
     * @access public
     * @return int
     **/
    public function getRefresh (): int
    {
      return $this->Refresh;
    }
    // }}}

    // {{{ setRefresh
    /**
     * Set the amount of seconds to wait between refreshes
     *
     * @param int $Refresh
     *
     * @access public
     * @return void
     **/
    public function setRefresh (int $Refresh): void
    {
      $this->Refresh = $Refresh;
    }
    // }}}

    // {{{ getRetry
    /**
     * Retrieve the amount of seconds to wait between negative responses
     *
     * @access public
     * @return int
     **/
    public function getRetry (): int
    {
      return $this->Retry;
    }
    // }}}

    // {{{ setRetry
    /**
     * Set time to wait between negative responses
     *
     * @param int $Retry
     *
     * @access public
     * @return void
     **/
    public function setRetry (int $Retry): void
    {
      $this->Retry = $Retry;
    }
    // }}}

    // {{{ getExpire
    /**
     * Get maximum amount of to keep a copy of this zone
     *
     * @access public
     * @return int
     **/
    public function getExpire (): int
    {
      return $this->Expire;
    }
    // }}}

    // {{{ setExpire
    /**
     * Set maximum amount of time to keep a copy of this zone
     *
     * @param int $Expire
     *
     * @access public
     * @return void
     **/
    public function setExpire (int $Expire): void
    {
      $this->Expire = $Expire;
    }
    // }}}

    // {{{ getMinimum
    /**
     * Retrieve the minimum ttl for DNS-Records on this zone
     *
     * @access public
     * @return int
     **/
    public function getMinimum (): int
    {
      return $this->Minimum;
    }
    // }}}

    // {{{ setMinimum
    /**
     * Set the minimum ttl for DNS-Records on this zone
     *
     * @param int $Minimum
     *
     * @access public
     * @return void
     **/
    public function setMinimum (int $Minimum): void
    {
      $this->Minimum = $Minimum;
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
     * @throws InvalidArgumentException
     **/
    public function parsePayload (string $dnsData, int &$dataOffset, int $dataLength = null): void
    {
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);

      $Nameserver = DNS\Message::getLabel ($dnsData, $dataOffset);
      $Mailbox = DNS\Message::getLabel ($dnsData, $dataOffset);

      if ($dataLength < $dataOffset + 20)
        throw new LengthException ('DNS-Record too short (SOA)');

      $this->Nameserver = $Nameserver;
      $this->Mailbox    = $Mailbox;
      $this->Serial     = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Refresh    = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Retry      = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Expire     = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
      $this->Minimum    = self::parseInt32 ($dnsData, $dataOffset, $dataLength);
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
      $Nameserver = DNS\Message::setLabel ($this->Nameserver, $dataOffset, $dnsLabels);
      $Mailbox = DNS\Message::setLabel ($this->Mailbox, $dataOffset + strlen ($Nameserver), $dnsLabels);

      return
        $Nameserver .
        $Mailbox .
        self::buildInt32 ($this->Serial) .
        self::buildInt32 ($this->Refresh) .
        self::buildInt32 ($this->Retry) .
        self::buildInt32 ($this->Expire) .
        self::buildInt32 ($this->Minimum);
    }
    // }}}
  }
