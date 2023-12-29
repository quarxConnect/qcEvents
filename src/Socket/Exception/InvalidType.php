<?php

  /**
   * quarxConnect Events - Exception when an invalid socket-type was used
   * Copyright (C) 2014-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Socket\Exception;

  use OutOfRangeException;
  use Throwable;

  class InvalidType extends OutOfRangeException {
    // {{{ __construct
    /**
     * Create a new Not-Found-Exception for Cache
     *
     * @param string $errorMessage (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (string $errorMessage = 'Invalid Socket-Type') {
      parent::__construct ($errorMessage);
    }
    // }}}
  }
