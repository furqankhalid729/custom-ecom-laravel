<?php

namespace Custom\GoogleCore\Integrations;

use Custom\GoogleCore\Contracts\GoogleIntegration;

class IntegrationRegistry
{
    /** @var array<string, GoogleIntegration> */
    protected array $integrations = [];

    public function register(GoogleIntegration $integration): void
    {
        $this->integrations[$integration->key()] = $integration;
    }

    /** @return array<string, GoogleIntegration> */
    public function all(): array
    {
        return $this->integrations;
    }
}
