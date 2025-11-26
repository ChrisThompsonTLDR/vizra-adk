<?php

use Illuminate\Support\Facades\Event;
use Vizra\VizraADK\Events\AgentExecutionFinished;
use Vizra\VizraADK\Events\AgentExecutionStarting;
use Vizra\VizraADK\Events\AgentResponseGenerated;
use Vizra\VizraADK\Events\EmbeddingGenerated;
use Vizra\VizraADK\Events\TaskDelegated;
use Vizra\VizraADK\Models\VectorMemory;
use Vizra\VizraADK\System\AgentContext;

it('creates agent response generated event correctly', function () {
    $context = new AgentContext('test-session', 'test input');
    $agentName = 'test-agent';
    $response = 'Test response';

    $event = new AgentResponseGenerated($context, $agentName, $response);

    expect($event->context)->toBe($context);
    expect($event->agentName)->toBe($agentName);
    expect($event->finalResponse)->toBe($response);
});

it('creates agent execution starting event correctly', function () {
    $context = new AgentContext('test-session', 'test input');
    $agentName = 'test-agent';
    $input = 'test input';

    $event = new AgentExecutionStarting($context, $agentName, $input);

    expect($event->context)->toBe($context);
    expect($event->agentName)->toBe($agentName);
    expect($event->input)->toBe($input);
});

it('creates agent execution finished event correctly', function () {
    $context = new AgentContext('test-session', 'test input');
    $agentName = 'test-agent';

    $event = new AgentExecutionFinished($context, $agentName);

    expect($event->context)->toBe($context);
    expect($event->agentName)->toBe($agentName);
});

it('creates task delegated event correctly', function () {
    $parentContext = new AgentContext('parent-session', 'parent input');
    $subAgentContext = new AgentContext('sub-session', 'sub input');
    $parentAgentName = 'parent-agent';
    $subAgentName = 'sub-agent';
    $taskInput = 'Process this data';
    $contextSummary = 'User is asking about data processing';
    $delegationDepth = 2;

    $event = new TaskDelegated(
        $parentContext,
        $subAgentContext,
        $parentAgentName,
        $subAgentName,
        $taskInput,
        $contextSummary,
        $delegationDepth
    );

    expect($event->parentContext)->toBe($parentContext);
    expect($event->subAgentContext)->toBe($subAgentContext);
    expect($event->parentAgentName)->toBe($parentAgentName);
    expect($event->subAgentName)->toBe($subAgentName);
    expect($event->taskInput)->toBe($taskInput);
    expect($event->contextSummary)->toBe($contextSummary);
    expect($event->delegationDepth)->toBe($delegationDepth);
});

it('can dispatch events', function () {
    Event::fake();

    $context = new AgentContext('test-session', 'test input');
    $subAgentContext = new AgentContext('sub-session', 'sub input');

    // Dispatch events
    AgentResponseGenerated::dispatch($context, 'test-agent', 'response');
    AgentExecutionStarting::dispatch($context, 'test-agent', 'test input');
    AgentExecutionFinished::dispatch($context, 'test-agent');
    TaskDelegated::dispatch($context, $subAgentContext, 'parent-agent', 'sub-agent', 'task input', 'context summary', 1);

    // Assert events were dispatched
    Event::assertDispatched(AgentResponseGenerated::class);
    Event::assertDispatched(AgentExecutionStarting::class);
    Event::assertDispatched(AgentExecutionFinished::class);
    Event::assertDispatched(TaskDelegated::class);
});

it('contains correct data when dispatched', function () {
    Event::fake();

    $context = new AgentContext('test-session', 'test input');

    AgentResponseGenerated::dispatch($context, 'test-agent', 'test-response');

    Event::assertDispatched(AgentResponseGenerated::class, function ($event) use ($context) {
        return $event->context === $context &&
               $event->agentName === 'test-agent' &&
               $event->finalResponse === 'test-response';
    });
});

it('handles complex response data', function () {
    $context = new AgentContext('test-session', 'complex input');
    $complexResponse = [
        'text' => 'Response text',
        'metadata' => ['tokens' => 150, 'model' => 'gpt-4'],
        'tools_used' => ['weather_tool', 'calculator'],
    ];

    $event = new AgentResponseGenerated($context, 'complex-agent', $complexResponse);

    expect($event->finalResponse)->toBeArray();
    expect($event->finalResponse['text'])->toBe('Response text');
    expect($event->finalResponse['metadata']['tokens'])->toBe(150);
    expect($event->finalResponse['tools_used'])->toContain('weather_tool');
});

it('can be serialized', function () {
    $context = new AgentContext('test-session', 'serialization test');
    $event = new AgentResponseGenerated($context, 'serializable-agent', 'serializable response');

    // Test that event can be serialized (important for queued listeners)
    $serialized = serialize($event);
    $unserialized = unserialize($serialized);

    expect($unserialized->agentName)->toBe($event->agentName);
    expect($unserialized->finalResponse)->toBe($event->finalResponse);
    expect($unserialized->context->getSessionId())->toBe($event->context->getSessionId());
});

it('creates embedding generated event correctly', function () {
    $agentName = 'test_agent';
    $provider = 'openai';
    $model = 'text-embedding-3-small';
    $tokenCount = 100;
    $metadata = ['source' => 'test', 'file_id' => '123'];

    // Create a mock VectorMemory model
    $memory = new VectorMemory([
        'agent_name' => $agentName,
        'content' => 'Test content',
        'embedding_provider' => $provider,
        'embedding_model' => $model,
        'token_count' => $tokenCount,
        'metadata' => $metadata,
    ]);

    $event = new EmbeddingGenerated(
        null,
        $agentName,
        $memory,
        $provider,
        $model,
        $tokenCount,
        $metadata
    );

    expect($event->context)->toBeNull();
    expect($event->agentName)->toBe($agentName);
    expect($event->memory)->toBe($memory);
    expect($event->provider)->toBe($provider);
    expect($event->model)->toBe($model);
    expect($event->tokenCount)->toBe($tokenCount);
    expect($event->metadata)->toBe($metadata);
});

it('can dispatch embedding generated event', function () {
    Event::fake();

    $memory = new VectorMemory([
        'agent_name' => 'test_agent',
        'content' => 'Test content',
        'embedding_provider' => 'openai',
        'embedding_model' => 'text-embedding-3-small',
        'token_count' => 100,
    ]);

    EmbeddingGenerated::dispatch(
        null,
        'test_agent',
        $memory,
        'openai',
        'text-embedding-3-small',
        100,
        ['source' => 'test']
    );

    Event::assertDispatched(EmbeddingGenerated::class);
});

it('contains correct data when embedding generated event is dispatched', function () {
    Event::fake();

    $memory = new VectorMemory([
        'agent_name' => 'test_agent',
        'content' => 'Test content for embedding',
        'embedding_provider' => 'openai',
        'embedding_model' => 'text-embedding-3-small',
        'token_count' => 150,
    ]);

    EmbeddingGenerated::dispatch(
        null,
        'test_agent',
        $memory,
        'openai',
        'text-embedding-3-small',
        150,
        ['source' => 'file_upload', 'file_id' => 'abc123']
    );

    Event::assertDispatched(EmbeddingGenerated::class, function ($event) use ($memory) {
        return $event->agentName === 'test_agent' &&
               $event->memory->id === $memory->id &&
               $event->provider === 'openai' &&
               $event->model === 'text-embedding-3-small' &&
               $event->tokenCount === 150 &&
               $event->metadata['source'] === 'file_upload' &&
               $event->metadata['file_id'] === 'abc123';
    });
});

it('can serialize embedding generated event', function () {
    // Create and save memory to database so it can be serialized
    $memory = VectorMemory::create([
        'agent_name' => 'test_agent',
        'namespace' => 'default',
        'content' => 'Test content',
        'embedding_provider' => 'openai',
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dimensions' => 384,
        'token_count' => 100,
        'embedding_vector' => array_fill(0, 384, 0.1),
        'embedding_norm' => 1.0,
        'content_hash' => 'test-hash',
    ]);

    $event = new EmbeddingGenerated(
        null,
        'test_agent',
        $memory,
        'openai',
        'text-embedding-3-small',
        100,
        ['source' => 'test']
    );

    // Test that event can be serialized (important for queued listeners)
    $serialized = serialize($event);
    $unserialized = unserialize($serialized);

    expect($unserialized->agentName)->toBe($event->agentName);
    expect($unserialized->provider)->toBe($event->provider);
    expect($unserialized->model)->toBe($event->model);
    expect($unserialized->tokenCount)->toBe($event->tokenCount);
    expect($unserialized->metadata)->toBe($event->metadata);
    expect($unserialized->memory->id)->toBe($memory->id);
});
