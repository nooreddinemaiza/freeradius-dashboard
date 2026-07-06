<?php

namespace Core\Exception;

class ConfigurationException extends \Exception
{
    protected $errors = [];

    public function __construct($message = "Configuration failed", $errors = [], $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function setErrors($errors)
    {
        $this->errors = $errors;
        return $this;
    }

    public function addError($field, $message)
    {
        $this->errors[$field] = $message;
        return $this;
    }
}
