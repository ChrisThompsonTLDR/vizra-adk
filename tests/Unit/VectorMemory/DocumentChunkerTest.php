<?php

namespace Vizra\VizraADK\Tests\Unit\VectorMemory;

use Illuminate\Support\Facades\Config;
use Vizra\VizraADK\Services\DocumentChunker;
use Vizra\VizraADK\Tests\TestCase;

class DocumentChunkerTest extends TestCase
{
    protected DocumentChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('vizra-adk.vector_memory.chunking', [
            'strategy' => 'sentence',
            'chunk_size' => 100,
            'overlap' => 20,
        ]);

        $this->chunker = new DocumentChunker;
    }

    public function test_chunks_by_sentence()
    {
        // Arrange - set smaller chunk size to force splitting
        Config::set('vizra-adk.vector_memory.chunking.chunk_size', 30);
        $chunker = new DocumentChunker;
        $content = 'First sentence here. Second sentence follows. Third sentence ends it.';

        // Act
        $chunks = $chunker->chunk($content);

        // Assert
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(1, count($chunks));
        $this->assertStringContainsString('First sentence', $chunks[0]);
    }

    public function test_chunks_by_paragraph()
    {
        // Arrange - set smaller chunk size to force splitting
        Config::set('vizra-adk.vector_memory.chunking.strategy', 'paragraph');
        Config::set('vizra-adk.vector_memory.chunking.chunk_size', 30);
        $chunker = new DocumentChunker;

        $content = "First paragraph here.\n\nSecond paragraph follows.\n\nThird paragraph ends it.";

        // Act
        $chunks = $chunker->chunk($content);

        // Assert
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_chunks_by_fixed_size()
    {
        // Arrange
        Config::set('vizra-adk.vector_memory.chunking.strategy', 'fixed');
        Config::set('vizra-adk.vector_memory.chunking.chunk_size', 50);
        $chunker = new DocumentChunker;

        $content = str_repeat('This is a test sentence. ', 10);

        // Act
        $chunks = $chunker->chunk($content);

        // Assert
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(60, strlen($chunk)); // Some flexibility for word boundaries
        }
    }

    public function test_handles_empty_content()
    {
        // Act
        $chunks = $this->chunker->chunk('');

        // Assert
        $this->assertIsArray($chunks);
        $this->assertEmpty($chunks);
    }

    public function test_handles_whitespace_only_content()
    {
        // Act
        $chunks = $this->chunker->chunk("   \n\t   ");

        // Assert
        $this->assertIsArray($chunks);
        $this->assertEmpty($chunks);
    }

    public function test_validates_chunks()
    {
        // Arrange
        $invalidChunks = [
            '',           // Empty
            '   ',        // Whitespace only
            'ab',         // Too short
            '!!!@#$',     // No alphanumeric content
            'Valid chunk content here',  // Valid
        ];

        // Act
        $validChunks = $this->chunker->validateChunks($invalidChunks);

        // Assert
        $this->assertIsArray($validChunks);
        $this->assertCount(1, $validChunks);
        $this->assertEquals('Valid chunk content here', $validChunks[0]);
    }

    public function test_estimates_optimal_chunk_size()
    {
        // Arrange
        $shortContent = 'Short content';
        $codeContent = 'function test() { return $var->method(); }';
        $normalContent = str_repeat('This is normal text content. ', 50);

        // Act
        $shortSize = $this->chunker->getOptimalChunkSize($shortContent);
        $codeSize = $this->chunker->getOptimalChunkSize($codeContent);
        $normalSize = $this->chunker->getOptimalChunkSize($normalContent);

        // Assert
        $this->assertEquals(strlen($shortContent), $shortSize);
        $this->assertLessThan($normalSize, $codeSize); // Code should get smaller chunks
        $this->assertEquals(100, $normalSize); // Normal content uses configured size
    }

    public function test_chunks_utf8_text_safely_without_splitting_multibyte_sequences()
    {
        // Arrange - Test with UTF-8 multi-byte characters (Chinese, emoji, etc.)
        Config::set('vizra-adk.vector_memory.chunking.strategy', 'fixed');
        Config::set('vizra-adk.vector_memory.chunking.chunk_size', 50);
        $chunker = new DocumentChunker;

        // Content with multi-byte UTF-8 characters that could be split by byte-based substr()
        $content = 'Hello 世界 🌍 This is a test with UTF-8 characters. 日本語も大丈夫です。';

        // Act
        $chunks = $chunker->chunk($content);

        // Assert - All chunks should be valid UTF-8 and JSON encodable
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(0, count($chunks));

        foreach ($chunks as $chunk) {
            // Verify chunk is valid UTF-8
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'Chunk should be valid UTF-8');

            // Verify chunk can be JSON encoded (critical for embedding provider API calls)
            $json = json_encode($chunk, JSON_UNESCAPED_UNICODE);
            $this->assertNotFalse($json, 'Chunk should be JSON encodable');
            $this->assertEquals(JSON_ERROR_NONE, json_last_error(), 'No JSON encoding errors');

            // Verify chunk doesn't contain incomplete UTF-8 sequences
            // (which would happen if substr() split a multi-byte character)
            $this->assertStringNotContainsString("\x80", $chunk, 'Chunk should not contain standalone continuation bytes');
            $this->assertStringNotContainsString("\x81", $chunk, 'Chunk should not contain standalone continuation bytes');
        }
    }

    public function test_chunks_utf8_sentences_safely()
    {
        // Arrange - Test sentence chunking with UTF-8
        Config::set('vizra-adk.vector_memory.chunking.strategy', 'sentence');
        Config::set('vizra-adk.vector_memory.chunking.chunk_size', 30);
        $chunker = new DocumentChunker;

        $content = 'First sentence with 世界. Second sentence with 🌍. Third sentence with 日本語.';

        // Act
        $chunks = $chunker->chunk($content);

        // Assert
        $this->assertIsArray($chunks);
        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'Chunk should be valid UTF-8');
            $json = json_encode($chunk, JSON_UNESCAPED_UNICODE);
            $this->assertNotFalse($json, 'Chunk should be JSON encodable');
        }
    }

    public function test_get_overlap_content_handles_utf8_safely()
    {
        // Arrange
        Config::set('vizra-adk.vector_memory.chunking.overlap', 10);
        $chunker = new DocumentChunker;

        // Chunk with UTF-8 characters at the end
        $chunk = 'Some text before 世界 🌍 end';

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($chunker);
        $method = $reflection->getMethod('getOverlapContent');
        $method->setAccessible(true);

        // Act
        $overlap = $method->invoke($chunker, $chunk);

        // Assert
        $this->assertIsString($overlap);
        if (! empty($overlap)) {
            $this->assertTrue(mb_check_encoding($overlap, 'UTF-8'), 'Overlap should be valid UTF-8');
            $json = json_encode($overlap, JSON_UNESCAPED_UNICODE);
            $this->assertNotFalse($json, 'Overlap should be JSON encodable');
        }
    }
}
