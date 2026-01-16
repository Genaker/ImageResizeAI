#!/usr/bin/env python3
"""
Pytest integration tests for agento_video.py
Tests video generation, caching, and file saving functionality.
"""

import pytest
import json
import os
import tempfile
import shutil
from pathlib import Path
from unittest.mock import Mock, patch, MagicMock
import base64
import sys

# Add the current directory to path to import agento_video
sys.path.insert(0, str(Path(__file__).parent))
from agento_video import GeminiVideoGenerator


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
def generator(temp_dir, api_key):
    """Create a GeminiVideoGenerator instance"""
    return GeminiVideoGenerator(api_key, str(temp_dir))


class TestGeminiVideoGenerator:
    """Test suite for GeminiVideoGenerator"""
    
    def test_is_available(self, generator, api_key):
        """Test service availability check"""
        assert generator.is_available() is True
        
        # Test with empty API key
        empty_generator = GeminiVideoGenerator('', str(generator.base_path))
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
        assert cached['videoUrl'] == f'/media/video/{filename}'
        assert cached['videoPath'] == str(video_path)
        assert cached['status'] == 'completed'
    
    def test_get_cached_video_not_exists(self, generator):
        """Test retrieving non-existent cached video"""
        cached = generator.get_cached_video('non_existent_key')
        assert cached is None
    
    @patch('agento_video.requests.get')
    def test_download_video_with_redirects(self, mock_get, generator):
        """Test video download with redirect handling"""
        # Mock redirect chain
        redirect_response = Mock()
        redirect_response.url = 'https://redirected-url.com/video.mp4'
        
        final_response = Mock()
        final_response.history = [redirect_response]
        final_response.url = 'https://final-url.com/video.mp4'
        final_response.status_code = 200
        final_response.content = b'video content data'
        final_response.raise_for_status = Mock()
        
        mock_get.return_value = final_response
        
        video_content = generator.download_video_with_redirects('https://api.example.com/video')
        
        assert video_content == b'video content data'
        mock_get.assert_called_once()
        call_kwargs = mock_get.call_args[1]
        assert call_kwargs['allow_redirects'] is True
        assert call_kwargs['timeout'] == 300
    
    @patch('agento_video.requests.get')
    def test_download_video_appends_api_key(self, mock_get, generator):
        """Test that API key is appended to URI"""
        response = Mock()
        response.history = []
        response.url = 'https://api.example.com/video'
        response.status_code = 200
        response.content = b'video content'
        response.raise_for_status = Mock()
        mock_get.return_value = response
        
        uri = 'https://generativelanguage.googleapis.com/v1beta/files/123:download?alt=media'
        generator.download_video_with_redirects(uri)
        
        # Check that API key was appended
        call_args = mock_get.call_args[0][0]
        assert 'key=' in call_args
        assert generator.api_key in call_args
    
    def test_save_video(self, generator, temp_dir):
        """Test saving video to filesystem"""
        cache_key = 'test_save_key'
        
        with patch.object(generator, 'download_video_with_redirects') as mock_download:
            mock_download.return_value = b'fake video content'
            
            video_path = generator.save_video('https://api.example.com/video.mp4', cache_key)
            
            assert video_path == str(generator.video_dir / f'veo_{cache_key}.mp4')
            assert Path(video_path).exists()
            assert Path(video_path).read_bytes() == b'fake video content'
    
    @patch('agento_video.requests.post')
    @patch('agento_video.requests.get')
    def test_generate_video_from_image_success(self, mock_get, mock_post, generator, test_image):
        """Test successful video generation"""
        # Mock API response for video generation
        operation_response = Mock()
        operation_response.status_code = 200
        operation_response.json.return_value = {
            'name': 'operations/test-operation-123',
            'done': False
        }
        mock_post.return_value = operation_response
        
        # Mock polling response (not called in this test, but needed for import)
        mock_get.return_value = Mock()
        
        result = generator.generate_video_from_image(
            test_image,
            'Test prompt',
            '16:9',
            False
        )
        
        assert 'operationName' in result
        assert result['operationName'] == 'operations/test-operation-123'
        assert result['status'] == 'running'
        assert 'cacheKey' in result
    
    @patch('agento_video.requests.post')
    def test_generate_video_from_image_cached(self, mock_post, generator, test_image, temp_dir):
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
        assert result['videoUrl'] == f'/media/video/{filename}'
        assert result['videoPath'] == str(video_path)
        # Should not make API call
        mock_post.assert_not_called()
    
    @patch('agento_video.requests.get')
    def test_poll_video_operation_success(self, mock_get, generator):
        """Test successful video operation polling"""
        operation_name = 'operations/test-operation-123'
        cache_key = 'test_cache_key'
        
        # Mock polling responses
        # First call: operation not done
        not_done_response = Mock()
        not_done_response.status_code = 200
        not_done_response.json.return_value = {
            'name': operation_name,
            'done': False
        }
        
        # Second call: operation done with video
        done_response = Mock()
        done_response.status_code = 200
        done_response.json.return_value = {
            'name': operation_name,
            'done': True,
            'response': {
                'generateVideoResponse': {
                    'generatedSamples': [
                        {
                            'video': {
                                'uri': 'https://api.example.com/video.mp4'
                            }
                        }
                    ]
                }
            }
        }
        
        mock_get.side_effect = [not_done_response, done_response]
        
        with patch.object(generator, 'save_video') as mock_save:
            mock_save.return_value = str(generator.video_dir / 'veo_test.mp4')
            
            result = generator.poll_video_operation(
                operation_name,
                max_wait_seconds=60,  # Shorter timeout for test
                poll_interval_seconds=1,  # Faster polling for test
                cache_key=cache_key
            )
            
            assert result['status'] == 'completed'
            assert 'videoUrl' in result
            assert 'videoPath' in result
            assert 'embedUrl' in result
    
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
    
    def test_video_directory_creation(self, temp_dir, api_key):
        """Test that video directory is created automatically"""
        base_path = temp_dir / 'magento'
        base_path.mkdir()
        
        generator = GeminiVideoGenerator(api_key, str(base_path))
        
        expected_video_dir = base_path / 'pub' / 'media' / 'video'
        assert expected_video_dir.exists()
        assert expected_video_dir.is_dir()


class TestIntegration:
    """Integration tests that verify end-to-end functionality"""
    
    @patch('agento_video.requests.post')
    @patch('agento_video.requests.get')
    def test_full_video_generation_flow(self, mock_get, mock_post, temp_dir, test_image, api_key):
        """Test complete video generation flow"""
        generator = GeminiVideoGenerator(api_key, str(temp_dir))
        
        # Mock API responses
        operation_response = Mock()
        operation_response.status_code = 200
        operation_response.json.return_value = {
            'name': 'operations/test-op-456',
            'done': False
        }
        mock_post.return_value = operation_response
        
        # Mock polling - operation completes
        done_response = Mock()
        done_response.status_code = 200
        done_response.json.return_value = {
            'name': 'operations/test-op-456',
            'done': True,
            'response': {
                'generateVideoResponse': {
                    'generatedSamples': [
                        {
                            'video': {
                                'uri': 'https://api.example.com/files/video123:download?alt=media'
                            }
                        }
                    ]
                }
            }
        }
        mock_get.side_effect = [
            Mock(status_code=200, json=lambda: {'name': 'operations/test-op-456', 'done': False}),
            done_response,
            Mock(status_code=200, content=b'video binary data', raise_for_status=Mock())
        ]
        
        # Generate video
        operation = generator.generate_video_from_image(
            test_image,
            'Test product showcase',
            '16:9',
            False
        )
        
        assert 'operationName' in operation
        
        # Poll for completion
        with patch.object(generator, 'download_video_with_redirects') as mock_download:
            mock_download.return_value = b'video binary content'
            
            result = generator.poll_video_operation(
                operation['operationName'],
                max_wait_seconds=60,
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
            
            # Verify video URL format matches PHP implementation
            assert result['videoUrl'].startswith('/media/video/'), f"Video URL should start with /media/video/, got {result['videoUrl']}"
            assert result['videoUrl'].endswith('.mp4'), f"Video URL should end with .mp4, got {result['videoUrl']}"
            
            # Verify directory structure matches PHP: pub/media/video/
            expected_dir = temp_dir / 'pub' / 'media' / 'video'
            assert video_path.parent == expected_dir, f"Video directory mismatch. Expected: {expected_dir}, Got: {video_path.parent}"
            
            print(f"\n✓ Video successfully saved to: {video_path}")
            print(f"✓ Video URL: {result['videoUrl']}")
            print(f"✓ Video size: {video_size} bytes")
            print(f"✓ Directory structure correct: {video_path.parent}")


if __name__ == '__main__':
    pytest.main([__file__, '-v'])
