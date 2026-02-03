#!/usr/bin/env python3
"""
Pytest integration tests for agento_video.py
Tests video generation, caching, and file saving functionality.

Uses mock Veo API server for testing to avoid API costs and rate limits.
"""

import pytest
import json
import os
import tempfile
import shutil
import subprocess
import time
import signal
from pathlib import Path
from unittest.mock import Mock, patch, MagicMock
import base64
import sys

# Add the current directory to path to import agento_video
sys.path.insert(0, str(Path(__file__).parent))
from agento_video import GeminiVideoGenerator


@pytest.fixture(scope='session')
def mock_server():
    """Start mock Veo API server before tests and stop after"""
    # Determine mock server script path
    script_dir = Path(__file__).parent
    mock_server_script = script_dir / 'mock_veo_server.py'
    
    if not mock_server_script.exists():
        pytest.skip(f"Mock server script not found: {mock_server_script}")
    
    # Use a random port to avoid conflicts
    import random
    port = random.randint(9000, 9999)
    host = '127.0.0.1'
    
    # Start mock server
    server_process = subprocess.Popen(
        [sys.executable, str(mock_server_script), '--port', str(port), '--host', host],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        preexec_fn=os.setsid if hasattr(os, 'setsid') else None
    )
    
    # Wait for server to start
    time.sleep(2)
    
    # Check if server is running
    if server_process.poll() is not None:
        stdout, stderr = server_process.communicate()
        pytest.fail(f"Mock server failed to start:\nSTDOUT: {stdout.decode()}\nSTDERR: {stderr.decode()}")
    
    # Set environment variable for tests
    base_url = f'http://{host}:{port}/v1beta'
    os.environ['GOOGLE_API_DOMAIN'] = base_url
    
    yield {
        'host': host,
        'port': port,
        'base_url': base_url,
        'process': server_process
    }
    
    # Cleanup: Stop mock server
    try:
        if hasattr(os, 'setsid'):
            # Kill the process group
            os.killpg(os.getpgid(server_process.pid), signal.SIGTERM)
        else:
            # Windows or systems without setsid
            server_process.terminate()
        
        # Wait for process to terminate
        try:
            server_process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server_process.kill()
            server_process.wait()
    except ProcessLookupError:
        # Process already terminated
        pass
    
    # Remove environment variable
    if 'GOOGLE_API_DOMAIN' in os.environ:
        del os.environ['GOOGLE_API_DOMAIN']


@pytest.fixture
def temp_dir():
    """Create a temporary directory for testing"""
    temp_path = Path(tempfile.mkdtemp())
    yield temp_path
    shutil.rmtree(temp_path)


@pytest.fixture
def test_image(temp_dir):
    """Create a test image file"""
    image_path = temp_dir / 'test_image.jpg'
    # Create a minimal JPEG file (valid JPEG header)
    with open(image_path, 'wb') as f:
        # Minimal valid JPEG
        f.write(b'\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01')
        f.write(b'\x01\x01\x00\x00\x01\x00\x01\x00\x00')
        f.write(b'\xff\xdb\x00C\x00')
        # Add some dummy data
        f.write(b'\x00' * 100)
    return str(image_path)


@pytest.fixture
def api_key():
    """Test API key"""
    return 'test_api_key_12345'


@pytest.fixture
def generator(temp_dir, api_key, mock_server):
    """Create a GeminiVideoGenerator instance (uses mock server)"""
    # Mock server fixture ensures GOOGLE_API_DOMAIN is set
    # If mock_server is not available, use a default test URL
    base_url = os.getenv('GOOGLE_API_DOMAIN', 'http://127.0.0.1:8080/v1beta')
    return GeminiVideoGenerator(api_key, str(temp_dir), base_url=base_url)


class TestGeminiVideoGenerator:
    """Test suite for GeminiVideoGenerator"""
    
    def test_is_available(self, generator, api_key):
        """Test service availability check"""
        assert generator.is_available() is True
        
        # Test with empty API key
        base_url = os.getenv('GOOGLE_API_DOMAIN', 'http://127.0.0.1:8080/v1beta')
        empty_generator = GeminiVideoGenerator('', str(generator.base_path), base_url=base_url)
        assert empty_generator.is_available() is False
    
    def test_resolve_image_path_absolute(self, generator, test_image):
        """Test resolving absolute image paths"""
        resolved = generator.resolve_image_path(test_image)
        assert resolved == Path(test_image)
        assert resolved.is_absolute()
    
    def test_resolve_image_path_relative(self, generator, temp_dir):
        """Test resolving relative image paths"""
        # Create image in pub/media
        media_dir = temp_dir / 'pub' / 'media' / 'catalog' / 'product'
        media_dir.mkdir(parents=True, exist_ok=True)
        image_path = media_dir / 'test.jpg'
        image_path.write_bytes(b'test image data')
        
        # Test relative path
        resolved = generator.resolve_image_path('catalog/product/test.jpg')
        assert resolved == image_path
        assert resolved.exists()
    
    def test_resolve_image_path_pub_media_prefix(self, generator, temp_dir):
        """Test resolving paths with pub/media/ prefix"""
        media_dir = temp_dir / 'pub' / 'media' / 'catalog'
        media_dir.mkdir(parents=True, exist_ok=True)
        image_path = media_dir / 'test.jpg'
        image_path.write_bytes(b'test image data')
        
        resolved = generator.resolve_image_path('pub/media/catalog/test.jpg')
        assert resolved == image_path
    
    def test_generate_cache_key(self, generator, test_image):
        """Test cache key generation"""
        prompt = "Test prompt"
        aspect_ratio = "16:9"
        
        cache_key1 = generator.generate_cache_key(test_image, prompt, aspect_ratio)
        cache_key2 = generator.generate_cache_key(test_image, prompt, aspect_ratio)
        
        # Same inputs should generate same cache key
        assert cache_key1 == cache_key2
        
        # Different prompt should generate different cache key
        cache_key3 = generator.generate_cache_key(test_image, "Different prompt", aspect_ratio)
        assert cache_key1 != cache_key3
    
    def test_get_cached_video_exists(self, generator, temp_dir):
        """Test retrieving cached video"""
        cache_key = 'test_cache_key_123'
        filename = f'veo_{cache_key}.mp4'
        video_path = generator.video_dir / filename
        
        # Create cached video file
        generator.video_dir.mkdir(parents=True, exist_ok=True)
        video_path.write_bytes(b'fake video content')
        
        cached = generator.get_cached_video(cache_key)
        
        assert cached is not None
        assert cached['fromCache'] is True
        # Video URL should contain the filename and be a valid URL
        assert filename in cached['videoUrl']
        assert cached['videoUrl'].endswith('.mp4')
        assert cached['videoPath'] == str(video_path)
        assert cached['status'] == 'completed'
    
    def test_get_cached_video_not_exists(self, generator):
        """Test retrieving non-existent cached video"""
        cached = generator.get_cached_video('non_existent_key')
        assert cached is None
    
    def test_download_video_with_redirects(self, generator, mock_server):
        """Test video download with redirect handling using mock server"""
        # Generate and complete a video operation
        test_image_path = generator.base_path / 'test.jpg'
        test_image_path.write_bytes(b'\xff\xd8\xff\xe0\x00\x10JFIF')
        
        operation = generator.generate_video_from_image(
            str(test_image_path),
            'Test download',
            '16:9',
            False
        )
        
        # Poll for completion
        result = generator.poll_video_operation(
            operation['operationName'],
            max_wait_seconds=10,
            poll_interval_seconds=1,
            cache_key=operation.get('cacheKey')
        )
        
        # Verify video was downloaded and saved
        assert result['status'] == 'completed'
        assert Path(result['videoPath']).exists()
        assert Path(result['videoPath']).stat().st_size > 0
    
    def test_save_video(self, generator, temp_dir, mock_server):
        """Test saving video to filesystem using mock server"""
        cache_key = 'test_save_key'
        
        # Use mock server video URI
        video_uri = f"{mock_server['base_url'].replace('/v1beta', '')}/videos/test-video-id"
        
        video_path = generator.save_video(video_uri, cache_key)
        
        assert video_path == str(generator.video_dir / f'veo_{cache_key}.mp4')
        assert Path(video_path).exists()
        assert Path(video_path).stat().st_size > 0
    
    def test_generate_video_from_image_success(self, generator, test_image, mock_server):
        """Test successful video generation using mock server"""
        # Use real requests to mock server (no mocking needed)
        result = generator.generate_video_from_image(
            test_image,
            'Test prompt',
            '16:9',
            False
        )
        
        assert 'operationName' in result
        assert result['operationName'].startswith('operations/')
        assert result['status'] == 'running'
        assert 'cacheKey' in result
    
    def test_generate_video_from_image_cached(self, generator, test_image, temp_dir):
        """Test that cached videos are returned"""
        cache_key = generator.generate_cache_key(test_image, 'Test prompt', '16:9')
        filename = f'veo_{cache_key}.mp4'
        video_path = generator.video_dir / filename
        
        # Create cached video
        generator.video_dir.mkdir(parents=True, exist_ok=True)
        video_path.write_bytes(b'cached video')
        
        result = generator.generate_video_from_image(
            test_image,
            'Test prompt',
            '16:9',
            False
        )
        
        assert result['fromCache'] is True
        assert 'videoUrl' in result
        assert result['videoPath'] == str(video_path)
    
    def test_poll_video_operation_success(self, generator, test_image, mock_server):
        """Test successful video operation polling using mock server"""
        # First generate a video operation
        operation = generator.generate_video_from_image(
            test_image,
            'Test prompt for polling',
            '16:9',
            False
        )
        
        operation_name = operation['operationName']
        cache_key = operation.get('cacheKey')
        
        # Poll for completion (mock server completes after ~5 seconds)
        result = generator.poll_video_operation(
            operation_name,
            max_wait_seconds=10,  # Allow time for mock server
            poll_interval_seconds=1,
            cache_key=cache_key
        )
        
        assert result['status'] == 'completed'
        assert 'videoUrl' in result
        assert 'videoPath' in result
        assert 'embedUrl' in result
        assert Path(result['videoPath']).exists()
    
    @patch('agento_video.requests.get')
    def test_poll_video_operation_safety_filter(self, mock_get, generator):
        """Test polling detects safety filter blocks"""
        operation_name = 'operations/test-operation-123'
        
        # Mock response with safety filter
        filtered_response = Mock()
        filtered_response.status_code = 200
        filtered_response.json.return_value = {
            'name': operation_name,
            'done': True,
            'response': {
                'generateVideoResponse': {
                    'raiMediaFilteredReasons': ['SAFETY', 'COPYRIGHT'],
                    'raiMediaFilteredCount': 1
                }
            }
        }
        
        mock_get.return_value = filtered_response
        
        with pytest.raises(RuntimeError) as exc_info:
            generator.poll_video_operation(
                operation_name,
                max_wait_seconds=60,
                poll_interval_seconds=1
            )
        
        assert 'Safety Filter' in str(exc_info.value) or 'safety filters' in str(exc_info.value)
        assert 'SAFETY' in str(exc_info.value) or 'COPYRIGHT' in str(exc_info.value)
    
    @patch('agento_video.requests.get')
    def test_poll_video_operation_timeout(self, mock_get, generator):
        """Test polling timeout"""
        operation_name = 'operations/test-operation-123'
        
        # Mock response that never completes
        not_done_response = Mock()
        not_done_response.status_code = 200
        not_done_response.json.return_value = {
            'name': operation_name,
            'done': False
        }
        
        mock_get.return_value = not_done_response
        
        with pytest.raises(RuntimeError) as exc_info:
            generator.poll_video_operation(
                operation_name,
                max_wait_seconds=1,  # Very short timeout
                poll_interval_seconds=0.5
            )
        
        assert 'timeout' in str(exc_info.value).lower()
    
    def test_video_directory_creation(self, temp_dir, api_key, mock_server):
        """Test that video directory is created automatically"""
        base_path = temp_dir / 'magento'
        base_path.mkdir()
        
        base_url = os.getenv('GOOGLE_API_DOMAIN', 'http://127.0.0.1:8080/v1beta')
        generator = GeminiVideoGenerator(api_key, str(base_path), base_url=base_url)
        
        expected_video_dir = base_path / 'pub' / 'media' / 'video'
        assert expected_video_dir.exists()
        assert expected_video_dir.is_dir()


class TestIntegration:
    """Integration tests that verify end-to-end functionality using mock server"""
    
    def test_full_video_generation_flow(self, temp_dir, test_image, api_key, mock_server):
        """Test complete video generation flow using mock server"""
        # Use base_url from mock_server fixture
        base_url = mock_server['base_url']
        generator = GeminiVideoGenerator(api_key, str(temp_dir), base_url=base_url)
        
        # Generate video (uses mock server)
        operation = generator.generate_video_from_image(
            test_image,
            'Test product showcase',
            '16:9',
            False
        )
        
        assert 'operationName' in operation
        
        # Poll for completion (mock server completes after ~5 seconds)
        result = generator.poll_video_operation(
            operation['operationName'],
            max_wait_seconds=10,  # Allow time for mock server
            poll_interval_seconds=1,
            cache_key=operation.get('cacheKey')
        )
        
        # Verify video was saved
        assert result['status'] == 'completed'
        assert 'videoPath' in result
        assert 'videoUrl' in result
        
        video_path = Path(result['videoPath'])
        
        # CRITICAL: Verify video file exists and is saved in correct directory
        assert video_path.exists(), f"Video file not found at {video_path}"
        assert video_path.is_file(), f"Video path is not a file: {video_path}"
        assert video_path.parent == generator.video_dir, f"Video not saved in correct directory. Expected: {generator.video_dir}, Got: {video_path.parent}"
        assert video_path.suffix == '.mp4', f"Video file should be .mp4, got {video_path.suffix}"
        
        # Verify video file has content
        video_size = video_path.stat().st_size
        assert video_size > 0, f"Video file is empty (size: {video_size} bytes)"
        
        # Verify video URL is generated correctly
        assert result['videoUrl'].endswith('.mp4'), f"Video URL should end with .mp4, got {result['videoUrl']}"
        
        # Verify directory structure matches PHP: pub/media/video/
        expected_dir = temp_dir / 'pub' / 'media' / 'video'
        assert video_path.parent == expected_dir, f"Video directory mismatch. Expected: {expected_dir}, Got: {video_path.parent}"
        
        print(f"\n✓ Video successfully saved to: {video_path}")
        print(f"✓ Video URL: {result['videoUrl']}")
        print(f"✓ Video size: {video_size} bytes")
        print(f"✓ Directory structure correct: {video_path.parent}")
        print(f"✓ Using mock server at: {mock_server['base_url']}")


if __name__ == '__main__':
    pytest.main([__file__, '-v'])
