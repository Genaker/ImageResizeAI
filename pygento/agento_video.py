#!/usr/bin/env python3
"""
Genaker ImageAIBundle - Python Console Command
Generate video from image using Google Gemini Veo 3.1 API

This script provides a Python implementation of the agento:video console command.
It uses Google's Generative AI SDK and handles 302 redirects properly when downloading videos.
"""

import argparse
import json
import os
import sys
import hashlib
import time
import mimetypes
from pathlib import Path
from typing import Optional, Dict, Any
import requests
from google import generativeai as genai


class GeminiVideoGenerator:
    """Generate videos using Google Gemini Veo 3.1 API"""
    
    def __init__(self, api_key: str, base_path: str = None, base_url: str = None):
        """
        Initialize the video generator
        
        Args:
            api_key: Google Gemini API key
            base_path: Base path for Magento installation (defaults to current directory)
            base_url: Base URL for generating full video URLs (e.g., https://app.lc.test)
        """
        self.api_key = api_key
        self.base_path = Path(base_path) if base_path else Path.cwd()
        # Ensure video directory matches PHP implementation: pub/media/video
        self.video_dir = self.base_path / 'pub' / 'media' / 'video'
        self.video_dir.mkdir(parents=True, exist_ok=True)
        
        # Get base URL for generating full video URLs
        self.base_url = base_url or self._get_base_url()
        
        # Configure Google Generative AI
        genai.configure(api_key=api_key)
        # Use veo-3.1-generate-preview (matches PHP implementation)
        # Note: veo-3.1-generate-001 may not be available for all API keys
        self.model = genai.GenerativeModel('veo-3.1-generate-preview')
    
    def is_available(self) -> bool:
        """Check if the service is available"""
        return bool(self.api_key)
    
    def _get_base_url(self) -> str:
        """
        Get base URL for generating full video URLs
        
        Returns:
            Base URL (e.g., https://app.lc.test)
        """
        # Try environment variable first
        base_url = os.getenv('MAGENTO_BASE_URL') or os.getenv('BASE_URL')
        if base_url:
            # Remove trailing slash and store code if present
            base_url = base_url.rstrip('/')
            # Remove store code (e.g., /default/)
            if '/default/' in base_url:
                base_url = base_url.replace('/default/', '/')
            return base_url.rstrip('/')
        
        # Try to detect from system
        # Check if we can determine from HTTP_HOST
        host = os.getenv('HTTP_HOST') or os.getenv('SERVER_NAME')
        if host:
            # Use HTTPS if available, otherwise HTTP
            scheme = 'https' if os.getenv('HTTPS') == 'on' else 'http'
            return f"{scheme}://{host}"
        
        # Default fallback
        return 'https://app.lc.test'
    
    def resolve_image_path(self, image_path: str) -> Path:
        """
        Resolve image path (handle relative and absolute paths)
        
        Args:
            image_path: Image path (relative to pub/media/ or absolute)
            
        Returns:
            Resolved absolute path
        """
        image_path_obj = Path(image_path)
        
        # If absolute path, use as is
        if image_path_obj.is_absolute():
            return image_path_obj
        
        # If path starts with pub/media/, remove it
        if str(image_path).startswith('pub/media/'):
            image_path = str(image_path)[10:]
        
        # Resolve relative to pub/media/
        media_path = self.base_path / 'pub' / 'media' / image_path.lstrip('/')
        return media_path
    
    def generate_cache_key(self, image_path: str, prompt: str, aspect_ratio: str) -> str:
        """
        Generate cache key from parameters
        
        Args:
            image_path: Path to image
            prompt: Video generation prompt
            aspect_ratio: Aspect ratio
            
        Returns:
            Cache key string
        """
        # Read image file and create hash
        image_path_obj = Path(image_path)
        if not image_path_obj.exists():
            raise FileNotFoundError(f"Image not found: {image_path}")
        
        with open(image_path_obj, 'rb') as f:
            image_hash = hashlib.md5(f.read()).hexdigest()
        
        # Create cache key from all parameters
        cache_data = f"{image_hash}:{prompt}:{aspect_ratio}"
        return hashlib.md5(cache_data.encode()).hexdigest()
    
    def get_cached_video(self, cache_key: str) -> Optional[Dict[str, Any]]:
        """
        Check if cached video exists
        
        Args:
            cache_key: Cache key
            
        Returns:
            Cached video info if exists, None otherwise
        """
        filename = f'veo_{cache_key}.mp4'
        video_path = self.video_dir / filename
        
        if video_path.exists():
            # Generate full video URL with domain (matches PHP implementation)
            relative_path = f"media/video/{filename}"
            # Ensure base_url doesn't have trailing slash
            base_url_clean = self.base_url.rstrip('/')
            video_url = f"{base_url_clean}/{relative_path}"
            
            return {
                'fromCache': True,
                'videoUrl': video_url,
                'videoPath': str(video_path),
                'status': 'completed'
            }
        
        return None
    
    def download_video_with_redirects(self, uri: str) -> bytes:
        """
        Download video from URI with proper 302 redirect handling
        
        The requests library automatically follows redirects (allow_redirects=True by default),
        which handles Google's 302 redirects to temporary GCS signed URLs. Unlike PHP's cURL
        which requires CURLOPT_FOLLOWLOCATION, Python's requests handles this automatically.
        
        We ensure the API key is included in both headers and URL parameters to survive redirects.
        
        Args:
            uri: Video download URI
            
        Returns:
            Video content as bytes
            
        Raises:
            requests.RequestException: If download fails
        """
        headers = {
            'x-goog-api-key': self.api_key
        }
        
        # Append API key to URI if it's a Google API URI and doesn't have it
        # This ensures the key survives redirects (some redirects may not preserve headers)
        if 'generativelanguage.googleapis.com' in uri and 'key=' not in uri:
            separator = '&' if '?' in uri else '?'
            uri = f"{uri}{separator}key={self.api_key}"
        
        # Use requests with redirect following (default behavior)
        # requests.get() automatically follows redirects with allow_redirects=True (default)
        # This handles Google's 302 redirects to temporary GCS signed URLs
        response = requests.get(
            uri,
            headers=headers,
            allow_redirects=True,  # Explicitly enable (default, but clear intent)
            timeout=300,  # 5 minutes for large video files
            stream=True  # Stream download for large files
        )
        
        # Check for redirects (requests handles automatically, but log for debugging)
        if response.history:
            # Log redirect chain for debugging
            redirect_count = len(response.history)
            final_url = response.url
            print(f"Followed {redirect_count} redirect(s), final URL: {final_url}", file=sys.stderr)
        
        # Raise exception for non-2xx status codes
        response.raise_for_status()
        
        # Read content
        video_content = response.content
        
        if not video_content:
            raise RuntimeError("Video download returned empty content")
        
        return video_content
    
    def save_video(self, video_uri: str, cache_key: str) -> str:
        """
        Save video to local filesystem
        
        Args:
            video_uri: URI to download video from
            cache_key: Cache key for filename
            
        Returns:
            Path to saved video file
        """
        filename = f'veo_{cache_key}.mp4'
        video_path = self.video_dir / filename
        
        # Download video with redirect handling
        video_content = self.download_video_with_redirects(video_uri)
        
        # Save to file
        with open(video_path, 'wb') as f:
            f.write(video_content)
        
        return str(video_path)
    
    def generate_video_from_image(
        self,
        image_path: str,
        prompt: str,
        aspect_ratio: str = '16:9',
        silent_video: bool = False
    ) -> Dict[str, Any]:
        """
        Generate video from image using Gemini Veo 3.1 API
        
        Args:
            image_path: Path to source image
            prompt: Video generation prompt
            aspect_ratio: Aspect ratio (e.g., "16:9", "9:16", "1:1")
            silent_video: If True, appends "silent video" to prompt
            
        Returns:
            Operation details or cached video details
        """
        if not self.is_available():
            raise RuntimeError(
                'Gemini video service is not available. Please configure the Gemini API key.'
            )
        
        # Resolve image path
        source_path = self.resolve_image_path(image_path)
        if not source_path.exists():
            raise FileNotFoundError(f"Source image not found: {source_path}")
        
        # Append "silent video" to prompt if requested
        final_prompt = prompt
        if silent_video:
            final_prompt = f"{prompt.strip()} silent video"
        
        # Generate cache key
        cache_key = self.generate_cache_key(str(source_path), final_prompt, aspect_ratio)
        
        # Check cache
        cached_video = self.get_cached_video(cache_key)
        if cached_video:
            return cached_video
        
        # Read image file
        with open(source_path, 'rb') as f:
            image_data = f.read()
        
        # Determine MIME type using Python's mimetypes library (more robust)
        mime_type, _ = mimetypes.guess_type(str(source_path))
        mime_type = mime_type or 'image/jpeg'  # Default to JPEG if detection fails
        
        # Prepare generation config
        generation_config = {}
        if aspect_ratio:
            generation_config['aspectRatio'] = aspect_ratio
        
        try:
            # Generate video using Google SDK
            # Note: The SDK handles the async operation internally
            # We'll use the direct API call approach similar to PHP implementation
            
            # For now, we'll use a direct HTTP approach since the SDK may not fully support Veo 3.1
            # This matches the PHP implementation's direct HTTP approach
            import base64
            
            base_url = 'https://generativelanguage.googleapis.com/v1beta'
            # Use veo-3.1-generate-preview (matches PHP implementation)
            # This model works with Google AI Studio API keys
            model_name = 'veo-3.1-generate-preview'
            endpoint = f"{base_url}/models/{model_name}:predictLongRunning"
            
            # Prepare payload
            payload = {
                'instances': [
                    {
                        'prompt': final_prompt,
                        'image': {
                            'bytesBase64Encoded': base64.b64encode(image_data).decode('utf-8'),
                            'mimeType': mime_type
                        }
                    }
                ],
                'parameters': generation_config
            }
            
            # Make API request
            headers = {
                'x-goog-api-key': self.api_key,
                'Content-Type': 'application/json'
            }
            
            response = requests.post(endpoint, json=payload, headers=headers, timeout=60)
            response.raise_for_status()
            
            data = response.json()
            
            if 'name' not in data:
                raise RuntimeError('Invalid API response: operation name not found')
            
            operation_name = data['name']
            
            return {
                'operationName': operation_name,
                'cacheKey': cache_key,
                'done': data.get('done', False),
                'status': 'completed' if data.get('done', False) else 'running'
            }
            
        except requests.RequestException as e:
            raise RuntimeError(f'Gemini API request failed: {str(e)}')
        except Exception as e:
            raise RuntimeError(f'Video generation failed: {str(e)}')
    
    def poll_video_operation(
        self,
        operation_name: str,
        max_wait_seconds: int = 300,
        poll_interval_seconds: int = 10,
        cache_key: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Poll operation status and get video when ready
        
        Args:
            operation_name: Operation name/ID
            max_wait_seconds: Maximum time to wait in seconds
            poll_interval_seconds: Interval between polls in seconds
            cache_key: Cache key for saving video
            
        Returns:
            Video details with URL and path
        """
        if not self.is_available():
            raise RuntimeError('Gemini video service is not available.')
        
        base_url = 'https://generativelanguage.googleapis.com/v1beta'
        poll_url = f"{base_url}/{operation_name}"
        
        start_time = time.time()
        
        headers = {
            'x-goog-api-key': self.api_key
        }
        
        while True:
            # Check timeout
            elapsed = time.time() - start_time
            if elapsed > max_wait_seconds:
                raise RuntimeError(f'Video generation timeout after {max_wait_seconds} seconds')
            
            # Poll operation status
            try:
                response = requests.get(poll_url, headers=headers, timeout=poll_interval_seconds + 5)
                response.raise_for_status()
                
                data = response.json()
                
                # Check if operation is done
                if data.get('done', False):
                    # Extract video URI from response
                    video_uri = None
                    
                    # Check various response structures
                    if 'response' in data:
                        response_data = data['response']
                        if 'generateVideoResponse' in response_data:
                            gen_res = response_data['generateVideoResponse']
                            
                            # Catch Safety Filter Blocks (RAI Media Filtered)
                            if 'raiMediaFilteredReasons' in gen_res:
                                reasons = gen_res['raiMediaFilteredReasons']
                                reasons_str = ", ".join(reasons) if isinstance(reasons, list) else str(reasons)
                                filtered_count = gen_res.get('raiMediaFilteredCount', len(reasons) if isinstance(reasons, list) else 1)
                                raise RuntimeError(
                                    f"Video generation was blocked by safety filters. "
                                    f"Reason(s): {reasons_str}. "
                                    f"Filtered count: {filtered_count}. "
                                    f"Suggestions: 1) Simplify your prompt (remove brand names, celebrities, or copyrighted content), "
                                    f"2) If audio is the issue, retry with --silent-video flag or add 'silent video' to your prompt, "
                                    f"3) Check that your image doesn't contain restricted content. "
                                    f"You have not been charged for this attempt."
                                )
                            
                            # Success Path - Extract video URI
                            if 'generatedSamples' in gen_res and gen_res['generatedSamples']:
                                sample = gen_res['generatedSamples'][0]
                                if 'video' in sample and 'uri' in sample['video']:
                                    video_uri = sample['video']['uri']
                    
                    if not video_uri:
                        raise RuntimeError('No video URI found in completed operation response')
                    
                    # Save video
                    if cache_key:
                        video_path = self.save_video(video_uri, cache_key)
                    else:
                        # Use operation name as cache key
                        video_path = self.save_video(video_uri, operation_name.split('/')[-1])
                    
                    # Generate full video URL with domain (matches PHP implementation)
                    video_filename = Path(video_path).name
                    relative_path = f"media/video/{video_filename}"
                    # Ensure base_url doesn't have trailing slash
                    base_url_clean = self.base_url.rstrip('/')
                    video_url = f"{base_url_clean}/{relative_path}"
                    
                    # Generate embed URL
                    embed_url = f'<video controls width="100%" height="auto"><source src="{video_url}" type="video/mp4">Your browser does not support the video tag.</video>'
                    
                    return {
                        'videoUrl': video_url,
                        'videoPath': video_path,
                        'embedUrl': embed_url,
                        'status': 'completed'
                    }
                
                # Not done yet, wait and poll again
                time.sleep(poll_interval_seconds)
                
            except requests.RequestException as e:
                raise RuntimeError(f'Video operation polling failed: {str(e)}')


def main():
    """Main entry point for the console command"""
    parser = argparse.ArgumentParser(
        description='Generate video from image using Gemini Veo 3.1 API',
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    
    parser.add_argument(
        '-ip', '--image-path',
        required=True,
        nargs='+',
        help='Path(s) to source image(s) (relative to pub/media/ or absolute path). Can specify multiple paths.'
    )
    
    parser.add_argument(
        '-p', '--prompt',
        required=True,
        help='Video generation prompt'
    )
    
    parser.add_argument(
        '-ar', '--aspect-ratio',
        default='16:9',
        help='Aspect ratio (e.g., "16:9", "9:16", "1:1"). Default: 16:9'
    )
    
    parser.add_argument(
        '-sv', '--silent-video',
        action='store_true',
        help='Generate silent video (helps avoid audio-related safety filters)'
    )
    
    parser.add_argument(
        '--poll',
        action='store_true',
        help='Wait for video generation to complete (synchronous mode)'
    )
    
    parser.add_argument(
        '--api-key',
        help='Google Gemini API key (or set GEMINI_API_KEY environment variable)'
    )
    
    parser.add_argument(
        '--base-path',
        help='Base path for Magento installation (defaults to current directory)'
    )
    
    parser.add_argument(
        '--base-url',
        help='Base URL for generating full video URLs (e.g., https://app.lc.test). Can also set MAGENTO_BASE_URL environment variable.'
    )
    
    args = parser.parse_args()
    
    # Get API key from argument or environment
    api_key = args.api_key or os.getenv('GEMINI_API_KEY')
    if not api_key:
        result = {
            'success': False,
            'error': 'API key is required. Use --api-key or set GEMINI_API_KEY environment variable.'
        }
        print(json.dumps(result, indent=2))
        sys.exit(1)
    
    try:
        # Initialize generator
        # Get base_url from args (may be None if not provided)
        base_url = getattr(args, 'base_url', None)
        generator = GeminiVideoGenerator(api_key, args.base_path, base_url)
        
        # Check if service is available
        if not generator.is_available():
            result = {
                'success': False,
                'error': 'Video service is not available. Please configure Gemini API key.'
            }
            print(json.dumps(result, indent=2))
            sys.exit(1)
        
        # Process multiple image paths
        image_paths = args.image_path
        results = []
        errors = []
        
        for image_path in image_paths:
            try:
                # Generate video for this image
                operation = generator.generate_video_from_image(
                    image_path,
                    args.prompt,
                    args.aspect_ratio,
                    args.silent_video
                )
                
                # Check if video was returned from cache
                if operation.get('fromCache'):
                    results.append({
                        'imagePath': image_path,
                        'success': True,
                        'status': 'completed',
                        'videoUrl': operation['videoUrl'],
                        'videoPath': operation['videoPath'],
                        'cached': True
                    })
                    continue
                
                # If poll option is set, wait for completion
                if args.poll:
                    result_data = generator.poll_video_operation(
                        operation['operationName'],
                        300,  # 5 minutes max wait
                        10,   # 10 seconds poll interval
                        operation.get('cacheKey')
                    )
                    
                    results.append({
                        'imagePath': image_path,
                        'success': True,
                        'status': 'completed',
                        'videoUrl': result_data['videoUrl'],
                        'videoPath': result_data['videoPath'],
                        'embedUrl': result_data.get('embedUrl')
                    })
                else:
                    # Return operation ID for async polling
                    results.append({
                        'imagePath': image_path,
                        'success': True,
                        'status': 'processing',
                        'operationName': operation['operationName'],
                        'message': 'Video generation started. Use --poll option to wait for completion.'
                    })
                    
            except FileNotFoundError as e:
                errors.append({
                    'imagePath': image_path,
                    'success': False,
                    'error': f'Source image not found: {str(e)}'
                })
            except Exception as e:
                errors.append({
                    'imagePath': image_path,
                    'success': False,
                    'error': str(e)
                })
        
        # Prepare final result
        if len(image_paths) == 1:
            # Single image - return single result format for backward compatibility
            if results:
                result = results[0]
            else:
                result = errors[0]
        else:
            # Multiple images - return array format
            result = {
                'success': len(errors) == 0,
                'total': len(image_paths),
                'succeeded': len(results),
                'failed': len(errors),
                'results': results,
                'errors': errors
            }
        
        print(json.dumps(result, indent=2))
        
        # Exit with error code if any failures
        if errors:
            sys.exit(1)
        sys.exit(0)
        
    except Exception as e:
        result = {
            'success': False,
            'error': str(e)
        }
        print(json.dumps(result, indent=2))
        sys.exit(1)


if __name__ == '__main__':
    main()
