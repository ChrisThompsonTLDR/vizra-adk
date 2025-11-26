<?php

namespace Vizra\VizraADK\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vizra\VizraADK\Models\VectorMemory;
use Vizra\VizraADK\System\AgentContext;

class EmbeddingGenerated
{
    use Dispatchable, SerializesModels;

    public ?AgentContext $context;

    public string $agentName;

    public VectorMemory $memory;

    public string $provider;

    public string $model;

    public int $tokenCount;

    public array $metadata;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?AgentContext $context,
        string $agentName,
        VectorMemory $memory,
        string $provider,
        string $model,
        int $tokenCount,
        array $metadata = []
    ) {
        $this->context = $context;
        $this->agentName = $agentName;
        $this->memory = $memory;
        $this->provider = $provider;
        $this->model = $model;
        $this->tokenCount = $tokenCount;
        $this->metadata = $metadata;
    }
}

