<?php
declare(strict_types=1);

/**
 * Exception carrying an HTTP status and a machine-readable error code.
 * The front controller converts it into a JSON response.
 */
final class HttpException extends RuntimeException
{
    public int $status;
    public ?string $errorCode;
    /** Optional details, e.g. errors for individual form fields. */
    public array $details = [];
    /** Message parameters (e.g. field label, limit) – the frontend builds the localized text from them. */
    public array $params = [];

    public function __construct(string $message, int $status = 400, ?string $code = null, array $details = [], array $params = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
        $this->details = $details;
        $this->params = $params;
    }
}
