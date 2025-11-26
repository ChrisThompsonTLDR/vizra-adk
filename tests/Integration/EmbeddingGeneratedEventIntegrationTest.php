<?php

namespace Vizra\VizraADK\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Mockery;
use Vizra\VizraADK\Contracts\EmbeddingProviderInterface;
use Vizra\VizraADK\Events\EmbeddingGenerated;
use Vizra\VizraADK\Models\VectorMemory;
use Vizra\VizraADK\Services\DocumentChunker;
use Vizra\VizraADK\Services\VectorMemoryManager;
use Vizra\VizraADK\Tests\TestCase;
use Vizra\VizraADK\Tests\Fixtures\TestAgent;

class EmbeddingGeneratedEventIntegrationTest extends TestCase
{
    protected VectorMemoryManager $vectorMemoryManager;

    protected $mockEmbeddingProvider;

    protected $mockChunker;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock embedding provider
        $this->mockEmbeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockEmbeddingProvider->shouldReceive('getProviderName')->andReturn('openai');
        $this->mockEmbeddingProvider->shouldReceive('getModel')->andReturn('text-embedding-3-small');
        $this->mockEmbeddingProvider->shouldReceive('getDimensions')->andReturn(1536);

        // Mock document chunker
        $this->mockChunker = Mockery::mock(DocumentChunker::class);

        $this->vectorMemoryManager = new VectorMemoryManager(
            $this->mockEmbeddingProvider,
            $this->mockChunker
        );
    }

    public function test_event_can_be_listened_to_by_external_listeners()
    {
        // Arrange - Create a listener that captures event data
        $capturedEvents = [];
        Event::listen(EmbeddingGenerated::class, function (EmbeddingGenerated $event) use (&$capturedEvents) {
            $capturedEvents[] = [
                'agent_name' => $event->agentName,
                'provider' => $event->provider,
                'model' => $event->model,
                'token_count' => $event->tokenCount,
                'metadata' => $event->metadata,
                'memory_id' => $event->memory->id,
            ];
        });

        $agentClass = TestAgent::class;
        $content = 'Test content for embedding integration test';
        $mockEmbedding = array_fill(0, 1536, 0.1);

        $this->mockEmbeddingProvider->shouldReceive('embed')
            ->with($content)
            ->once()
            ->andReturn([$mockEmbedding]);

        // Act
        $result = $this->vectorMemoryManager->addChunk(
            $agentClass,
            $content,
            ['source' => 'integration_test', 'file_id' => 'test-123']
        );

        // Assert - Listener was called
        $this->assertCount(1, $capturedEvents);
        $this->assertEquals('test_agent', $capturedEvents[0]['agent_name']);
        $this->assertEquals('openai', $capturedEvents[0]['provider']);
        $this->assertEquals('text-embedding-3-small', $capturedEvents[0]['model']);
        $this->assertEquals($result->token_count, $capturedEvents[0]['token_count']);
        $this->assertEquals('integration_test', $capturedEvents[0]['metadata']['source']);
        $this->assertEquals('test-123', $capturedEvents[0]['metadata']['file_id']);
        $this->assertEquals($result->id, $capturedEvents[0]['memory_id']);
    }

    public function test_event_contains_all_required_data_for_usage_tracking()
    {
        // Arrange
        $capturedEvent = null;
        Event::listen(EmbeddingGenerated::class, function (EmbeddingGenerated $event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });

        $agentClass = TestAgent::class;
        $content = 'Content with specific token count';
        $mockEmbedding = array_fill(0, 1536, 0.1);

        $this->mockEmbeddingProvider->shouldReceive('embed')
            ->with($content)
            ->once()
            ->andReturn([$mockEmbedding]);

        // Act
        $result = $this->vectorMemoryManager->addChunk(
            $agentClass,
            $content,
            ['space_id' => 'spa_123', 'media_id' => 'med_456']
        );

        // Assert - All required data is present
        $this->assertNotNull($capturedEvent);
        $this->assertInstanceOf(EmbeddingGenerated::class, $capturedEvent);
        $this->assertInstanceOf(VectorMemory::class, $capturedEvent->memory);
        $this->assertIsString($capturedEvent->agentName);
        $this->assertIsString($capturedEvent->provider);
        $this->assertIsString($capturedEvent->model);
        $this->assertIsInt($capturedEvent->tokenCount);
        $this->assertIsArray($capturedEvent->metadata);
        $this->assertGreaterThan(0, $capturedEvent->tokenCount);
        $this->assertEquals($result->id, $capturedEvent->memory->id);
    }
}

