<?php

namespace AtiqurSafayat\Bkash\Exceptions;

class ConfigurationException extends \Exception
{
    public function __construct($message = 'Configuration error', $code = 411, ?\Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
