# Python Console Command for Video Generation

NOTE: The Node.js equivalent documentation and examples have been moved to the `nodegento` folder.
See: ../nodegento/README.md

This module provides a universal Python implementation for video generation from images using Google's Gemini Veo 3.1 API. While originally designed for Magento through the `agento:video` console command integration, this module is **universal and can work with any e-commerce system** including Shopify, Oro Commerce, Shopware, BigCommerce, and others. The Python-based architecture allows seamless integration with any platform that supports Python, making it a versatile solution for AI-powered video generation across different e-commerce ecosystems. Whether you're building integrations for Magento, Shopify, or any other platform, this module provides a consistent, powerful interface for generating product videos, promotional content, and dynamic media assets.

## Available Implementations

This module provides multiple implementations for different runtime environments:

- **Python Implementation** (this directory) - Full-featured Python CLI with all features
- **Node.js Implementation** - See [`nodegento/NODEJS_README.md`](nodegento/NODEJS_README.md) for Node.js version with identical functionality

Both implementations share the same CLI interface and features, allowing you to choose the runtime that best fits your environment.

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

### Using External URL

```bash
python agento_video.py -ip "https://example.com/image.jpg" -p "Product showcase"
```

### Multiple Images (Same Prompt)

```bash
python agento_video.py \
  -ip "catalog/product/image1.jpg" "catalog/product/image2.jpg" "catalog/product/image3.jpg" \
  -p "Product showcase"
```

### With Polling (Wait for Completion)

```bash
python agento_video.py -ip "catalog/product/image.jpg" -p "Product showcase" --sync
```

### Multiple Images with Polling

```bash
python agento_video.py \
  -ip "catalog/product/image1.jpg" "catalog/product/image2.jpg" \
  -p "Beautiful product animation" \
  --sync
```

### Full Example

```bash
python agento_video.py \
  -ip "catalog/product/image.jpg" \
  -p "Beautiful product animation" \
  -ar "16:9" \
  -sv \
  --sync \
  --api-key "YOUR_API_KEY"
```

### Using Environment Variables

```bash
export GEMINI_API_KEY="your_api_key_here"
export MAGENTO_BASE_PATH="/var/www/html"
export MAGENTO_BASE_URL="https://example.com"
export VIDEO_SAVE_PATH="pub/media/video"
python agento_video.py -ip "catalog/product/image.jpg" -p "Product showcase" --sync
```

### Using .env File

Create a `.env` file in the script directory or current directory:

```env
GEMINI_API_KEY=your_api_key_here
MAGENTO_BASE_PATH=/var/www/html
VIDEO_SAVE_PATH=pub/media/video
MAGENTO_BASE_URL=https://example.com
```

The script will automatically load these values. Command-line arguments override environment variables.

### Using Second Image

```bash
python agento_video.py \
  -ip "catalog/product/background.jpg" \
  -si "catalog/product/foreground.jpg" \
  -p "Use image1 as background and image2 as foreground, create a smooth transition" \
  --sync
```

### Image Reference Examples

When using `--second-image`, you can reference images in your prompt:

```bash
# Using generic references
python agento_video.py -ip img1.jpg -si img2.jpg \
  -p "Use image1 as background and image2 as foreground"

# Using positional references
python agento_video.py -ip img1.jpg -si img2.jpg \
  -p "Combine the first image with the second image"

# Using filenames
python agento_video.py -ip background.jpg -si foreground.jpg \
  -p "Use background.jpg for the scene and foreground.jpg for the subject"

# Disable auto-reference enhancement
python agento_video.py -ip img1.jpg -si img2.jpg \
  --no-auto-reference \
  -p "Your custom prompt with explicit image references"
```

## Image Generation Prompt Examples

The following examples demonstrate various prompt techniques for image generation using Google's Gemini 2.5 Flash Image model. These examples show how to generate images from text prompts, modify existing images, and combine multiple images.

### Basic Image Generation from Text

```python
from google import genai
from google.genai import types
from PIL import Image
from io import BytesIO

client = genai.Client(api_key=os.environ['GOOGLE_API_KEY'])

prompt = """
A photorealistic close-up portrait of an elderly Japanese
ceramicist with deep, sun-etched wrinkles and a warm, knowing smile.
He is carefully inspecting a freshly glazed tea bowl. The setting is
his rustic, sun-drenched workshop with pottery wheels and shelves of
clay pots in the background. The scene is illuminated by soft, golden
hour light streaming through a window, highlighting the fine texture
of the clay and the fabric of his apron. Captured with an 85mm portrait
lens, resulting in a soft, blurred background (bokeh). The overall mood
is serene and masterful.
"""

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=prompt,
)

# Save the generated image
image_parts = [
    part.inline_data.data
    for part in response.candidates[0].content.parts
    if part.inline_data
]

if image_parts:
    image = Image.open(BytesIO(image_parts[0]))
    image.save("ceramicist-portrait.jpg")
```

### Image Editing with Before/After Display

```python
import matplotlib.pyplot as plt
from PIL import Image
from io import BytesIO

def display_before_after(before_image, response):
    # Collect generated images
    after_images = []
    for part in response.candidates[0].content.parts:
        if part.inline_data is not None:
            after_images.append(Image.open(BytesIO(part.inline_data.data)))

    # Plot side by side
    fig, axes = plt.subplots(1, len(after_images) + 1, figsize=(6 * (len(after_images) + 1), 6))

    # Show before image in first subplot
    axes[0].imshow(before_image)
    axes[0].set_title("Before")
    axes[0].axis("off")

    # Show all generated images
    for i, img in enumerate(after_images, start=1):
        axes[i].imshow(img)
        axes[i].set_title(f"After {i}")
        axes[i].axis("off")

    plt.show()

# Example: Change woman's outfit and environment
prompt = """
Create an image of the woman in this picture with the following changes:
- She's wearing a white one-piece swimsuit.
- She is holding a smoothie in her hands.
- She is at the beach with the ocean behind her.
- she is sitting on a towel on the sand.
- she is wearing a white Panama hat.
"""

before_image = Image.open("woman-stock-image-1.jpg")

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt, before_image],
)

display_before_after(before_image, response)
```

### Simple Image Modification

```python
prompt = (
    "Make the color of this woman's coat be light green and change her hat, make her wear a top hat instead."
)

before_image = Image.open("woman-stock-image-1.jpg")

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt, before_image],
)

display_before_after(before_image, response)
```

### Cartoon Style Generation

```python
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=(
        "A grid with 12 cartoon-style stickers of a penguin holding an ice ",
        "cream cone and performing different activities. ",
        "The penguin has oversized round eyes and stubby wings. ",
        "The design uses thick outlines, cel-shading, and bright playful ",
        "colors. The background must be white."
    )
)

image_parts = [
    part.inline_data.data
    for part in response.candidates[0].content.parts
    if part.inline_data
]

if image_parts:
    image = Image.open(BytesIO(image_parts[0]))
    image.save("penguin-stickers.jpg")
```

### Product Photography Enhancement

```python
prompt = (
    "Given the input image, create a high-resolution studio photograph "
    "of the object. "
    "The image should have professional studio lighting with soft shadows "
    "and sharp focus to highlight the product's details. "
    "Use a clean white background and ensure the object is centered "
    "and isolated, ready for use in e-commerce or product catalogs."
)

before_image = Image.open("watch.jpg")

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt, before_image]
)

display_before_after(before_image, response)
```

### Logo Design and Application

```python
# Generate a logo
response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=(
        "Create a sleek, minimalist logo for a tech startup called 'AstroMind'. "
        "The text should be in a modern geometric sans-serif font. "
        "Include a simple, abstract icon of a planet with rings. "
        "The color scheme is white and blue on a black background."
    ),
)

# Save logo
image_parts = [
    part.inline_data.data
    for part in response.candidates[0].content.parts
    if part.inline_data
]

if image_parts:
    logo_image = Image.open(BytesIO(image_parts[0]))
    logo_image.save("astromind-logo.png")

# Apply logo to product
prompt = (
    "Take the first image of the woman with brown hair,"
    "brown eyes, and a smile. Add the logo from "
    "the second image onto her black t-shirt. Position the logo "
    "on the chest on the left side of the t-shirt. Ensure the woman's "
    "face and features remain completely unchanged. The logo "
    "should look like it's naturally printed on the fabric, "
    "following the folds of the shirt."
)

before_image = Image.open("woman-stock-image-2.jpg")

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[prompt, before_image, logo_image],
)

display_before_after(before_image, response)
```

### Fashion Product Composition

```python
dress_image = Image.open('dress.jpg')
model_image = Image.open('model.jpg')

prompt = """
Create a professional e-commerce fashion photo. Take the blue
dress with white spots from the first image and let the woman from
the second image wear it. Generate a realistic, full-body shot of
the woman wearing the dress, with the lighting and shadows adjusted
to match the outdoor environment.
"""

response = client.models.generate_content(
    model="gemini-2.5-flash-image",
    contents=[model_image, dress_image, prompt],
)

display_before_after(model_image, response)
```

## Options

- `-ip, --image-path`: Path(s) to source image(s) or URL(s) (required, can specify multiple paths/URLs). Supports:
  - Relative paths: `catalog/product/image.jpg`
  - Absolute paths: `/var/www/html/pub/media/catalog/product/image.jpg`
  - HTTP/HTTPS URLs: `https://example.com/image.jpg`
- `-si, --second-image`: Optional second image path or URL to include in the payload for video generation
- `-p, --prompt`: Video generation prompt (required, applied to all images). When using `--second-image`, you can reference images as:
  - `"image1"` or `"first image"` for the first image
  - `"image2"` or `"second image"` for the second image
  - Image filenames (e.g., `"background.jpg"`, `"foreground.png"`)
  - Examples: `"Use image1 as background"`, `"Combine image2 with image1"`, `"Transition from first image to second image"`
- `--no-auto-reference`: Disable automatic image reference enhancement in prompt (use if you want full control over prompt)
- `-ar, --aspect-ratio`: Aspect ratio (default: "16:9")
- `-sv, --silent-video`: Generate silent video (helps avoid audio-related safety filters)
- `--sync`: Wait for video generation to complete (synchronous mode)
- `--api-key`: Google Gemini API key (or set GEMINI_API_KEY environment variable)
- `--base-path`: Base path for Magento installation (defaults to current directory or MAGENTO_BASE_PATH env)
- `--save-path`: Path where videos should be saved, relative to base_path or absolute (defaults to pub/media/video or VIDEO_SAVE_PATH env)
- `--base-url`: Base URL for generating full video URLs (defaults to MAGENTO_BASE_URL env or auto-detected)
- `--env-file`: Path to .env file (defaults to .env in script directory or current directory)

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
  "message": "Video generation started. Use --sync option to wait for completion."
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
- **Second Image Support**: Include a second image in the payload for advanced video generation scenarios
- **Image Reference Enhancement**: Automatically enhances prompts with image context when using second image
- **External URL Support**: Accept images from external URLs (http/https) in addition to local file paths
- **Environment Variable Configuration**: Configure API keys, paths, and URLs via environment variables or .env file
- **Configurable Save Path**: Customize where videos are saved (relative or absolute paths)
- **Configurable API Domain**: Use mock server for testing or override API endpoint
- **Stable Model**: Uses `veo-3.1-generate-preview` model for `predictLongRunning` endpoint
- **302 Redirect Handling**: Properly handles Google API redirects when downloading videos. Python's `requests` library automatically follows redirects (unlike PHP's cURL which requires `CURLOPT_FOLLOWLOCATION`). The API key is included in both headers and URL parameters to ensure it survives redirects.
- **Safety Filter Detection**: Detects and reports safety filter blocks (RAI Media Filtered) during polling to avoid getting stuck in loops
- **Robust MIME Detection**: Uses Python's built-in `mimetypes` library for accurate MIME type detection
- **Caching**: Checks for cached videos before making API calls (includes second image in cache key when used)
- **Error Handling**: Comprehensive error handling with clear JSON error messages. When processing multiple images, partial failures are reported without stopping the entire batch.
- **Async Support**: Supports both async and synchronous (polling) modes
- **Service Architecture**: Separated service layer for better code organization and testability
- **Directory Matching**: Saves videos to `pub/media/video/` directory by default, matching the PHP implementation exactly

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

## Mock API Server for Testing

To avoid API costs and rate limits during development and testing, a mock Veo API server is included.

### Starting the Mock Server

```bash
# Start on default port 8080
python3 mock_veo_server.py

# Start on custom port
python3 mock_veo_server.py --port 9000

# Start on custom host and port
python3 mock_veo_server.py --host 127.0.0.1 --port 8080
```

### Using Mock Server

Set the `GOOGLE_API_DOMAIN` environment variable to point to the mock server:

```bash
# Start mock server in one terminal
python3 mock_veo_server.py --port 8080

# In another terminal, set environment variable and run video generation
export GOOGLE_API_DOMAIN=http://127.0.0.1:8080/v1beta
export GEMINI_API_KEY=test-key  # Any value works with mock server
python3 agento_video.py -ip image.jpg -p "test prompt" --sync
```

### Mock Server Features

- **Mimics Real API**: Returns responses matching Google Veo API structure
- **Operation Simulation**: Creates operation IDs and simulates async processing (completes after ~5 seconds)
- **Progress Tracking**: Returns progress percentage during polling
- **Video Download**: Returns minimal valid MP4 files for download testing
- **No API Costs**: Completely free - no API quota consumed
- **No Rate Limits**: Unlimited requests for testing
- **Fast Testing**: Completes in ~5 seconds vs real API wait times

### Mock Server Endpoints

The mock server handles the following endpoints:

- `POST /models/veo-3.1-generate-preview:predictLongRunning` - Submit video generation request
- `GET /operations/{operation_id}` - Poll operation status
- `GET /videos/{video_id}` - Download mock video file

### Environment Variables for Mock Server

- `GOOGLE_API_DOMAIN`: Set to mock server URL (e.g., `http://127.0.0.1:8080/v1beta`)
- `PORT`: Mock server port (default: 8080)
- `HOST`: Mock server host (default: 127.0.0.1)

### Example: Testing with Mock Server

```bash
# Terminal 1: Start mock server
cd /var/www/html/vendor/genaker/imageaibundle/pygento
python3 mock_veo_server.py --port 8080

# Terminal 2: Run video generation with mock server
export GOOGLE_API_DOMAIN=http://127.0.0.1:8080/v1beta
export GEMINI_API_KEY=test-key
python3 agento_video.py \
  -ip catalog/product/image.jpg \
  -p "test video generation" \
  --sync
```

The mock server will:
1. Accept the video generation request
2. Return an operation ID
3. Complete the operation after ~5 seconds
4. Provide a downloadable video URL
5. Serve a minimal MP4 file when requested

## Environment Variables

The script supports configuration via environment variables (loaded from `.env` file or system environment):

| Variable | Description | Default |
|----------|-------------|---------|
| `GEMINI_API_KEY` | Google Gemini API key | Required |
| `MAGENTO_BASE_PATH` | Base path for Magento installation | Current directory |
| `VIDEO_SAVE_PATH` | Path where videos should be saved | `pub/media/video` |
| `MAGENTO_BASE_URL` | Base URL for generating full video URLs | Auto-detected or required |
| `GOOGLE_API_DOMAIN` | Override API base URL (for mock server) | Production API |

**Priority Order**: Command-line arguments > Environment variables > Defaults

## New Python Features

### Second Image Support

You can now include a second image in the video generation payload:

```bash
python3 agento_video.py \
  -ip background.jpg \
  -si foreground.jpg \
  -p "Use image1 as background and image2 as foreground"
```

**Benefits**:
- Create videos with multiple image inputs
- Reference images by name or position in prompts
- Automatic prompt enhancement with image context
- Cache keys include both images for proper caching

### Image Reference in Prompts

When using `--second-image`, the script automatically enhances your prompt with image context:

- **Automatic Enhancement**: Adds image filenames and references to help AI understand which image is which
- **Smart Detection**: Skips enhancement if your prompt already references images
- **Flexible References**: Use `image1`/`image2`, `first image`/`second image`, or filenames

### Configurable Paths and URLs

All paths and URLs can be configured:

```bash
# Via command-line arguments
python3 agento_video.py \
  --base-path /custom/path \
  --save-path custom/video/path \
  --base-url https://custom-domain.com

# Via environment variables
export MAGENTO_BASE_PATH=/custom/path
export VIDEO_SAVE_PATH=custom/video/path
export MAGENTO_BASE_URL=https://custom-domain.com
python3 agento_video.py -ip image.jpg -p "prompt"
```

### Service Architecture

The code is organized into separate classes:

- **`GeminiVideoService`**: Core API interaction logic
- **`GeminiVideoGenerator`**: High-level generator with file/path management

This separation provides:
- Better code organization
- Easier testing
- Reusable service layer
- Clear separation of concerns

### Optional API Key

API key can be provided via parameter or environment variable:

```python
# Explicit parameter
service = GeminiVideoService(api_key="your-key")

# From environment
service = GeminiVideoService()  # Reads from GEMINI_API_KEY env var
```

## Requirements

- Python 3.7+
- google-generativeai >= 0.3.0
- requests >= 2.31.0
- python-dotenv (optional, for .env file support)

## Node.js Alternative

A Node.js implementation is also available in the [`nodegento/`](nodegento/) directory. For complete Node.js documentation, installation instructions, and usage examples, see [`nodegento/NODEJS_README.md`](nodegento/NODEJS_README.md).

The Node.js version provides:
- Same CLI interface and arguments
- Identical feature set
- Compatible output format
- Support for all Python features (caching, second image, verbose mode, etc.)

Choose the implementation that best fits your environment - both provide the same functionality.

---

# Image Generation Server

This module also provides a Flask-based HTTP server for image generation using Google's Gemini 2.5 Flash Image API.

## Server Features

- **REST API**: Generate images via HTTP POST requests with JSON payloads
- **Single/Two Image Support**: Generate from one or two input images
- **URL Support**: Accept both local file paths and HTTP/HTTPS URLs
- **Descriptive Filenames**: Auto-generated filenames based on input image names
- **Image Serving**: Built-in endpoints to serve and manage generated images
- **Health Checks**: API health monitoring endpoint

## Server Installation

The server uses the same requirements as the CLI tool. Flask is included in `requirements.txt`.

## Running the Server

### Using Docker Compose (Recommended)

```bash
# Set your API key
export GEMINI_API_KEY="your-api-key-here"

# Start the server
docker-compose up server
```

The server will be available at `http://localhost:5000`

### Manual Docker Run

```bash
docker build -t agento-image-server .
docker run -p 5000:5000 -e GEMINI_API_KEY="your-key" -v $(pwd):/opt/app agento-image-server python3 agento_image_server.py
```

### Direct Python Run

```bash
export GEMINI_API_KEY="your-api-key-here"
python3 agento_image_server.py
```

## API Endpoints

### POST /generate
Generate an image from input images and prompt.

**Request Body:**
```json
{
  "model_image": "https://example.com/image1.jpg",
  "look_image": "https://example.com/image2.jpg",  // optional
  "prompt": "Create a professional fashion photo combining these images",
  "api_key": "optional-api-key-override"
}
```

**Response:**
```json
{
  "success": true,
  "status": "completed",
  "saved_path": "/opt/app/pub/media/lookbook/filename_timestamp.jpg",
  "filename": "filename_timestamp.jpg",
  "message": "Image generated and saved",
  "input": {
    "model_image": "...",
    "look_image": "...",
    "prompt": "..."
  }
}
```

### GET /health
Check server health and API availability.

**Response:**
```json
{
  "status": "healthy",
  "service": "agento-image-server",
  "api_available": true
}
```

### GET /images/{filename}
Serve a generated image file.

### GET /list-images
List all generated images with metadata.

**Response:**
```json
{
  "success": true,
  "images": [
    {
      "filename": "image1.jpg",
      "path": "/path/to/image1.jpg",
      "size": 12345,
      "modified": 1640995200.0
    }
  ],
  "count": 1
}
```

### DELETE /images/{filename}
Delete a generated image.

## Example Usage

### Generate with Two Images
```bash
curl -X POST http://localhost:5000/generate \
  -H "Content-Type: application/json" \
  -d '{
    "model_image": "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg",
    "look_image": "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg",
    "prompt": "Create a professional fashion photo combining these images"
  }'
```

### Generate with Single Image
```bash
curl -X POST http://localhost:5000/generate \
  -H "Content-Type: application/json" \
  -d '{
    "model_image": "https://example.com/image.jpg",
    "prompt": "Create a professional fashion photo from this image"
  }'
```

### Check Health
```bash
curl http://localhost:5000/health
```

### List Images
```bash
curl http://localhost:5000/list-images
```

### Serve Image
```bash
curl http://localhost:5000/images/generated_image_1234567890.jpg -o image.jpg
```

## Testing the Server

A test script is provided to verify server functionality:

```bash
# Start the server in one terminal
docker-compose up server

# Run tests in another terminal
python3 test_server.py
```

Or run both together:

```bash
# This will start server and run tests (requires API key)
GEMINI_API_KEY="your-key" docker-compose up test-server
```

### Integration Testing

Run the full integration test suite:

```bash
# Run integration tests (starts server, runs tests, cleans up)
GEMINI_API_KEY="your-key" ./test_server_integration.sh
```
