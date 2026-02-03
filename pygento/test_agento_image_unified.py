#!/usr/bin/env python3
"""
Unified comprehensive tests for agento_image.py / NanaBabanaImageService.

This file merges all image-related tests into a single comprehensive suite:
- Mock server integration tests
- HTTP layer mocking tests
- CLI functionality tests
- Real API tests (optional)
- Service core functionality tests

Run with:
  # Mock tests only
  python3 -m pytest test_agento_image_unified.py -v

  # Include real API tests (requires GEMINI_API_KEY)
  GEMINI_API_KEY=your_key python3 -m pytest test_agento_image_unified.py -v

  # Integration tests with mock server
  python3 -m pytest test_agento_image_unified.py::TestMockServerIntegration -v
"""
import pytest
import os
import sys
import json
import tempfile
import shutil
import subprocess
import signal
import time
from pathlib import Path
from unittest.mock import Mock, MagicMock, patch
from io import StringIO
from PIL import Image

# Add current directory to path for imports
sys.path.insert(0, str(Path(__file__).parent))
from agento_image import NanaBabanaImageService, main


class TestNanaBabanaImageServiceCore:
    """Core functionality tests for NanaBabanaImageService."""

    @pytest.fixture
    def temp_dir(self):
        """Create a temporary directory for tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def test_jpeg(self, temp_dir):
        """Create a fake JPEG file."""
        p = temp_dir / 'model.jpg'
        # Minimal JPEG header + some data
        p.write_bytes(b'\xff\xd8\xff\xe0\x00\x10JFIF' + b'0' * 100)
        return str(p)

    @pytest.fixture
    def test_png(self, temp_dir):
        """Create a fake PNG file."""
        p = temp_dir / 'look.png'
        # Minimal PNG header + some data
        p.write_bytes(b'\x89PNG\r\n\x1a\n' + b'0' * 100)
        return str(p)

    def test_service_initialization_defaults(self, temp_dir):
        """Test service initialization with default values."""
        svc = NanaBabanaImageService()

        assert svc.api_key == ''
        assert svc.base_url == 'https://generativelanguage.googleapis.com/v1beta'
        assert svc.model_name == 'gemini-2.5-flash-image'
        assert svc.base_path == Path.cwd()
        assert svc.output_dir == Path.cwd() / 'genai'
        assert svc.verbose is False
        assert svc.use_sdk is True

    def test_service_initialization_custom(self, temp_dir):
        """Test service initialization with custom values."""
        custom_path = temp_dir / 'custom'
        svc = NanaBabanaImageService(
            api_key='test_key',
            base_path=str(custom_path),
            base_url='http://custom.url/v1beta',
            verbose=True,
            use_sdk=False
        )

        assert svc.api_key == 'test_key'
        assert svc.base_url == 'http://custom.url/v1beta'
        assert svc.base_path == custom_path
        assert svc.output_dir == custom_path / 'genai'
        assert svc.verbose is True
        assert svc.use_sdk is False

    def test_service_initialization_env_vars(self, temp_dir, monkeypatch):
        """Test service initialization with environment variables."""
        monkeypatch.setenv('GEMINI_API_KEY', 'env_api_key')
        monkeypatch.setenv('GOOGLE_API_DOMAIN', 'https://env.domain/v1beta')
        monkeypatch.setenv('MODEL_NAME', 'env-model')

        svc = NanaBabanaImageService()

        assert svc.api_key == 'env_api_key'
        assert svc.base_url == 'https://env.domain/v1beta'
        assert svc.model_name == 'env-model'

    def test_is_available(self):
        """Test is_available method."""
        # No API key
        svc = NanaBabanaImageService()
        assert svc.is_available() is False

        # With API key
        svc = NanaBabanaImageService(api_key='test_key')
        assert svc.is_available() is True

    @patch('PIL.Image.open')
    def test_read_image_jpeg(self, mock_image_open, test_jpeg):
        """Test reading JPEG image."""
        # Create a mock image with a mode that needs conversion
        mock_img = MagicMock()
        mock_img.mode = 'P'  # Palette mode, needs conversion
        mock_converted = MagicMock()
        mock_img.convert.return_value = mock_converted
        mock_image_open.return_value = mock_img
        
        svc = NanaBabanaImageService()
        img = svc._load_image(test_jpeg)

        mock_image_open.assert_called_once_with(test_jpeg)
        mock_img.convert.assert_called_once_with('RGB')
        assert img == mock_converted

    @patch('PIL.Image.open')
    def test_read_image_png(self, mock_image_open, test_png):
        """Test reading PNG image."""
        # Create a mock image
        mock_img = MagicMock()
        mock_img.mode = 'RGBA'
        mock_img.convert.return_value = mock_img
        mock_image_open.return_value = mock_img
        
        svc = NanaBabanaImageService()
        img = svc._load_image(test_png)

        mock_image_open.assert_called_once_with(test_png)
        # PNG with RGBA shouldn't be converted
        mock_img.convert.assert_not_called()
        assert img == mock_img

    @patch('PIL.Image.open')
    def test_read_image_nonexistent(self, mock_image_open):
        """Test reading nonexistent image."""
        mock_image_open.side_effect = FileNotFoundError("No such file")
        
        svc = NanaBabanaImageService()

        with pytest.raises(FileNotFoundError):
            svc._load_image('/nonexistent/file.jpg')


class TestSDKMocking:
    """HTTP layer mocking tests (no external dependencies)."""

    @pytest.fixture
    def temp_dir(self):
        """Create a temporary directory for tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def test_jpeg(self, temp_dir):
        """Create a fake JPEG file."""
        p = temp_dir / 'model.jpg'
        p.write_bytes(b'\xff\xd8\xff\xe0\x00\x10JFIF' + b'0' * 100)
        return str(p)

    @patch('PIL.Image.open')
    @patch('google.genai.Client')
    def test_generate_image_sdk_success(self, mock_client_class, mock_image_open, test_jpeg):
        """Test successful SDK-based generation."""
        # Mock PIL Image
        mock_img = Mock()
        mock_img.convert.return_value = mock_img  # Mock the convert call
        mock_image_open.return_value = mock_img
        
        # Mock the _load_image method to return our mock image
        with patch.object(NanaBabanaImageService, '_load_image', return_value=mock_img) as mock_load:
            # Mock genai client and response
            mock_client = Mock()
            mock_client_class.return_value = mock_client
            
            # Mock response with inline data
            mock_part = Mock()
            mock_part.inline_data.data = b'fake-image-data'
            mock_part.inline_data.mime_type = 'image/jpeg'
            
            mock_candidate = Mock()
            mock_candidate.content.parts = [mock_part]
            
            mock_response = Mock()
            mock_response.candidates = [mock_candidate]
            mock_client.models.generate_content.return_value = mock_response

            svc = NanaBabanaImageService(api_key='test_key')

            result = svc.generate_image(test_jpeg, test_jpeg, 'test prompt')

            # Verify _load_image was called for both images
            assert mock_load.call_count == 2
            mock_load.assert_any_call(test_jpeg)
            
            # Verify SDK was called correctly
            mock_client.models.generate_content.assert_called_once()
            call_args = mock_client.models.generate_content.call_args
            assert call_args[1]['model'] == 'gemini-2.5-flash-image'
            assert call_args[1]['contents'] == ['test prompt', mock_img, mock_img]

            # Check result format
            assert result['done'] is True
            assert 'response' in result
            assert 'generateImageResponse' in result['response']

    @patch('PIL.Image.open')
    @patch('google.genai.Client')
    def test_generate_image_sdk_error(self, mock_client_class, mock_image_open, test_jpeg):
        """Test SDK generation with error."""
        # Mock PIL Image
        mock_img = Mock()
        mock_image_open.return_value = mock_img
        
        # Mock genai client to raise exception
        mock_client = Mock()
        mock_client_class.return_value = mock_client
        mock_client.models.generate_content.side_effect = Exception('SDK Error')

        svc = NanaBabanaImageService(api_key='test_key')

        with pytest.raises(Exception, match='SDK Error'):
            svc.generate_image(test_jpeg, test_jpeg, 'test prompt')

    @patch('requests.get')
    def test_poll_operation_success(self, mock_get):
        """Test successful operation polling."""
        # Mock responses: first not done, second done
        mock_response1 = Mock()
        mock_response1.json.return_value = {'done': False}

        mock_response2 = Mock()
        mock_response2.json.return_value = {
            'done': True,
            'response': {'result': 'success'}
        }

        mock_get.side_effect = [mock_response1, mock_response2]

        svc = NanaBabanaImageService(api_key='test_key')

        result = svc.poll_operation('operations/test-op', max_wait_seconds=10, poll_interval_seconds=0.1)

        assert result['done'] is True
        assert result['response']['result'] == 'success'

        # Should have made 2 GET requests
        assert mock_get.call_count == 2

    @patch('requests.get')
    def test_poll_operation_timeout(self, mock_get):
        """Test operation polling timeout."""
        mock_response = Mock()
        mock_response.json.return_value = {'done': False}
        mock_get.return_value = mock_response

        svc = NanaBabanaImageService(api_key='test_key')

        with pytest.raises(RuntimeError, match='Operation timed out'):
            svc.poll_operation('operations/test-op', max_wait_seconds=0.1, poll_interval_seconds=0.05)

    def test_save_asset_from_operation_success(self, temp_dir, monkeypatch):
        """Test successful asset saving."""
        # Mock the download response
        class MockResponse:
            def __init__(self, content):
                self.status_code = 200
                self.content = content

            def raise_for_status(self):
                pass

        monkeypatch.setattr('requests.get', lambda url, timeout=60: MockResponse(b'fake-image-data'))

        operation_data = {
            'response': {
                'generateImageResponse': {
                    'generatedSamples': [
                        {'image': {'uri': 'http://example.com/image.jpg'}}
                    ]
                }
            }
        }

        svc = NanaBabanaImageService(base_path=str(temp_dir))
        saved_path = svc.save_asset_from_operation(operation_data, cache_key='test_asset')

        expected_path = temp_dir / 'genai' / 'test_asset.jpg'
        assert saved_path == str(expected_path)
        assert expected_path.exists()
        assert expected_path.read_bytes() == b'fake-image-data'

    def test_save_asset_from_operation_no_samples(self):
        """Test asset saving with no generated samples."""
        operation_data = {
            'response': {
                'generateImageResponse': {
                    'generatedSamples': []
                }
            }
        }

        svc = NanaBabanaImageService()

        with pytest.raises(RuntimeError, match='No generated samples found'):
            svc.save_asset_from_operation(operation_data)

    def test_save_asset_from_operation_no_uri(self):
        """Test asset saving with no URI in sample."""
        operation_data = {
            'response': {
                'generateImageResponse': {
                    'generatedSamples': [
                        {'image': {}}
                    ]
                }
            }
        }

        svc = NanaBabanaImageService()

        with pytest.raises(RuntimeError, match='No URI or data found for generated asset'):
            svc.save_asset_from_operation(operation_data)


class TestCLIFunctionality:
    """Test the CLI main function."""

    @pytest.fixture
    def temp_dir(self):
        """Create a temporary directory for tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def test_jpeg(self, temp_dir):
        """Create a fake JPEG file."""
        p = temp_dir / 'model.jpg'
        p.write_bytes(b'\xff\xd8\xff\xe0\x00\x10JFIF' + b'0' * 100)
        return str(p)

    @pytest.fixture
    def test_png(self, temp_dir):
        """Create a fake PNG file."""
        p = temp_dir / 'look.png'
        p.write_bytes(b'\x89PNG\r\n\x1a\n' + b'0' * 100)
        return str(p)

    def test_cli_main_missing_required_args(self, monkeypatch):
        """Test main with missing required arguments."""
        monkeypatch.setattr('sys.argv', ['agento_image.py'])
        with pytest.raises(SystemExit):
            main()

    def test_cli_main_help(self, monkeypatch, capsys):
        """Test main with --help."""
        monkeypatch.setattr('sys.argv', ['agento_image.py', '--help'])
        with pytest.raises(SystemExit):
            main()

        captured = capsys.readouterr()
        assert 'Generate image from one or two input images' in captured.out

    @patch('agento_image.NanaBabanaImageService.generate_image')
    @patch('json.dumps')
    def test_cli_main_success(self, mock_dumps, mock_generate, test_jpeg, test_png, monkeypatch):
        """Test successful main execution."""
        mock_generate.return_value = {'operationName': 'test-op', 'status': 'running'}
        mock_dumps.return_value = '{"operationName": "test-op", "status": "running"}'

        monkeypatch.setattr('sys.argv', [
            'agento_image.py',
            '--model-image', test_jpeg,
            '--look-image', test_png,
            '--prompt', 'Test prompt',
            '--api-key', 'test_key'
        ])

        main()

        mock_generate.assert_called_once()
        mock_dumps.assert_called_once_with({'operationName': 'test-op', 'status': 'running'})

    @patch('agento_image.generate_descriptive_filename')
    @patch('agento_image.NanaBabanaImageService.generate_image')
    @patch('json.dumps')
    def test_cli_main_with_urls(self, mock_dumps, mock_generate, mock_filename_gen, monkeypatch, capsys):
        """Test CLI main with URL parameters (like the working example)."""
        # Mock filename generation
        mock_filename_gen.return_value = 'professional_fashion_1738464000_fashion'
        
        # Mock successful response in the format that generate_image actually returns
        mock_generate.return_value = {
            'done': True,
            'response': {
                'generateImageResponse': {
                    'generatedSamples': [{
                        'image': {
                            'data': b'fake-image-data',
                            'mime_type': 'image/jpeg'
                        }
                    }]
                }
            }
        }
        mock_dumps.return_value = '{"success": true, "status": "completed", "saved_path": "pub/media/genai/professional_fashion_1738464000_fashion.jpg", "message": "Image generated and saved to pub/media/genai/professional_fashion_1738464000_fashion.jpg"}'

        # Set up command line arguments with the exact URLs from the working example
        monkeypatch.setattr('sys.argv', [
            'agento_image.py',
            '--model-image', 'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg',
            '--look-image', 'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg',
            '--prompt', 'Create a professional fashion photo combining these images',
            '--api-key', 'test_key'
        ])

        main()

        # Verify generate_image was called with the URLs
        mock_generate.assert_called_once()
        args, kwargs = mock_generate.call_args
        # The first two arguments should be the URLs
        assert args[0] == 'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg'
        assert args[1] == 'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg'
        assert args[2] == 'Create a professional fashion photo combining these images'
        
        # Verify filename generation was called with correct parameters
        mock_filename_gen.assert_called_once_with(
            'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg',
            'https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg',
            'Create a professional fashion photo combining these images'
        )
        
        # Verify JSON output
        mock_dumps.assert_called_once_with({
            'success': True,
            'status': 'completed',
            'saved_path': 'pub/media/genai/professional_fashion_1738464000_fashion.jpg',
            'message': 'Image generated and saved to pub/media/genai/professional_fashion_1738464000_fashion.jpg'
        })

        # Verify the printed output contains the correct path
        captured = capsys.readouterr()
        assert 'pub/media/genai/professional_fashion_1738464000_fashion.jpg' in captured.out

    @patch('agento_image.generate_descriptive_filename')
    @patch('agento_image.NanaBabanaImageService.generate_image')
    @patch('json.dumps')
    def test_cli_main_with_image_aliases(self, mock_dumps, mock_generate, mock_filename_gen, monkeypatch):
        """Test CLI main with --image-1 and --image-2 aliases."""
        # Mock filename generation
        mock_filename_gen.return_value = 'test_aliases_1738464000'
        
        # Mock successful response in the format that generate_image actually returns
        mock_generate.return_value = {
            'done': True,
            'response': {
                'generateImageResponse': {
                    'generatedSamples': [{
                        'image': {
                            'data': b'fake-image-data',
                            'mime_type': 'image/jpeg'
                        }
                    }]
                }
            }
        }
        mock_dumps.return_value = '{"success": true, "status": "completed", "saved_path": "pub/media/lookbook/test_aliases_1738464000.jpg", "message": "Image generated and saved to pub/media/lookbook/test_aliases_1738464000.jpg"}'

        # Set up command line arguments using the new aliases
        monkeypatch.setattr('sys.argv', [
            'agento_image.py',
            '--image-1', 'model.jpg',
            '--image-2', 'look.jpg',
            '--prompt', 'Test with aliases',
            '--api-key', 'test_key'
        ])

        main()

        # Verify generate_image was called with the correct arguments
        mock_generate.assert_called_once()
        args, kwargs = mock_generate.call_args
        assert args[0] == 'model.jpg'  # image_1
        assert args[1] == 'look.jpg'  # image_2
        assert args[2] == 'Test with aliases'
        
        # Verify filename generation was called
        mock_filename_gen.assert_called_once_with('model.jpg', 'look.jpg', 'Test with aliases')
        
        # Verify JSON output
        mock_dumps.assert_called_once()


class TestMockServerIntegration:
    """Integration tests using the mock server."""

    @pytest.fixture(scope='session')
    def mock_server(self):
        """Start the mock server for integration tests."""
        script_dir = Path(__file__).parent
        mock_server_script = script_dir / 'mock_veo_server.py'
        if not mock_server_script.exists():
            pytest.skip("Mock server not found")

        import random
        port = random.randint(9000, 9999)
        host = '127.0.0.1'

        server_process = subprocess.Popen(
            [sys.executable, str(mock_server_script), '--port', str(port), '--host', host],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            preexec_fn=os.setsid if hasattr(os, 'setsid') else None
        )

        time.sleep(1)
        if server_process.poll() is not None:
            stdout, stderr = server_process.communicate()
            pytest.fail(f"Mock server failed to start:\nSTDOUT: {stdout.decode()}\nSTDERR: {stderr.decode()}")

        base_url = f'http://{host}:{port}/v1beta'
        os.environ['GOOGLE_API_DOMAIN'] = base_url

        yield {'host': host, 'port': port, 'base_url': base_url, 'process': server_process}

        # Cleanup
        try:
            if hasattr(os, 'setsid'):
                os.killpg(os.getpgid(server_process.pid), signal.SIGTERM)
            else:
                server_process.terminate()
            server_process.wait(timeout=5)
        except (subprocess.TimeoutExpired, ProcessLookupError):
            server_process.kill()
            server_process.wait()

        if 'GOOGLE_API_DOMAIN' in os.environ:
            del os.environ['GOOGLE_API_DOMAIN']

    @pytest.fixture
    def temp_dir(self):
        """Create temporary directory for integration tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def test_image1(self, temp_dir):
        """Create test image 1."""
        p = temp_dir / 'model.jpg'
        p.write_bytes(b'\xff\xd8\xff\xe0' + b'0' * 100)
        return str(p)

    @pytest.fixture
    def test_image2(self, temp_dir):
        """Create test image 2."""
        p = temp_dir / 'look.jpg'
        p.write_bytes(b'\xff\xd8\xff\xe0' + b'1' * 100)
        return str(p)

    @patch('PIL.Image.open')
    @patch('google.genai.Client')
    def test_full_integration_workflow(self, mock_client_class, mock_image_open, temp_dir, test_image1, test_image2):
        """Test the full workflow: generate -> save (synchronous)."""
        # Mock PIL Images
        mock_img1 = Mock()
        mock_img2 = Mock()
        mock_image_open.side_effect = [mock_img1, mock_img2]
        
        # Mock genai client and response
        mock_client = Mock()
        mock_client_class.return_value = mock_client
        
        # Mock response with inline data
        mock_part = Mock()
        mock_part.inline_data.data = b'fake-generated-image-data'
        mock_part.inline_data.mime_type = 'image/jpeg'
        
        mock_candidate = Mock()
        mock_candidate.content.parts = [mock_part]
        
        mock_response = Mock()
        mock_response.candidates = [mock_candidate]
        mock_client.models.generate_content.return_value = mock_response

        svc = NanaBabanaImageService(
            api_key='test_api_key',
            base_path=str(temp_dir)
        )

        # Generate image (now synchronous)
        op = svc.generate_image(test_image1, test_image2, 'Generate a 100x100 lookbook image')
        assert op['done'] is True
        assert 'response' in op

        # Save the asset directly (no polling needed)
        saved_path = svc.save_asset_from_operation(op, cache_key='integration_test')
        assert saved_path is not None

        saved_file = Path(saved_path)
        assert saved_file.exists()
        assert saved_file.read_bytes() == b'fake-generated-image-data'
        assert saved_file.stat().st_size > 0


@pytest.mark.real_api
class TestRealAPI:
    """Real API tests (only run when explicitly enabled)."""

    @pytest.fixture
    def temp_dir(self):
        """Create a temporary directory for tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def real_api_key(self):
        """Get real API key from environment."""
        api_key = os.getenv('GEMINI_API_KEY')
        if not api_key:
            pytest.skip("GEMINI_API_KEY not set")
        return api_key

    @pytest.fixture
    def real_images(self, temp_dir):
        """Download real test images."""
        import requests

        # Use the same URLs from the conversation
        model_url = "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg"
        dress_url = "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg"

        model_path = temp_dir / 'model.jpg'
        dress_path = temp_dir / 'dress.jpg'

        # Download images
        for url, path in [(model_url, model_path), (dress_url, dress_path)]:
            resp = requests.get(url, timeout=30)
            resp.raise_for_status()
            path.write_bytes(resp.content)

        return str(model_path), str(dress_path)

    def test_real_api_generation(self, real_api_key, real_images, temp_dir):
        """Test with real API (requires GEMINI_API_KEY and network)."""
        model_image, dress_image = real_images

        svc = NanaBabanaImageService(
            api_key=real_api_key,
            base_path=str(temp_dir),
            base_url='https://generativelanguage.googleapis.com/v1beta'
        )

        # Test real image generation with Gemini 2.5 Flash Image
        result = svc.generate_image(model_image, dress_image, 'Generate a fashion lookbook combining these two images')
        
        # Should return synchronous result
        assert result['done'] is True
        assert 'response' in result
        assert 'generateImageResponse' in result['response']
        
        # Save the generated image
        saved_path = svc.save_asset_from_operation(result, cache_key='real_api_test')
        assert saved_path is not None
        
        saved_file = Path(saved_path)
        assert saved_file.exists()
        assert saved_file.suffix == '.jpg'


class TestErrorHandling:
    """Test error handling scenarios."""

    @pytest.fixture
    def temp_dir(self):
        """Create a temporary directory for tests."""
        p = Path(tempfile.mkdtemp())
        yield p
        shutil.rmtree(p)

    @pytest.fixture
    def test_jpeg(self, temp_dir):
        """Create a fake JPEG file."""
        p = temp_dir / 'model.jpg'
        p.write_bytes(b'\xff\xd8\xff\xe0\x00\x10JFIF' + b'0' * 100)
        return str(p)

    def test_generate_image_no_api_key(self, test_jpeg):
        """Test generate_image without API key."""
        svc = NanaBabanaImageService()

        with pytest.raises(RuntimeError, match='API key not configured'):
            svc.generate_image(test_jpeg, test_jpeg, 'test prompt')

    def test_generate_image_invalid_image_path(self):
        """Test generate_image with invalid image path."""
        svc = NanaBabanaImageService(api_key='test_key')

        with pytest.raises(FileNotFoundError):
            svc.generate_image('/invalid/path1.jpg', '/invalid/path2.jpg', 'test')

    @patch('PIL.Image.open')
    @patch('google.genai.Client')
    def test_generate_image_sdk_timeout(self, mock_client_class, mock_image_open, test_jpeg):
        """Test SDK timeout during generation."""
        # Mock PIL Image
        mock_img = Mock()
        mock_image_open.return_value = mock_img
        
        # Mock genai client to raise timeout
        mock_client = Mock()
        mock_client_class.return_value = mock_client
        from requests.exceptions import Timeout
        mock_client.models.generate_content.side_effect = Timeout('Request timed out')

        svc = NanaBabanaImageService(api_key='test_key')

        with pytest.raises(Timeout):
            svc.generate_image(test_jpeg, test_jpeg, 'test prompt')

    @patch('requests.get')
    def test_poll_operation_http_error(self, mock_get):
        """Test HTTP error during polling."""
        from requests.exceptions import HTTPError
        mock_get.side_effect = HTTPError('404 Not Found')

        svc = NanaBabanaImageService(api_key='test_key')

        with pytest.raises(HTTPError):
            svc.poll_operation('operations/test-op')

    @patch('requests.get')
    def test_save_asset_download_error(self, mock_get):
        """Test download error during asset saving."""
        from requests.exceptions import HTTPError
        mock_response = Mock()
        mock_response.raise_for_status.side_effect = HTTPError('404 Not Found')
        mock_get.return_value = mock_response

        operation_data = {
            'response': {
                'generateImageResponse': {
                    'generatedSamples': [
                        {'image': {'uri': 'http://example.com/missing.jpg'}}
                    ]
                }
            }
        }

        svc = NanaBabanaImageService()

        with pytest.raises(HTTPError):
            svc.save_asset_from_operation(operation_data)


if __name__ == '__main__':
    pytest.main([__file__])
