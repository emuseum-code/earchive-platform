<?php

namespace App\Services;

/** Помилка Gemini AI. transient=true — тимчасовий збій, завдання повторюється автоматично. */
class AiException extends \RuntimeException
{
    public function __construct(string $message, public bool $transient = false)
    {
        parent::__construct($message);
    }
}
