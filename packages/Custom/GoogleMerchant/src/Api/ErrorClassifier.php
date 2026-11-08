<?php

namespace Custom\GoogleMerchant\Api;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\Exceptions\NotFoundException;
use Custom\GoogleMerchant\Api\Exceptions\PermanentApiException;
use Custom\GoogleMerchant\Api\Exceptions\QuotaExhaustedException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Api\Exceptions\UnregisteredProjectException;
use Illuminate\Http\Client\Response;

class ErrorClassifier
{
    public function __construct(protected ResponseRedactor $redactor) {}

    public function classify(Response|\Throwable $failure): GmcApiException
    {
        if ($failure instanceof GmcApiException) {
            return $failure;
        }

        if ($failure instanceof \Throwable) {
            return new TransientApiException('Could not reach Google: '.$failure->getMessage(), null, null, 60, $failure);
        }

        $status = $failure->status();
        $message = (string) ($failure->json('error.message') ?? $failure->reason());
        $excerpt = $this->redactor->redact($failure->body());

        return match (true) {
            $status === 429 && str_contains(strtolower($message), 'quota') => new QuotaExhaustedException($message, $status, $excerpt, $this->retryAfter($failure, 3600)),
            $status === 429, $status >= 500                                => new TransientApiException($message, $status, $excerpt, $this->retryAfter($failure, 60)),
            $status === 401 && str_contains(strtolower($message), 'not registered') => new UnregisteredProjectException($message, $status, $excerpt),
            $status === 401, $status === 403                               => new AuthException($message, $status, $excerpt),
            $status === 404                                                => new NotFoundException($message, $status, $excerpt),
            default                                                        => new PermanentApiException($message, $status, $excerpt, $this->fieldViolations($failure)),
        };
    }

    protected function retryAfter(Response $response, int $default): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        if ($header && ($time = strtotime($header))) {
            return max(1, $time - time());
        }

        return $default;
    }

    /** @return array<string, string> */
    protected function fieldViolations(Response $response): array
    {
        $violations = [];

        foreach ((array) $response->json('error.details', []) as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (! empty($violation['field'])) {
                    $violations[$violation['field']] = (string) ($violation['description'] ?? '');
                }
            }
        }

        return $violations;
    }
}
