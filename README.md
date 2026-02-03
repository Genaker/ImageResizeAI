# Genaker ImageAIBundle - Magento 2 Module

Magento 2 module for intelligent image resizing with caching and AI-powered image modification support. This module provides on-the-fly image resizing with support for multiple formats, quality control, and optional AI-powered image enhancement using Google Gemini API.

## Features

- **On-the-Fly Image Resizing**: Resize images dynamically via URL parameters
- **Multiple Format Support**: WebP, JPEG, PNG, GIF support with automatic format conversion
- **Intelligent Caching**: Automatic caching of resized images for optimal performance
- **Signature Validation**: Optional signature-based URL validation for security
- **AI-Powered Enhancement**: Integration with Google Gemini API for AI image modification (optional)
- **AI Video Generation**: Generate videos from images using Google Veo 3.1 API (optional)
- **Python Implementation**: Standalone Python script for video generation with enhanced performance and reliability
- **Magento CLI Commands**: Console commands for video generation (`agento:video` PHP, `agento-p:video` Python proxy)
- **Admin Panel**: Admin interface for generating resize URLs with signatures
- **Configurable Limits**: System configuration for width, height, quality limits
- **Performance Optimized**: Efficient caching and file management

## Installation

### Via Composer (Recommended)

```bash
composer require genaker/imageaibundle
bin/magento module:enable Genaker_ImageAIBundle
bin/magento setup:upgrade
bin/magento cache:flush
```

**Note:** During installation, a test image (`wt09-white_main_1.jpg`) will be automatically copied to `/pub/media/catalog/product/w/t/` for testing purposes. You can use this image to test all resize functionality.

### Manual Installation

1. Copy the module to `app/code/Genaker/ImageAIBundle`
2. Run the following commands:
```bash
bin/magento module:enable Genaker_ImageAIBundle
bin/magento setup:upgrade
bin/magento cache:flush
```

**Note:** The test image will be automatically installed to `/pub/media/catalog/product/w/t/wt09-white_main_1.jpg` during `setup:upgrade`.

## Configuration

Navigate to **Stores > Configuration > Genaker > Image AI Resize** to configure:

### General Settings

- **Enable Signature Validation**: Enable signature validation for image resize URLs (recommended for production)
- **Signature Salt**: Secret salt for generating image resize URL signatures (required if signature validation is enabled)
- **Enable Regular URL Format**: Allow query string format URLs (e.g., `?w=100&h=100`)
- **Gemini API Key**: Google Gemini API key for AI image modification (optional)
- **Lock Retry Count**: Number of retries when acquiring lock for image processing (default: 3)
- **Use File Manager for Cache**: Use Magento file manager for cache tracking

### Default Limits

- **Width**: 20-5000 pixels
- **Height**: 20-5000 pixels
- **Quality**: 0-100
- **Allowed Formats**: webp, jpg, jpeg, png, gif
- **Allowed Aspect Ratios**: inset, outbound

## Usage

### Frontend URL Format

#### Basic Resize (Short Format - Recommended)
```
/resize/ip/{image_path}?w={width}&h={height}&f={format}&q={quality}
```

**Example:**
```
/resize/ip/catalog/product/image.jpg?w=300&h=300&f=webp&q=85
```

#### Legacy Format (Still Supported)
```
/resize/index/imagePath/{image_path}?w={width}&h={height}&f={format}&q={quality}
```

**Example:**
```
/resize/index/imagePath/catalog/product/image.jpg?w=300&h=300&f=webp&q=85
```

#### With Signature (if enabled)
```
/resize/ip/{image_path}?w={width}&h={height}&f={format}&sig={signature}
```

### URL Format Options: Base64 vs Regular

The module supports two URL formats for image resizing, both optimized for nginx caching:

#### Regular URL Format (Query String)

**Format:**
```
/media/resize/ip/{image_path}?w={width}&h={height}&f={format}&q={quality}
```

**Example:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=400&h=400&f=jpeg
```

**Characteristics:**
- Human-readable and easy to construct
- Parameters visible in URL
- Works with default nginx configuration
- Cache files stored as: `/pub/media/resize/{base64-encoded-params}.{extension}`

#### Base64 URL Format (Recommended for Production)

**Format:**
```
/media/resize/{base64-encoded-string}.{extension}
```

**Example:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJmg9NDAwJnc9NDAw.jpeg
```

**How it works:**
- Base64 string encodes: `ip/{image_path}?{sorted_params}`
- Parameters are automatically sorted alphabetically for consistent caching
- Extension matches the output format (jpeg, webp, png, etc.)

**Benefits:**
- **Nginx-friendly**: Cache files stored directly in `/pub/media/resize/` directory
- **No PHP required**: Nginx can serve cached files directly without hitting PHP
- **Consistent caching**: Same parameters always generate the same cache file
- **Cleaner URLs**: Shorter, more SEO-friendly URLs
- **Performance**: Faster cache lookups

**Decoding Example:**
The base64 string `aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJmg9NDAwJnc9NDAw` decodes to:
```
ip/catalog/product/w/t/wt09-white_main_1.jpg?f=jpeg&h=400&w=400
```

**Both formats use the same cache:**
- Regular URL: `/media/resize/ip/catalog/product/image.jpg?w=400&h=400&f=jpeg`
- Base64 URL: `/media/resize/{base64}.jpeg`
- Both generate the same cache file, ensuring optimal cache utilization

### Nginx Configuration

The module is designed to work with **default nginx configuration** without requiring custom rules. Here's how it works:

#### Default Nginx Behavior

With standard Magento nginx configuration, requests to `/media/resize/` are handled as follows:

1. **Cache Hit**: If the cache file exists at `/pub/media/resize/{base64}.{ext}`, nginx serves it directly (no PHP)
2. **Cache Miss**: If the file doesn't exist, nginx falls back to `/get.php` which routes to PHP
3. **PHP Processing**: PHP generates the resized image and saves it to the cache path
4. **Subsequent Requests**: Future requests are served directly by nginx from cache

#### Cache Path Structure

Cache files are stored using base64-encoded filenames:
```
/pub/media/resize/{base64-encoded-params}.{extension}
```

**Example cache file:**
```
/pub/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJmg9NDAwJnc9NDAw.jpeg
```

**Why base64 encoding?**
- Ensures cache files are stored as single files (not directories)
- Prevents nginx from treating cache paths as directories (which would cause 301/403 errors)
- Works seamlessly with nginx's `try_files $uri $uri/ /get.php` directive
- Parameters are sorted alphabetically for consistent cache generation

#### Nginx Configuration (No Changes Required)

The module works with Magento's default nginx configuration. The standard `try_files` directive handles everything:

```nginx
location ~* \.(jpg|jpeg|png|gif|webp)$ {
    try_files $uri $uri/ /get.php$is_args$args;
}
```

**How it works:**
1. First, nginx checks if `$uri` exists (cache file)
2. If not found, checks if `$uri/` is a directory (shouldn't match due to base64 format)
3. Finally, falls back to `/get.php` which routes to PHP via the Media plugin

#### Cache File Permissions

Ensure nginx has write access to the cache directory:
```bash
chmod -R 775 /var/www/html/pub/media/resize/
chown -R www-data:www-data /var/www/html/pub/media/resize/
```

#### Performance Benefits

- **Direct Serving**: Cached images served directly by nginx (no PHP overhead)
- **Consistent Caching**: Both URL formats generate identical cache files
- **Automatic Cleanup**: Cache files can be managed via Magento cache management
- **CDN Compatible**: Cache files can be easily cached by CDN services

### Manual Browser Testing

You can test the image resize functionality directly in your browser by constructing URLs with the appropriate parameters.

#### URL Structure

The module supports two URL formats:

**1. Regular URL Format (Query String):**
```
https://your-domain.com/media/resize/ip/{image_path}?w={width}&h={height}&f={format}
```

**2. Base64 URL Format (Recommended for Production):**
```
https://your-domain.com/media/resize/{base64-encoded-string}.{extension}
```

**Legacy format (still supported for backward compatibility):**
```
https://your-domain.com/resize/index/imagePath/{image_path}?{parameters}
```

**Note:** 
- The regular format (`/media/resize/ip/...`) is human-readable and easy to construct
- The base64 format (`/media/resize/{base64}.{ext}`) is optimized for nginx caching and production use
- Both formats generate the same cache file and return identical results
- The legacy format (`/resize/index/imagePath/...`) is still supported for backward compatibility

#### Constructing Test URLs

**1. Basic Image Resize (Width & Height)**

Using the test image included with the module:

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=jpeg
```

**Base64 URL format (same result, better caching):**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJmg9MzAwJnc9MzAw.jpeg
```

**2. Resize with Quality Control**

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=500&h=500&f=webp&q=90
```

**Base64 URL format:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj13ZWJwJmg9NTAwJnE9OTAmdz01MDA.webp
```

**3. Width Only (Height auto-scales)**

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=400&f=jpeg
```

**Base64 URL format:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJnc9NDAw.jpeg
```

**4. Height Only (Width auto-scales)**

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?h=400&f=jpeg
```

**Base64 URL format:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1qcGVnJmg9NDAw.jpeg
```

**5. Format Conversion (JPEG to WebP)**

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=webp&q=85
```

**Base64 URL format:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj13ZWJwJmg9MzAwJnE9ODUmdz0zMDA.webp
```

**6. Format Conversion (JPEG to PNG)**

**Regular URL format:**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=png&q=90
```

**Base64 URL format:**
```
https://your-domain.com/media/resize/aXAvY2F0YWxvZy9wcm9kdWN0L3cvdC93dDA5LXdoaXRlX21haW5fMS5qcGc_Zj1wbmcmaD0zMDAmaz05MCZ3PTMwMA.png
```

**Note:** Both URL formats generate the same cache file and return identical results. Use base64 format for production (better nginx caching) and regular format for development/testing (easier to read and construct).

**7. With Signature (if signature validation is enabled)**
```
https://your-domain.com/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=webp&sig={generated_signature}
```

**8. Testing Gemini AI Integration (Admin Only)**

To test AI-powered image modification, you need to:
- Be logged in as admin
- Have `GEMINI_API_KEY` environment variable set or configured in admin panel
- Use the `prompt` parameter

```
https://your-domain.com/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=jpeg&prompt=Make%20this%20image%20brighter%20and%20more%20vibrant
```

**Note:** The module includes a test image (`wt09-white_main_1.jpg`) that is automatically copied to `/pub/media/catalog/product/w/t/` during installation. You can use this image for testing all resize functionality.

**Note:** The `prompt` parameter is only available for admin users or when signature validation is enabled (signature provides security).

#### URL Parameter Encoding

When constructing URLs manually, ensure proper URL encoding:

- **Spaces** should be encoded as `%20` or `+`
- **Special characters** in image paths should be URL encoded
- **Example with encoded path:**
```
https://your-domain.com/resize/ip/catalog/product/image%20with%20spaces.jpg?w=300&h=300&f=jpeg
```

#### Testing Checklist

1. **Basic Resize**: Test with width and height parameters
2. **Format Conversion**: Test converting between JPEG, PNG, WebP, GIF
3. **Quality Control**: Test different quality values (1-100)
4. **Caching**: Request the same URL twice - second request should be faster (cache hit)
5. **Error Handling**: Test with invalid parameters or non-existent images
6. **Gemini AI**: Test AI modification with appropriate prompts (admin only)

#### Example Test Scenarios

**Scenario 1: Resize Product Image**
```
Original: /media/catalog/product/example.jpg (1200x800)
Resized: https://your-domain.com/resize/ip/catalog/product/example.jpg?w=300&h=200&f=webp&q=85
Result: 300x200 WebP image
```

**Scenario 2: Create Thumbnail**
```
Original: /media/catalog/product/large-image.jpg
Thumbnail: https://your-domain.com/resize/ip/catalog/product/large-image.jpg?w=150&h=150&f=jpeg&q=80
Result: 150x150 JPEG thumbnail
```

**Scenario 3: Optimize for Web**
```
Original: /media/catalog/product/heavy-image.png
Optimized: https://your-domain.com/resize/ip/catalog/product/heavy-image.png?w=800&f=webp&q=90
Result: WebP format with max width 800px, auto height
```

#### Troubleshooting Browser Testing

- **404 Error**: Check that the image path is correct and the image exists in `/pub/media/`
- **403 Error**: Check signature if signature validation is enabled
- **500 Error**: Check Magento logs (`var/log/system.log`) for detailed error messages
- **Image Not Loading**: Verify format parameter (`f`) is provided and valid
- **Slow Response**: First request creates cache, subsequent requests should be faster

### URL Parameters

| Parameter | Description | Required | Example |
|-----------|-------------|----------|---------|
| `w` | Width in pixels | No | `300` |
| `h` | Height in pixels | No | `300` |
| `q` | Quality (0-100) | No | `85` |
| `f` | Format (webp, jpg, jpeg, png, gif) | **Yes** | `webp` |
| `a` | Aspect ratio (inset, outbound) | No | `inset` |
| `sig` | Signature (if validation enabled) | Yes* | `abc123...` |
| `prompt` | AI modification prompt (admin only) | No | `enhance colors` |
| `video` | Enable video generation (Veo 3.1) | No | `true` |
| `aspectRatio` | Video aspect ratio (16:9, 9:16, 1:1) | No | `16:9` |
| `poll` | Wait for video completion (synchronous) | No | `true` |
| `operation` | Operation ID for polling video status | No | `operations/...` |
| `silentVideo` | Generate silent video (no audio) to avoid audio-related safety filters | No | `true` |
| `return` | Return format: `video` to return video content directly instead of JSON | No | `video` |

\* Required only if signature validation is enabled

## Video Generation

### Video Model Selection (Environment Variables)

The module supports two video generation models that can be selected via environment variables:

#### Default: Veo 3.1 (Google AI Studio)
- **Model**: `veo-3.1-generate-preview`
- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta`
- **Use Case**: Production video generation with high quality
- **Default**: Used when no environment variable is set

#### Alternative: Imagen (Vertex AI) - For Testing
- **Model**: `imagegeneration@006` (or latest version)
- **Endpoint**: Vertex AI endpoint (configurable)
- **Use Case**: Faster generation, higher quotas, good for testing
- **Activation**: Set `VIDEO_MODEL=imagen` environment variable

**Environment Variables:**

```bash
# Use Imagen model for testing (faster, higher quotas)
export VIDEO_MODEL=imagen

# Or use alternative env var name
export GEMINI_VIDEO_MODEL=imagen

# Required for Imagen: Set Vertex AI endpoint
# Format: https://{region}-aiplatform.googleapis.com/v1/projects/{project}/locations/{region}/publishers/google/models/imagegeneration@006
export VERTEX_AI_ENDPOINT=https://us-central1-aiplatform.googleapis.com/v1/projects/your-project/locations/us-central1/publishers/google/models/imagegeneration@006

# Optional: Vertex AI access token (if using Bearer auth instead of API key)
export VERTEX_AI_ACCESS_TOKEN=your_access_token
```

**Model Comparison:**

| Feature | Veo 3.1 | Imagen |
|---------|---------|--------|
| Quality | High | Good |
| Speed | Slower | Faster |
| Quotas | Standard | Higher |
| Use Case | Production | Testing |
| Endpoint | Google AI Studio | Vertex AI |

**Note:** When `VIDEO_MODEL=imagen` is set but `VERTEX_AI_ENDPOINT` is not configured, the module will fall back to Veo 3.1 and log a warning.

### Video Generation Examples

Video generation uses the same URL structure as image resizing, but with the `video=true` parameter. The endpoint returns JSON responses instead of image files.

**Base URL Format**:
```
https://your-domain.com/media/resize/ip/{image_path}?video=true&prompt={prompt}&{other_parameters}
```

### Basic Video Generation (Async Mode)

**Start video generation** (returns operation ID for polling):
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&aspectRatio=16:9
```

**Response (JSON)**:
```json
{
  "success": true,
  "status": "processing",
  "operationName": "operations/abc123...",
  "message": "Video generation started. Poll with ?operation=operations/abc123...&poll=true"
}
```

**Poll for completion**:
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?operation=operations/abc123...&poll=true
```

### Synchronous Video Generation

**Wait for video completion** (may take 30-60 seconds):
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&poll=true
```

**Response (JSON)**:
```json
{
  "success": true,
  "status": "completed",
  "videoUrl": "https://your-domain.com/media/video/veo_abc123.mp4",
  "embedUrl": "<video controls width=\"100%\" height=\"auto\"><source src=\"...\" type=\"video/mp4\">Your browser does not support the video tag.</video>",
  "videoPath": "/var/www/html/pub/media/video/veo_abc123.mp4",
  "cached": true
}
```

### Video Generation with Different Aspect Ratios

**16:9 (Landscape)**:
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=create%20a%20summer%20scene&aspectRatio=16:9
```

**9:16 (Portrait)**:
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=create%20a%20summer%20scene&aspectRatio=9:16
```

**1:1 (Square)**:
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=create%20a%20summer%20scene&aspectRatio=1:1
```

### Video Generation Examples

**Example 1: Transform Product Image to Summer Scene**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=Transform%20this%20product%20into%20a%20summer%20beach%20scene%20with%20sunset&aspectRatio=16:9&poll=true
```

**Example 2: Create Animated Product Showcase**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=Create%20an%20animated%20showcase%20of%20this%20product%20rotating%20slowly&aspectRatio=16:9
```

**Example 3: Generate Silent Video (No Audio)**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&silentVideo=true&poll=true
```

**Example 4: Return Video Content Directly (Not JSON)**
```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&poll=true&return=video
```

This returns the actual video file content (MP4) that browsers can play directly, instead of JSON response.

**Example 5: Video Caching**
Videos are automatically cached based on image path, prompt, and aspect ratio. Same parameters = same cached video:
```
# First request - generates and caches video
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&poll=true

# Second request with same parameters - returns cached video immediately
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?video=true&prompt=make%20it%20summer&poll=true
```

### Video Generation Notes

- **Async Mode**: Use without `poll=true` to get operation ID immediately (recommended for production)
- **Sync Mode**: Use `poll=true` to wait for completion (may take 30-90 seconds, timeout: 5 minutes)
- **Aspect Ratios**: Supported values are `16:9`, `9:16`, and `1:1` (default: `16:9`)
- **Caching**: Videos are **always cached** locally to `pub/media/video/` directory. Cache key is based on image path, prompt, and aspect ratio
- **Silent Video**: Use `silentVideo=true` to generate videos without audio (helps avoid audio-related safety filters)
- **Return Format**: Use `return=video` to return video content directly instead of JSON (useful for direct video playback)
- **Security**: Same admin/signature requirements as image modification
- **Video Storage**: All videos are saved to `pub/media/video/` with filename format: `veo_{md5_hash}.mp4`
- **Implementation**: Uses direct HTTP calls to Gemini v1beta API (default implementation)
  - **Direct API Calls**: The module uses native PHP cURL to make direct requests to `https://generativelanguage.googleapis.com/v1beta` endpoint
  - **No SDK Required**: Works without waiting for Gemini SDK updates - uses direct HTTP implementation by default
  - **Veo 3.1 Support**: Directly calls `veo-3.1-generate-preview:predictLongRunning` endpoint
  - **Redirect Handling**: Automatically follows 302 redirects from Google Files API to download videos
  - **Reliable**: Bypasses SDK limitations and works immediately with any valid API key
  - **API Documentation**: https://ai.google.dev/gemini-api/docs/models/veo

### Troubleshooting Video Generation

**Error: "Video generation service is not available"**
- Check that `GEMINI_API_KEY` environment variable is set or configured in admin panel
- Verify the API key is valid and has access to Veo 3.1 models
- Ensure the API key has proper permissions for Veo 3.1 API access

**Error: "Gemini API error (403)"**
- Your API key may not have access to Veo 3.1 models
- Check your Google Cloud Console API permissions
- Ensure Veo 3.1 API is enabled for your project

**Error: "Video generation timeout"**
- Video generation typically takes 30-90 seconds (can take up to 5 minutes)
- Default timeout is 300 seconds (5 minutes)
- Use async mode (`poll=false`) for production to avoid timeouts
- Check network connectivity to Google APIs
- Ensure PHP `max_execution_time` is greater than polling timeout (default 300s)

**Error: "Video generation was blocked by safety filters"**
- Google's safety filters detected potentially restricted content
- **Solutions**:
  1. Simplify your prompt (remove brand names, celebrities, copyrighted content)
  2. Use `silentVideo=true` parameter if audio is the issue
  3. Check that your image doesn't contain restricted content
  4. Try a different prompt or image
- You are not charged for blocked attempts

**Error: "Video generation error (Code: 13)"**
- This is an internal server error from Gemini API (usually temporary)
- The operation has failed and cannot be retried
- **Solution**: Start a new video generation request with the same parameters
- Wait a few minutes if it's a temporary server issue
- Check Gemini API status if problem persists

**Error: "Failed to download video from URI (Status: 302)"**
- This error has been fixed - the module now automatically follows redirects
- If you still see this error, check logs for details
- Videos are downloaded using native PHP cURL with redirect following enabled

**Error: "No video found in API response"**
- Check Magento logs for full API response structure
- May indicate API response format has changed
- Verify API key has proper Veo 3.1 access

**Note**: The module uses direct HTTP calls to Gemini API, so no SDK updates are required. Video generation works as long as your API key has Veo 3.1 access.

### Admin URL Generator

## AI Image Generation Prompt Examples

This section provides examples of effective prompts for AI-powered image generation and modification using Google Gemini. These prompts are language-agnostic and can be used with any implementation that supports Gemini's image generation capabilities.

### Text-to-Image Generation

**Photorealistic Portrait:**
```
A photorealistic close-up portrait of an elderly Japanese ceramicist with deep, sun-etched wrinkles and a warm, knowing smile. He is carefully inspecting a freshly glazed tea bowl. The setting is his rustic, sun-drenched workshop with pottery wheels and shelves of clay pots in the background. The scene is illuminated by soft, golden hour light streaming through a window, highlighting the fine texture of the clay and the fabric of his apron. Captured with an 85mm portrait lens, resulting in a soft, blurred background (bokeh). The overall mood is serene and masterful.
```

**Cartoon Style:**
```
A grid with 12 cartoon-style stickers of a penguin holding an ice cream cone and performing different activities. The penguin has oversized round eyes and stubby wings. The design uses thick outlines, cel-shading, and bright playful colors. The background must be white.
```

### Image Editing and Modification

**Outfit Change:**
```
Create an image of the woman in this picture with the following changes:
- She's wearing a white one-piece swimsuit.
- She is holding a smoothie in her hands.
- She is at the beach with the ocean behind her.
- she is sitting on a towel on the sand.
- she is wearing a white Panama hat.
```

**Simple Modification:**
```
Make the color of this woman's coat be light green and change her hat, make her wear a top hat instead.
```

### Product Photography Enhancement

**Studio Product Photo:**
```
Given the input image, create a high-resolution studio photograph of the object. The image should have professional studio lighting with soft shadows and sharp focus to highlight the product's details. Use a clean white background and ensure the object is centered and isolated, ready for use in e-commerce or product catalogs.
```

### Logo Design and Application

**Logo Creation:**
```
Create a sleek, minimalist logo for a tech startup called 'AstroMind'. The text should be in a modern geometric sans-serif font. Include a simple, abstract icon of a planet with rings. The color scheme is white and blue on a black background.
```

**Logo Application:**
```
Take the first image of the woman with brown hair, brown eyes, and a smile. Add the logo from the second image onto her black t-shirt. Position the logo on the chest on the left side of the t-shirt. Ensure the woman's face and features remain completely unchanged. The logo should look like it's naturally printed on the fabric, following the folds of the shirt.
```

### Fashion and Product Composition

**Fashion Lookbook:**
```
Create a professional e-commerce fashion photo. Take the blue dress with white spots from the first image and let the woman from the second image wear it. Generate a realistic, full-body shot of the woman wearing the dress, with the lighting and shadows adjusted to match the outdoor environment.
```

### Prompt Engineering Tips

- **Be Specific**: Include details about lighting, camera settings, mood, and composition
- **Reference Images**: When using multiple images, clearly specify which is which (first image, second image, etc.)
- **Style Consistency**: Maintain consistent artistic style across referenced images
- **Technical Details**: Specify lens type, lighting conditions, and post-processing effects
- **Output Requirements**: Define resolution, format, and intended use case

## Python Implementation for Video Generation

The module includes a Python-based alternative implementation for video generation that offers enhanced performance, better error handling, and improved reliability for production environments.

### Overview

The Python implementation (`pygento/agento_video.py`) provides a standalone script that can be used independently or via Magento CLI proxy command (`agento-p:video`). It uses Google's Generative AI SDK and Python's `requests` library for robust API interactions.

### Benefits of Python Implementation

1. **Better Performance**: Python's `requests` library handles large binary streams (video files) more efficiently than PHP cURL for long-running downloads
2. **Automatic Redirect Handling**: Python's `requests` library automatically follows HTTP redirects (302), eliminating the need for manual redirect configuration
3. **Decoupled Processing**: Can run as a standalone background worker without taxing PHP-FPM processes
4. **Better Error Handling**: More detailed error messages and safety filter detection
5. **Multiple Image Processing**: Process multiple images with the same prompt in a single command
6. **Robust MIME Detection**: Uses Python's built-in `mimetypes` library for accurate MIME type detection
7. **Scalability**: Can be easily wrapped in a Docker container or deployed as a separate microservice
8. **Cross-Platform**: Works on Linux, macOS, and Windows with Python 3.7+

### Installation

Install Python dependencies:

```bash
cd vendor/genaker/imageaibundle/pygento
pip install -r requirements.txt
```

Or install manually:

```bash
pip install google-generativeai requests
```

### Usage via Magento CLI (Recommended)

The easiest way to use the Python implementation is through the Magento CLI proxy command:

#### Basic Usage

```bash
php bin/magento agento-p:video --image-path "catalog/product/image.jpg" --prompt "Product showcase"
```

#### Multiple Images

```bash
php bin/magento agento-p:video \
  --image-path "catalog/product/image1.jpg" "catalog/product/image2.jpg" \
  --prompt "Beautiful product animation" \
  --poll
```

#### With All Options

```bash
php bin/magento agento-p:video \
  --image-path "catalog/product/image.jpg" \
  --prompt "Create a summer scene" \
  --aspect-ratio "16:9" \
  --silent-video \
  --poll \
  --api-key "YOUR_API_KEY"
```

**Note**: The `--base-url` parameter is automatically retrieved from Magento's store configuration, so you don't need to specify it manually.

### Usage as Standalone Python Script

You can also run the Python script directly:

```bash
python3 vendor/genaker/imageaibundle/pygento/agento_video.py \
  --base-path /var/www/html \
  --base-url https://your-domain.com \
  -ip "catalog/product/image.jpg" \
  -p "Product showcase" \
  --poll
```

### Command Options

| Option | Description | Required |
|--------|-------------|----------|
| `--image-path` or `-ip` | Path(s) to source image(s) (can specify multiple) | Yes |
| `--prompt` or `-p` | Video generation prompt | Yes |
| `--aspect-ratio` or `-ar` | Aspect ratio (16:9, 9:16, 1:1) | No (default: 16:9) |
| `--silent-video` or `-sv` | Generate silent video (avoids audio safety filters) | No |
| `--poll` | Wait for video completion (synchronous mode) | No |
| `--api-key` | Google Gemini API key (or set GEMINI_API_KEY env var) | No |
| `--base-path` | Magento base path (auto-detected when using Magento CLI) | No |
| `--base-url` | Base URL for video URLs (auto-detected from Magento config) | No |

### Output Format

The Python implementation always returns JSON (unlike the PHP command which supports multiple formats):

```json
{
  "success": true,
  "status": "completed",
  "videoUrl": "https://your-domain.com/media/video/veo_abc123.mp4",
  "videoPath": "/var/www/html/pub/media/video/veo_abc123.mp4",
  "embedUrl": "<video controls>...</video>",
  "cached": false
}
```

### Key Features

- **Multiple Image Support**: Process multiple images with the same prompt in a single command
- **Automatic Redirect Handling**: Python's `requests` library automatically follows 302 redirects from Google Files API
- **Safety Filter Detection**: Detects and reports safety filter blocks with actionable suggestions
- **Robust MIME Detection**: Uses Python's `mimetypes` library for accurate MIME type detection
- **Caching**: Checks for cached videos before making API calls (same cache as PHP implementation)
- **Error Handling**: Comprehensive error handling with clear JSON error messages
- **Full HTTPS URLs**: Generates complete HTTPS URLs with domain (not relative paths)

### When to Use Python Implementation

**Use Python implementation when:**
- Processing large batches of videos
- Running video generation as background jobs
- Need better performance for long-running operations
- Want to decouple video processing from PHP-FPM
- Deploying in containerized/microservice architectures

**Use PHP implementation when:**
- Simple, occasional video generation
- Tight integration with Magento's request lifecycle
- Prefer PHP-only solutions

### Integration with Magento

The Python implementation integrates seamlessly with Magento:

1. **Automatic Base URL**: Magento CLI command automatically retrieves base URL from store configuration
2. **Shared Cache**: Uses the same cache directory (`pub/media/video/`) as PHP implementation
3. **Consistent Output**: Returns the same JSON structure as PHP implementation
4. **Same API**: Uses the same Gemini Veo 3.1 API endpoints

### Example: Batch Processing

Process multiple product images in a single command:

```bash
php bin/magento agento-p:video \
  --image-path \
    "catalog/product/image1.jpg" \
    "catalog/product/image2.jpg" \
    "catalog/product/image3.jpg" \
  --prompt "Create an animated product showcase" \
  --aspect-ratio "16:9" \
  --poll
```

This will generate videos for all three images and return a summary with individual results.

### Troubleshooting Python Implementation

**Python Not Found:**
```bash
# Install Python 3.7+ if not available
sudo apt-get install python3 python3-pip  # Ubuntu/Debian
brew install python3  # macOS
```

**Module Not Found Errors:**
```bash
pip install -r vendor/genaker/imageaibundle/pygento/requirements.txt
```

**Permission Errors:**
```bash
chmod +x vendor/genaker/imageaibundle/pygento/agento_video.py
```

**API Key Issues:**
- Set environment variable: `export GEMINI_API_KEY=your_key`
- Or pass via `--api-key` parameter
- Or configure in Magento admin (used automatically)

For more details, see the [Python-specific README](pygento/README.md).

Navigate to **Genaker > Image Resize > Generate** to generate resize URLs with signature validation.

## API Usage

### Service Interface

```php
use Genaker\ImageAIBundle\Api\ImageResizeServiceInterface;

class YourClass
{
    private ImageResizeServiceInterface $imageResizeService;
    
    public function __construct(ImageResizeServiceInterface $imageResizeService)
    {
        $this->imageResizeService = $imageResizeService;
    }
    
    public function resizeImage()
    {
        $params = [
            'w' => 300,
            'h' => 300,
            'f' => 'webp',
            'q' => 85
        ];
        
        $result = $this->imageResizeService->resizeImage(
            'catalog/product/image.jpg',
            $params
        );
        
        // Access result properties
        $filePath = $result->getFilePath();
        $mimeType = $result->getMimeType();
        $fromCache = $result->isFromCache();
    }
}
```

## Cache Management

### Image Cache

Resized images are automatically cached in `/pub/media/cache/resize/` directory. The cache structure follows the image path structure for easy management.

**Clearing Image Cache:**
```bash
rm -rf pub/media/cache/resize/*
```

### Video Cache

Generated videos are automatically cached in `/pub/media/video/` directory. Videos are cached based on:
- Image path
- Prompt text
- Aspect ratio

Cache key format: `md5(imagePath|prompt|aspectRatio)`

**Video Cache Behavior:**
- **Always Enabled**: Videos are always saved locally (no configuration needed)
- **Automatic**: Same image + prompt + aspect ratio = same cached video
- **Immediate Return**: Cached videos are returned immediately without API calls

**Clearing Video Cache:**
```bash
rm -rf pub/media/video/*
```

**Or use Magento cache management:**
```bash
bin/magento cache:clean
```

**Note**: Video cache is separate from image cache and is always enabled for optimal performance.

## Security

### Signature Validation

When signature validation is enabled, all resize URLs must include a valid signature parameter. This prevents unauthorized image resizing and protects against abuse.

**Generating Signatures:**

The signature is calculated as:
```php
$signature = md5($imagePath . '|' . $sortedParams . '|' . $salt);
```

Where:
- `$imagePath` is the image path
- `$sortedParams` is URL-encoded query string of sorted parameters
- `$salt` is the configured signature salt

## Programmatic URL Generation

The module provides multiple ways to generate image resize URLs programmatically:

### 1. Using ResizeUrlGenerationService (Recommended)

The `ResizeUrlGenerationService` is the core service for generating resize URLs. It's registered in Magento's dependency injection container and can be injected into any class.

**In PHP Classes (Blocks, Controllers, etc.):**

```php
<?php
namespace YourVendor\YourModule\Block;

use Genaker\ImageAIBundle\Service\ResizeUrlGenerationService;
use Magento\Framework\View\Element\Template;

class YourBlock extends Template
{
    private ResizeUrlGenerationService $resizeUrlService;

    public function __construct(
        Template\Context $context,
        ResizeUrlGenerationService $resizeUrlService,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->resizeUrlService = $resizeUrlService;
    }

    public function getResizedImageUrl(): string
    {
        $imagePath = 'catalog/product/image.jpg';
        $params = ['w' => 400, 'h' => 400, 'f' => 'jpeg', 'q' => 85];
        
        // Generate base64 URL (default, optimized for nginx)
        return $this->resizeUrlService->generateUrl($imagePath, $params, true);
        
        // Or generate regular URL
        // return $this->resizeUrlService->generateUrl($imagePath, $params, false);
    }
}
```

**Service Methods:**
- `generateUrl($imagePath, $params = [], $useBase64 = true, $includeDomain = true)` - Main method, base64 by default
- `generateBase64Url($imagePath, $params = [], $includeDomain = true)` - Always generates base64 format
- `generateRegularUrl($imagePath, $params = [], $includeDomain = true)` - Always generates regular format

### 2. Using Helper Class (Global Access)

The `ImageResizeUrl` helper provides global access to URL generation functionality.

**In Templates (.phtml files):**

```php
<?php
/** @var \Genaker\ImageAIBundle\Helper\ImageResizeUrl $helper */
$helper = $this->helper(\Genaker\ImageAIBundle\Helper\ImageResizeUrl::class);

// Generate base64 URL (default)
$resizeUrl = $helper->getResizeUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);

// Generate regular URL
$regularUrl = $helper->getRegularUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);

// Generate base64 URL explicitly
$base64Url = $helper->getBase64Url('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);
?>

<img src="<?= $escaper->escapeUrl($resizeUrl) ?>" alt="Product Image" />
```

**In PHP Classes:**

```php
<?php
use Genaker\ImageAIBundle\Helper\ImageResizeUrl;

class YourClass
{
    private ImageResizeUrl $resizeUrlHelper;

    public function __construct(ImageResizeUrl $resizeUrlHelper)
    {
        $this->resizeUrlHelper = $resizeUrlHelper;
    }

    public function getImageUrl(): string
    {
        return $this->resizeUrlHelper->getResizeUrl('catalog/product/image.jpg', ['w' => 300]);
    }
}
```

### 3. Using ViewModel (Recommended for Templates)

ViewModels provide a clean way to access URL generation in templates without using helpers or blocks.

**Step 1: Add ViewModel to Block**

In your module's layout XML file (e.g., `view/frontend/layout/catalog_product_view.xml`):

```xml
<?xml version="1.0"?>
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceBlock name="product.info.media">
            <arguments>
                <argument name="resizeUrlViewModel" xsi:type="object">
                    Genaker\ImageAIBundle\ViewModel\ResizeUrl
                </argument>
            </arguments>
        </referenceBlock>
    </body>
</page>
```

**Step 2: Use in Template**

In your template file (e.g., `view/frontend/templates/product/view/gallery.phtml`):

```php
<?php
/** @var \Genaker\ImageAIBundle\ViewModel\ResizeUrl $resizeUrlViewModel */
$resizeUrlViewModel = $block->getData('resizeUrlViewModel');

// Generate base64 URL (default)
$resizeUrl = $resizeUrlViewModel->getResizeUrl('catalog/product/image.jpg', [
    'w' => 400,
    'h' => 400,
    'f' => 'jpeg',
    'q' => 85
]);

// Or use specific methods
$base64Url = $resizeUrlViewModel->getBase64Url('catalog/product/image.jpg', ['w' => 300]);
$regularUrl = $resizeUrlViewModel->getRegularUrl('catalog/product/image.jpg', ['w' => 300]);
?>

<img src="<?= $escaper->escapeUrl($resizeUrl) ?>" 
     srcset="<?= $escaper->escapeUrl($base64Url) ?> 1x,
             <?= $escaper->escapeUrl($resizeUrlViewModel->getResizeUrl('catalog/product/image.jpg', ['w' => 800])) ?> 2x"
     alt="Product Image" />
```

**Alternative: Inject ViewModel Directly in Block**

You can also inject the ViewModel directly in your Block class:

```php
<?php
namespace YourVendor\YourModule\Block;

use Genaker\ImageAIBundle\ViewModel\ResizeUrl;
use Magento\Framework\View\Element\Template;

class YourBlock extends Template
{
    private ResizeUrl $resizeUrlViewModel;

    public function __construct(
        Template\Context $context,
        ResizeUrl $resizeUrlViewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->resizeUrlViewModel = $resizeUrlViewModel;
    }

    public function getResizeUrlViewModel(): ResizeUrl
    {
        return $this->resizeUrlViewModel;
    }
}
```

Then in your template:

```php
<?php
/** @var \YourVendor\YourModule\Block\YourBlock $block */
$resizeUrl = $block->getResizeUrlViewModel()->getResizeUrl('catalog/product/image.jpg', ['w' => 400]);
?>
```

### URL Generation Examples

**Basic Resize:**
```php
$url = $service->generateUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);
// Returns: https://your-domain.com/media/resize/{base64}.jpeg
```

**With Quality:**
```php
$url = $service->generateUrl('catalog/product/image.jpg', ['w' => 500, 'h' => 500, 'q' => 90]);
```

**Format Conversion:**
```php
$url = $service->generateUrl('catalog/product/image.jpg', ['w' => 300, 'h' => 300, 'f' => 'webp']);
// Returns: https://your-domain.com/media/resize/{base64}.webp
```

**Regular URL Format:**
```php
$url = $service->generateUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400], false);
// Returns: https://your-domain.com/media/resize/ip/catalog/product/image.jpg?w=400&h=400&f=jpeg
```

**Without Domain (Relative Path):**
```php
$url = $service->generateBase64Url('catalog/product/image.jpg', ['w' => 400], false);
// Returns: /media/resize/{base64}.jpeg
```

### Service Registry

The `ResizeUrlGenerationService` is registered in Magento's dependency injection container (`etc/di.xml`) and can be accessed globally:

```php
// Via ObjectManager (not recommended, use dependency injection)
$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
$service = $objectManager->get(\Genaker\ImageAIBundle\Service\ResizeUrlGenerationService::class);
```

**Note:** Always prefer dependency injection over ObjectManager for better testability and performance.

## Performance

- **Caching**: All resized images are cached to disk for fast subsequent requests
- **Lazy Processing**: Images are only processed when requested
- **Optimized Formats**: WebP support for smaller file sizes
- **Lock Mechanism**: Prevents race conditions during concurrent requests
- **Base64 URLs**: Optimized for nginx direct serving (no PHP overhead for cached images)

## Requirements

- **Magento**: 2.4.x
- **PHP**: 7.4 or higher
- **Extensions**: GD or Imagick (for image processing)
- **Optional**: Google Gemini API key (for AI features)
  - Set via admin: **Stores > Configuration > Genaker > Image AI Resize > Gemini API Key**
  - Or environment variable: `GEMINI_API_KEY`
- **Optional**: Python 3.7+ (for Python video generation implementation)
  - `google-generativeai >= 0.3.0`
  - `requests >= 2.31.0`

### Video Model Configuration (Environment Variables)

**For Veo 3.1 (Default):**
- No additional configuration needed
- Uses Google AI Studio API key

**For Imagen (Testing):**
```bash
# Enable Imagen model
export VIDEO_MODEL=imagen

# Set Vertex AI endpoint (required)
export VERTEX_AI_ENDPOINT=https://{region}-aiplatform.googleapis.com/v1/projects/{project}/locations/{region}/publishers/google/models/imagegeneration@006

# Optional: Vertex AI access token (if using Bearer auth)
export VERTEX_AI_ACCESS_TOKEN=your_token
```

**Note:** Imagen is faster and has higher quotas, making it ideal for testing. Veo 3.1 provides higher quality and is recommended for production.

## How Media App Interceptor Works

The module uses a Magento plugin to intercept requests to the Media Storage app and route resize requests to the standard HTTP app instead. This allows the module to handle `/media/resize/ip/` URLs without modifying core Magento files.

### Request Flow

1. **Request Arrives**: A request comes to `/media/resize/ip/catalog/product/image.jpg?w=300&h=300`
   
2. **Web Server Routing**: The web server routes `/media/*` requests to `pub/get.php` (configured in `.magento.app.yaml`)

3. **Media App Created**: `pub/get.php` creates a `Magento\MediaStorage\App\Media` application instance

4. **Plugin Intercepts**: The `MediaPlugin::aroundLaunch()` method intercepts the `Media::launch()` call before it executes

5. **Path Detection**: The plugin checks if the request is a resize path:
   - Extracts `relativeFileName` from the Media app (e.g., `resize/ip/catalog/product/image.jpg`)
   - Checks if path starts with `resize/ip/`

6. **Request Transformation**: If it's a resize path, the plugin:
   - Extracts the image path (everything after `resize/ip/`)
   - Modifies `$_SERVER` variables:
     - `REQUEST_URI`: `/media/resize/ip?ip=catalog/product/image.jpg&w=300&h=300`
     - `PATH_INFO`: `/media/resize/ip`
     - `QUERY_STRING`: `ip=catalog/product/image.jpg&w=300&h=300`
   - Sets `$_GET['ip']` parameter

7. **HTTP App Bootstrap**: The plugin creates a new `Magento\Framework\App\Http` application and runs it:
   ```php
   $bootstrap = Bootstrap::create(BP, $_SERVER);
   $app = $bootstrap->createApplication(Http::class);
   return $bootstrap->run($app);
   ```

8. **Standard Routing**: The HTTP app routes `/media/resize/ip` to `Genaker\ImageAIBundle\Controller\Resize\Ip` controller via `routes.xml`

9. **Controller Processing**: The controller processes the resize request and returns the resized image

### Plugin Configuration

The plugin is registered in `etc/di.xml`:

```xml
<type name="Magento\MediaStorage\App\Media">
    <plugin name="genaker_imageaibundle_media_plugin" 
            type="Genaker\ImageAIBundle\Plugin\MediaStorage\App\MediaPlugin" 
            sortOrder="1"/>
</type>
```

### Why This Approach?

- **No Core Modifications**: Doesn't require modifying `pub/get.php` or other core files
- **Clean Separation**: Uses Magento's plugin system for clean interception
- **Maintainable**: Easy to update and maintain without affecting core functionality
- **Compatible**: Works with all Magento versions that support plugins

### Handling Recursive Calls

The plugin includes logic to prevent infinite loops:
- Checks if `relativePath` is `media/resize/ip` and `ip` parameter exists (second call)
- Routes directly to HTTP app without further processing
- Prevents the Media app from processing the transformed request

## Testing AI Functionality

The module integrates with Google Gemini API for AI-powered image modification. Here's how to test it:

### Prerequisites

1. **Set Gemini API Key**: 
   - Set environment variable: `export GEMINI_API_KEY=your_api_key_here`
   - Or configure in admin: **Stores > Configuration > Genaker > Image AI Resize > Gemini API Key**

2. **Admin Access Required**: 
   - AI prompts are only available for admin users (logged in to admin panel)
   - Or when signature validation is enabled (signature provides security)

### Testing via Browser

**1. Basic AI Enhancement**

As an admin user, use the `prompt` parameter:

```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=jpeg&prompt=Make%20this%20image%20brighter%20and%20more%20vibrant
```

**2. Color Enhancement**

```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=400&h=400&f=jpeg&prompt=Enhance%20colors%20and%20increase%20saturation
```

**3. Style Modification**

```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=500&h=500&f=jpeg&prompt=Apply%20a%20vintage%20filter%20with%20warm%20tones
```

**4. Background Removal**

```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=png&prompt=Remove%20background%20and%20make%20it%20transparent
```

**5. Object Enhancement**

```
https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=600&h=600&f=jpeg&prompt=Sharpen%20the%20product%20and%20improve%20details
```

### Testing via cURL

**1. Basic Test (Admin Session Required)**

```bash
# First, get admin session cookie
curl -c cookies.txt -b cookies.txt \
  "https://your-domain.com/admin" \
  --data "login[username]=admin&login[password]=password"

# Then test AI modification
curl -b cookies.txt \
  "https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=jpeg&prompt=Enhance%20colors" \
  --output enhanced_image.jpg
```

**2. With Signature (No Admin Required)**

If signature validation is enabled, you can use AI prompts without admin access:

```bash
# Generate signature first (see Signature Validation section)
# Then use in URL:
curl "https://your-domain.com/media/resize/ip/catalog/product/w/t/wt09-white_main_1.jpg?w=300&h=300&f=jpeg&prompt=Enhance%20colors&sig=your_signature" \
  --output enhanced_image.jpg
```

### Testing via Integration Test

Run the integration test suite:

```bash
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Integration/Controller/Resize/IpCurlTest.php
```

### Expected Behavior

**Successful AI Modification:**
- Returns modified image with applied changes
- Image format matches requested format (`f` parameter)
- Image dimensions match requested dimensions (`w`, `h` parameters)
- Response time may be slower due to API call (first request)
- Subsequent requests use cache (faster)

**Error Cases:**
- **401/403**: Admin access required or signature validation failed
- **400**: Invalid prompt or API key not configured
- **500**: Gemini API error (check logs for details)

### AI Prompt Guidelines

**Effective Prompts:**
- ✅ "Make this image brighter and more vibrant"
- ✅ "Enhance colors and increase saturation"
- ✅ "Apply vintage filter with warm tones"
- ✅ "Remove background and make it transparent"
- ✅ "Sharpen the product and improve details"
- ✅ "Increase contrast and brightness"

**Ineffective Prompts:**
- ❌ "Make it better" (too vague)
- ❌ "Change everything" (not specific)
- ❌ Very long prompts (may exceed API limits)

### Troubleshooting AI Testing

**1. API Key Not Working**
- Verify `GEMINI_API_KEY` environment variable is set: `echo $GEMINI_API_KEY`
- Check admin configuration: **Stores > Configuration > Genaker > Image AI Resize**
- Verify API key is valid and has proper permissions

**2. Admin Access Required Error**
- Ensure you're logged in to admin panel
- Or enable signature validation and use signed URLs

**3. API Errors**
- Check Magento logs: `var/log/system.log`
- Look for Gemini API error messages
- Verify API quota and rate limits

**4. No Changes Applied**
- Check if prompt is being processed (look for API calls in logs)
- Verify image format supports modifications (some formats may not work well)
- Try simpler prompts first

**5. Slow Response Times**
- First request calls Gemini API (slower)
- Subsequent requests use cache (faster)
- Consider caching AI-modified images for production

### AI Modification Limitations

- **Format Support**: Works best with JPEG and PNG formats
- **Size Limits**: Very large images may fail (API limits)
- **Processing Time**: First request takes longer due to API call
- **Cost**: Each AI modification uses API quota
- **Quality**: Results depend on prompt clarity and image quality

## Troubleshooting

### Images Not Resizing

1. Check file permissions on `/pub/media/cache/resize/`
2. Verify image path is correct
3. Check Magento logs: `var/log/system.log`
4. Ensure format parameter (`f`) is provided

### Signature Validation Failing

1. Verify signature salt is configured correctly
2. Ensure parameters are sorted alphabetically when generating signature
3. Check that signature parameter is included in URL

### Cache Not Working

1. Verify directory permissions: `chmod -R 755 pub/media/cache/`
2. Check disk space availability
3. Verify Magento cache is enabled

### Media App Interceptor Not Working

1. Verify plugin is registered: Check `etc/di.xml` for plugin configuration
2. Clear generated code: `bin/magento setup:di:compile`
3. Check plugin is being called: Add logging to `MediaPlugin::aroundLaunch()`
4. Verify route configuration: Check `etc/frontend/routes.xml` has `frontName="media"`
5. Check Magento logs for plugin errors

## Development

### Module Structure

```
app/code/Genaker/ImageAIBundle/
├── Api/
│   └── ImageResizeServiceInterface.php
├── Controller/
│   ├── Resize/
│   │   └── Index.php
│   └── Adminhtml/
│       └── Generate/
│           └── Index.php
├── Model/
│   └── ResizeResult.php
├── Service/
│   └── ImageResizeService.php
└── etc/
    ├── module.xml
    ├── config.xml
    ├── system.xml
    ├── di.xml
    ├── acl.xml
    ├── frontend/
    │   └── routes.xml
    └── adminhtml/
        └── routes.xml
```

## License

Copyright (c) 2024 Genaker. All rights reserved.

## Support

For issues, questions, or contributions, please visit: https://github.com/Genaker/ImageResizeAI

## Changelog

### Version 1.2.0
- **Python Implementation**: Added Python-based video generation script with Google Generative AI SDK
- **Magento CLI Proxy**: Added `agento-p:video` command that proxies to Python script with automatic Magento config integration
- **Multiple Image Processing**: Python implementation supports processing multiple images in a single command
- **Automatic Base URL**: Magento CLI command automatically retrieves base URL from store configuration
- **Enhanced Error Handling**: Python implementation includes improved safety filter detection and error messages
- **Robust MIME Detection**: Python implementation uses built-in `mimetypes` library for accurate MIME type detection
- **Full HTTPS URLs**: Python implementation generates complete HTTPS URLs with domain (not relative paths)
- **Output Format Options**: PHP command (`agento:video`) now supports JSON, plain, and table output formats

### Version 1.1.0
- **Video Generation**: Added Google Veo 3.1 API integration for AI-powered video generation from images
- **Video Caching**: Automatic caching of generated videos based on image path, prompt, and aspect ratio
- **Silent Video**: Added `silentVideo=true` parameter to generate videos without audio (avoids audio-related safety filters)
- **Direct Video Return**: Added `return=video` parameter to return video content directly instead of JSON
- **Safety Filter Handling**: Improved error messages for Google's safety filter blocks with actionable suggestions
- **Redirect Handling**: Automatic handling of 302 redirects from Google Files API for video downloads
- **Error Handling**: Enhanced error handling with detailed error codes and troubleshooting guidance
- **Video Storage**: Videos saved to `pub/media/video/` directory (always enabled)
- **URL Format**: Fixed video URLs to exclude store code segment (`/default/`)

### Version 1.0.0
- Initial release
- Basic image resizing functionality
- Signature validation support
- Admin URL generator
- Multiple format support
- Caching system
- Gemini AI image modification support