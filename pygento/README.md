# Python Console Command for Video Generation

This directory contains a Python implementation of the `agento:video` console command using Google's Generative AI SDK.

## Installation

Install the required dependencies:

```bash
pip install -r requirements.txt
```

Or install manually:

```bash
pip install google-generativeai requests
```

## Usage

### Basic Usage (Single Image)

```bash
python agento_video.py -ip "catalog/product/image.jpg" -p "Product showcase"
```

### Multiple Images (Same Prompt)

```bash
python agento_video.py \
  -ip "catalog/product/image1.jpg" "catalog/product/image2.jpg" "catalog/product/image3.jpg" \
  -p "Product showcase"
```

### With Polling (Wait for Completion)

```bash
python agento_video.py -ip "catalog/product/image.jpg" -p "Product showcase" --poll
```

### Multiple Images with Polling

```bash
python agento_video.py \
  -ip "catalog/product/image1.jpg" "catalog/product/image2.jpg" \
  -p "Beautiful product animation" \
  --poll
```

### Full Example

```bash
python agento_video.py \
  -ip "catalog/product/image.jpg" \
  -p "Beautiful product animation" \
  -ar "16:9" \
  -sv \
  --poll \
  --api-key "YOUR_API_KEY"
```

### Using Environment Variable for API Key

```bash
export GEMINI_API_KEY="your_api_key_here"
python agento_video.py -ip "catalog/product/image.jpg" -p "Product showcase" --poll
```

## Options

- `-ip, --image-path`: Path(s) to source image(s) (required, can specify multiple paths)
- `-p, --prompt`: Video generation prompt (required, applied to all images)
- `-ar, --aspect-ratio`: Aspect ratio (default: "16:9")
- `-sv, --silent-video`: Generate silent video (helps avoid audio-related safety filters)
- `--poll`: Wait for video generation to complete (synchronous mode)
- `--api-key`: Google Gemini API key (or set GEMINI_API_KEY environment variable)
- `--base-path`: Base path for Magento installation (defaults to current directory)

**Note**: When multiple image paths are provided, the same prompt is applied to all images. Videos are saved to `pub/media/video/` directory, matching the PHP implementation.

## Output Format

The command outputs JSON with the following structure:

### Success (Single Image - Completed)
```json
{
  "success": true,
  "status": "completed",
  "videoUrl": "/media/video/veo_abc123.mp4",
  "videoPath": "/path/to/pub/media/video/veo_abc123.mp4",
  "embedUrl": "<video>...</video>",
  "cached": false
}
```

### Success (Single Image - Processing)
```json
{
  "success": true,
  "status": "processing",
  "operationName": "operations/test-operation-123",
  "message": "Video generation started. Use --poll option to wait for completion."
}
```

### Success (Multiple Images)
```json
{
  "success": true,
  "total": 3,
  "succeeded": 2,
  "failed": 1,
  "results": [
    {
      "imagePath": "catalog/product/image1.jpg",
      "success": true,
      "status": "completed",
      "videoUrl": "/media/video/veo_abc123.mp4",
      "videoPath": "/path/to/pub/media/video/veo_abc123.mp4"
    },
    {
      "imagePath": "catalog/product/image2.jpg",
      "success": true,
      "status": "completed",
      "videoUrl": "/media/video/veo_def456.mp4",
      "videoPath": "/path/to/pub/media/video/veo_def456.mp4"
    }
  ],
  "errors": [
    {
      "imagePath": "catalog/product/image3.jpg",
      "success": false,
      "error": "Source image not found"
    }
  ]
}
```

### Error (Single Image)
```json
{
  "success": false,
  "error": "Error message here"
}
```

## Features

- **Multiple Image Support**: Process multiple images with the same prompt in a single command
- **Stable Model**: Uses `veo-3.1-generate-001` model for `predictLongRunning` endpoint (more stable than preview version)
- **302 Redirect Handling**: Properly handles Google API redirects when downloading videos. Python's `requests` library automatically follows redirects (unlike PHP's cURL which requires `CURLOPT_FOLLOWLOCATION`). The API key is included in both headers and URL parameters to ensure it survives redirects.
- **Safety Filter Detection**: Detects and reports safety filter blocks (RAI Media Filtered) during polling to avoid getting stuck in loops
- **Robust MIME Detection**: Uses Python's built-in `mimetypes` library for accurate MIME type detection
- **Caching**: Checks for cached videos before making API calls
- **Error Handling**: Comprehensive error handling with clear JSON error messages. When processing multiple images, partial failures are reported without stopping the entire batch.
- **Async Support**: Supports both async and synchronous (polling) modes
- **Google SDK**: Uses Google's Generative AI SDK for API interactions
- **Directory Matching**: Saves videos to `pub/media/video/` directory, matching the PHP implementation exactly

## Differences from PHP Implementation

1. **Redirect Handling**: Python's `requests` library automatically follows redirects, so we don't need to explicitly enable `CURLOPT_FOLLOWLOCATION`
2. **SDK Usage**: Uses Google's Generative AI SDK, but falls back to direct HTTP calls for Veo 3.1 since the SDK may not fully support it yet
3. **File Paths**: Uses Python's `pathlib` for better cross-platform path handling

## Troubleshooting

### 302 Redirect Errors

Python's `requests` library automatically follows redirects (unlike PHP's cURL), so 302 redirects are handled seamlessly. However, if you encounter issues:

- Ensure the API key is included in request headers (`x-goog-api-key`)
- The API key is also appended to the download URI to ensure it survives redirects
- The `requests` library handles redirects automatically with `allow_redirects=True` (default)
- Check that `response.history` shows redirects were followed (logged to stderr)

**Note**: Unlike the PHP implementation which uses native cURL with `CURLOPT_FOLLOWLOCATION`, Python's `requests` library handles redirects automatically. This is why we don't need to explicitly enable redirect following - it's the default behavior.

### Safety Filter Blocks

If you receive a "Safety Filter Block" error:
- The video generation was blocked by Google's Responsible AI filters
- Common reasons: copyrighted content, brand names, celebrities, or restricted image content
- Solutions:
  1. Simplify your prompt (remove brand names, celebrities, or copyrighted content)
  2. Use `--silent-video` flag or add "silent video" to your prompt if audio is the issue
  3. Check that your image doesn't contain restricted content
  4. You are not charged for blocked attempts

### Empty Video Files

If videos are saved but are 0 bytes:
- Check that the API key is properly included in download requests (both header and URL)
- Verify network connectivity to Google's API endpoints
- Check that the video directory has write permissions
- Ensure the redirect was followed successfully (check stderr output)

### Model Availability

The script uses `veo-3.1-generate-001` model for the `predictLongRunning` endpoint, which is more stable than the preview version. If you encounter model not found errors:
- Ensure your API key has access to Veo 3.1 models
- Check Google's API status page for model availability
- Verify your API key permissions in Google Cloud Console

## Requirements

- Python 3.7+
- google-generativeai >= 0.3.0
- requests >= 2.31.0
