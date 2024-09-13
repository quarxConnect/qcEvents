<?php

  /**
   * quarxConnect Events - Not Found Exception for Cache
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
  
  namespace quarxConnect\Events\Cache\Exception;

  use Exception;
  use OutOfRangeException;
  use Throwable;

  /**
   * @property string $lookupKey
   **/
  class NotFound extends Exception {
    /**
     * The key used for lookup
     * 
     * @var string
     **/
    protected string $lookupKey;

    // {{{ __construct
    /**
     * Create a new Not-Found-Exception for Cache
     *
     * @param string $errorMessage
     * @param string $lookupKey
     * @param Throwable|null $previousError (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (string $errorMessage, string $lookupKey, Throwable $previousError = null) {
      parent::__construct ($errorMessage, 0, $previousError);

      $this->lookupKey = $lookupKey;
    }
    // }}}

    // {{{ __get
    /**
     * Access read-only properties of this event
     *
     * @param string $propertyName
     *
     * @access public
     * @return mixed
     **/
    public function __get (string $propertyName): mixed {
      if (!property_exists ($this, $propertyName))
          throw new OutOfRangeException ('Invalid property: ' . $propertyName);

      return $this->$propertyName;
    }
    // }}}
  }