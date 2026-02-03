#!/usr/bin/env python3
"""
Simple CLI to generate a lookbook-like asset from two images using a "nana babana" model.

This mirrors the structure of `agento_video.py` but is intentionally minimal for testing
and integration with the existing `mock_veo_server.py`. It posts to an endpoint
containing `:predictLongRunning` so the mock server will accept and simulate the
async operation flow.
"""
import argparse
import json
import os
import sys
import base64
import time
from pathlib import Path
from typing import Optional, Dict, Any
import requests

# Try latest SDK first (google-genai), fall back to google-generativeai 0.8.6
try:
    from google import genai
    _genai_available = True
    _genai_version = 'latest (google-genai)'
except ImportError:
    try:
        from google import generativeai as genai
        _genai_available = True
        _genai_version = 'google-generativeai 0.8.6'
    except ImportError:
        genai = None
        _genai_available = False
        _genai_version = None


class NanaBabanaImageService:
    def __init__(self, api_key: Optional[str] = None, base_path: Optional[str] = None, base_url: Optional[str] = None, verbose: bool = False, use_sdk: bool = True, output_dir: Optional[str] = None):
        self.api_key = api_key or os.getenv('GEMINI_API_KEY', '')
        self.verbose = verbose
        self.use_sdk = use_sdk  # Whether to prefer SDK over HTTP
        # Base URL similar to agento_video mock usage (e.g. http://127.0.0.1:8080/v1beta)
        if base_url:
            self.base_url = base_url.rstrip('/')
        else:
            self.base_url = os.getenv('GOOGLE_API_DOMAIN', 'https://generativelanguage.googleapis.com/v1beta')
        # Use the actual Gemini image generation model
        self.model_name = os.getenv('MODEL_NAME', 'gemini-2.5-flash-image')  # Can be overridden via env var
        # base path for saving output
        self.base_path = Path(base_path) if base_path else Path.cwd()
        self.output_dir = self.base_path / (output_dir if output_dir is not None else 'genai')
        self.output_dir.mkdir(parents=True, exist_ok=True)

    def is_available(self) -> bool:
        return bool(self.api_key)

    def _load_image(self, image_path: str):
        """Load image from file path or URL."""
        import tempfile
        import requests
        from PIL import Image
        from urllib.parse import urlparse
        
        # Check if it's a URL
        parsed = urlparse(image_path)
        if parsed.scheme in ('http', 'https'):
            # Download the image
            if self.verbose:
                print(f"[DEBUG] Downloading image from: {image_path}")
            
            response = requests.get(image_path, timeout=30)
            response.raise_for_status()
            
            # Save to temporary file
            with tempfile.NamedTemporaryFile(delete=False, suffix='.jpg') as temp_file:
                temp_file.write(response.content)
                temp_path = temp_file.name
            
            try:
                img = Image.open(temp_path)
                # Convert to RGB if necessary
                if img.mode not in ('RGB', 'RGBA'):
                    img = img.convert('RGB')
                return img
            finally:
                # Clean up temp file
                import os
                os.unlink(temp_path)
        else:
            # Local file
            img = Image.open(image_path)
            # Convert to RGB if necessary
            if img.mode not in ('RGB', 'RGBA'):
                img = img.convert('RGB')
            return img

    def generate_image(self, model_image: str, look_image: Optional[str], prompt: str) -> Dict[str, Any]:
        if not self.is_available():
            raise RuntimeError('API key not configured')

        if not _genai_available:
            raise RuntimeError('Google Gen AI SDK not available')

        # Use SDK for generation
        client = genai.Client(api_key=self.api_key)
        
        # Load images (handles both URLs and local files)
        img1 = self._load_image(model_image)
        contents = [prompt, img1]
        
        if look_image:
            img2 = self._load_image(look_image)
            contents.append(img2)

        if self.verbose:
            images_loaded = [model_image]
            if look_image:
                images_loaded.append(look_image)
            print(f"[DEBUG] Using model: {self.model_name}")
            print(f"[DEBUG] Images loaded: {', '.join(images_loaded)}")

        # Generate content with images and prompt
        response = client.models.generate_content(
            model=self.model_name,
            contents=contents,
        )

        # Convert SDK response to expected format for save_asset_from_operation
        # The save_asset_from_operation expects: response.generateImageResponse.generatedSamples[0].image.uri
        generated_samples = []
        for part in response.candidates[0].content.parts:
            if part.inline_data:
                # For inline data, we need to save it directly
                # Create a temporary file or return data that save_asset_from_operation can handle
                generated_samples.append({
                    'image': {
                        'data': part.inline_data.data,
                        'mime_type': part.inline_data.mime_type
                    }
                })

        if not generated_samples:
            raise RuntimeError('No generated images found in response')

        # Return in format expected by save_asset_from_operation
        return {
            'done': True,
            'response': {
                'generateImageResponse': {
                    'generatedSamples': generated_samples
                }
            }
        }

    def poll_operation(self, operation_name: str, max_wait_seconds: int = 120, poll_interval_seconds: int = 2) -> Dict[str, Any]:
        poll_url = f"{self.base_url}/{operation_name}"
        headers = {'x-goog-api-key': self.api_key}
        start = time.time()
        while True:
            if time.time() - start > max_wait_seconds:
                raise RuntimeError('Operation timed out')
            resp = requests.get(poll_url, headers=headers, timeout=poll_interval_seconds + 5)
            resp.raise_for_status()
            data = resp.json()
            if data.get('done'):
                return data
            time.sleep(poll_interval_seconds)

    def save_asset_from_operation(self, operation_data: Dict[str, Any], cache_key: Optional[str] = None) -> str:
        # Look at response structure similar to video generator
        resp = operation_data.get('response', {})
        gen = resp.get('generateVideoResponse') or resp.get('generateImageResponse') or {}
        samples = gen.get('generatedSamples') or []
        if not samples:
            raise RuntimeError('No generated samples found in operation response')
        
        sample = samples[0]
        # Check for inline data first (SDK response)
        image_data = sample.get('image', {}).get('data')
        if image_data:
            # Direct data, no download needed
            content = image_data
        else:
            # URI-based download (legacy HTTP response)
            uri = sample.get('video', {}).get('uri') or sample.get('image', {}).get('uri')
            if not uri:
                raise RuntimeError('No URI or data found for generated asset')
            # Download
            r = requests.get(uri, timeout=60)
            r.raise_for_status()
            content = r.content
        
        # Save to file using cache_key or generated name
        name = cache_key or f"lookbook_{int(time.time())}"
        out_path = self.output_dir / f"{name}.jpg"  # Use .jpg extension for images
        out_path.write_bytes(content)
        return str(out_path)


def generate_descriptive_filename(image1: str, image2: Optional[str], prompt: str) -> str:
    """
    Generate a descriptive filename based on the input image filenames and prompt.
    
    Args:
        image1: Path or URL to first image
        image2: Optional path or URL to second image
        prompt: The generation prompt
        
    Returns:
        A filesystem-safe filename
    """
    import re
    import time
    from pathlib import Path
    from urllib.parse import urlparse
    
    def extract_base_name(image_path: str) -> str:
        """Extract meaningful base name from image path or URL."""
        parsed = urlparse(image_path)
        if parsed.scheme in ('http', 'https'):
            # For URLs, get the last part of the path
            path_part = Path(parsed.path).stem
        else:
            # For local paths
            path_part = Path(image_path).stem
        
        # Clean the name: remove common suffixes like _main_1, _1, etc.
        path_part = re.sub(r'_[a-z]+_\d+$', '', path_part)  # Remove _main_1, _front_2, etc.
        path_part = re.sub(r'_\d+$', '', path_part)  # Remove trailing _1, _2, etc.
        
        # Keep only alphanumeric and underscores, replace others
        path_part = re.sub(r'[^\w]', '_', path_part)
        path_part = re.sub(r'_+', '_', path_part).strip('_')
        
        return path_part.lower()
    
    # Extract base names
    base1 = extract_base_name(image1)
    base2 = extract_base_name(image2) if image2 else None
    
    # Combine bases
    if base2 and base1 != base2:
        combined_base = f"{base1}_{base2}"
    else:
        combined_base = base1
    
    # If combined base is too short or generic, add some prompt words
    if len(combined_base) < 5 or combined_base in ('image', 'photo', 'pic'):
        # Extract first few meaningful words from prompt
        words = re.findall(r'\b\w+\b', prompt.lower())
        stop_words = {'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'create', 'make', 'generate', 'image', 'photo', 'picture'}
        meaningful_words = [word for word in words if word not in stop_words and len(word) > 2][:2]
        if meaningful_words:
            combined_base = f"{combined_base}_{'_'.join(meaningful_words)}"
    
    # Sanitize for filesystem
    combined_base = re.sub(r'[^\w\-_]', '_', combined_base)
    combined_base = re.sub(r'_+', '_', combined_base).strip('_')
    
    # Add timestamp for uniqueness
    timestamp = int(time.time())
    
    # Create final filename (limit total length)
    filename = f"{combined_base}_{timestamp}"
    if len(filename) > 100:  # Reasonable filename length limit
        filename = filename[:95] + f"_{timestamp}"
    
    return filename


def main(argv=None):
    parser = argparse.ArgumentParser(description='Generate image from one or two input images (Gemini 2.5 Flash Image / Nano Banana model)')
    parser.add_argument('--api-key', '-k', help='API key (overrides env GEMINI_API_KEY)')
    parser.add_argument('--base-url', '-u', help='Base API URL (overrides GOOGLE_API_DOMAIN)')
    parser.add_argument('--model-image', '--image-1', '-m', required=True, help='Path to Image 1 (model image) or URL (relative to pub/media/, absolute path, or http/https URL)')
    parser.add_argument('--look-image', '--image-2', '-l', help='Path to Image 2 (look/clothing image) or URL (relative to pub/media/, absolute path, or http/https URL) - optional for single image generation')
    parser.add_argument('--prompt', '-p', required=True, help='Prompt for generation')
    parser.add_argument('--base-path', default='pub/media', help='Base path to save generated assets (defaults to pub/media relative to current directory)')
    parser.add_argument('--output-dir', help='Output directory name within base-path (optional, defaults to genai)')
    args = parser.parse_args(argv)

    svc = NanaBabanaImageService(api_key=args.api_key, base_path=args.base_path, base_url=args.base_url, output_dir=args.output_dir)
    op = svc.generate_image(args.model_image, args.look_image, args.prompt)
    
    # Handle synchronous response
    if op.get('done'):
        # Generate descriptive filename based on input images and prompt
        cache_key = generate_descriptive_filename(args.model_image, args.look_image, args.prompt)
        # Save the generated image
        saved_path = svc.save_asset_from_operation(op, cache_key=cache_key)
        print(json.dumps({
            'success': True,
            'status': 'completed',
            'saved_path': saved_path,
            'message': f'Image generated and saved to {saved_path}'
        }))
    else:
        # Async response (fallback)
        print(json.dumps(op))


if __name__ == '__main__':
    main()
