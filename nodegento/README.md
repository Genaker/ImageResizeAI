# Node.js CLI for Video and Image Generation

This is a Node.js equivalent of the Python `agento_video.py` and `agento_image.py` scripts. It provides the same functionality for generating videos and images from images using Google Gemini APIs.

## Installation

```bash
cd /home/hammer/lccoins-m2/ImageResizeAI/nodegento
npm install
```

## Image Generation

The Node.js version now supports image generation using Gemini 2.5 Flash Image API, just like the Python version.

### CLI Usage

```bash
# Single image generation
node agento_image.js --model-image "image.jpg" --prompt "Create a professional fashion photo"

# Two image generation
node agento_image.js --model-image "model.jpg" --look-image "clothing.jpg" --prompt "Combine these images"

# With API key
node agento_image.js --api-key "YOUR_KEY" --model-image "image.jpg" --prompt "Generate image"
```

### Server Mode

Start the HTTP server for image generation:

```bash
# Start server
node agento_image_server.js

# Or with environment variables
GEMINI_API_KEY="your-key" node agento_image_server.js
```

The server provides the same REST API as the Python version:

```bash
# Generate image via API
curl -X POST http://localhost:3000/generate \
  -H "Content-Type: application/json" \
  -d '{
    "model_image": "https://example.com/image1.jpg",
    "look_image": "https://example.com/image2.jpg",
    "prompt": "Create a professional fashion photo combining these images"
  }'
```

### Image Generation Features

- ✅ Single or two-image generation
- ✅ URL and local file path support
- ✅ Descriptive filename generation based on input image names
- ✅ REST API with JSON payloads
- ✅ Image serving and management endpoints
- ✅ Same output format as Python version

### Image Server Endpoints

- `GET /health` - Health check
- `POST /generate` - Generate images
- `GET /images/:filename` - Serve images
- `GET /list-images` - List generated images
- `DELETE /images/:filename` - Delete images

---

# Video Generation

## Installation

```bash
cd /var/www/html/vendor/genaker/imageaibundle/pygento/nodegento
npm install
```

## Usage

The Node.js CLI has the same interface as the Python version:

```bash
cd /var/www/html/vendor/genaker/imageaibundle/pygento/nodegento
node agento_video.js --api-key='YOUR_API_KEY' -ip "image.jpg" -p "Create a dynamic product showcase video" --sync
```

Or make it executable and run directly:

```bash
cd /var/www/html/vendor/genaker/imageaibundle/pygento/nodegento
chmod +x agento_video.js
./agento_video.js --api-key='YOUR_API_KEY' -ip "image.jpg" -p "Create a dynamic product showcase video" --sync
```

## Features

- ✅ Same CLI interface as Python version
- ✅ Environment variable support (.env file)
- ✅ Image caching
- ✅ Video caching
- ✅ Support for second image
- ✅ Verbose debug output (`-v` flag)
- ✅ Synchronous and asynchronous modes
- ✅ Support for local files and URLs
- ✅ Thematic ASCII animation during polling

## Command Line Arguments

All arguments match the Python version:

- `-ip, --image-path <paths...>` - Path(s) to image file(s) or URL(s) (required, can specify multiple)
- `-p, --prompt <prompt>` - Video generation prompt (required)
- `-k, --api-key <key>` - Google Gemini API key (overrides GEMINI_API_KEY)
- `-ar, --aspect-ratio <ratio>` - Aspect ratio (16:9, 9:16, 1:1), default: 16:9
- `-si, --second-image <path>` - Optional second image path or URL
- `--no-auto-reference` - Disable automatic image reference enhancement
- `-sv, --silent-video` - Add "silent video" to prompt
- `--sync` - Wait for video generation to complete (synchronous mode)
- `--base-path <path>` - Base path for Magento installation
- `--save-path <path>` - Path where videos should be saved
- `--base-url <url>` - Base URL for generating full video URLs
- `--env-file <path>` - Path to .env file
- `-v, --verbose` - Enable verbose debug output

## Environment Variables

Same as Python version:

- `GEMINI_API_KEY` - Google Gemini API key
- `MAGENTO_BASE_PATH` - Base path for Magento installation
- `VIDEO_SAVE_PATH` - Path where videos should be saved
- `MAGENTO_BASE_URL` - Base URL for generating full video URLs
- `GOOGLE_API_DOMAIN` - Override API domain (for mock server testing)

## Example

```bash
# With external URL
node agento_video.js \
  --api-key='YOUR_API_KEY' \
  -ip "https://example.com/image.jpg" \
  -p "Create a dynamic product showcase video" \
  --sync \
  -v

# With second image
node agento_video.js \
  --api-key='YOUR_API_KEY' \
  -ip "background.jpg" \
  -si "foreground.png" \
  -p "Combine image1 as background with image2 as foreground" \
  --sync
```

## Output Format

Same JSON output format as Python version:

```json
{
  "success": true,
  "status": "completed",
  "videoUrl": "/pub/media/video/veo_xxx.mp4",
  "videoPath": "/path/to/video.mp4",
  "embedUrl": "<video>...</video>"
}
```

## Dependencies

- `axios` - HTTP client for API requests
- `commander` - CLI argument parsing
- `chalk` - Terminal colors (optional, for better output)
- `dotenv` - Environment variable loading from .env file

## Notes

- The Node.js version uses async/await for all operations
- Cache key generation for URLs requires async operation (handled automatically)
- Compatible with Node.js 12+ (no optional chaining used for compatibility)
