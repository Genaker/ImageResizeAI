# Video Generation API Server

HTTP API proxy server for `agento_video.py` CLI command. Provides REST API access to video generation functionality with API key authentication.

## Features

- **REST API**: HTTP POST endpoint for video generation
- **API Key Authentication**: Secure access using environment variable
- **JSON Request/Response**: Simple JSON-based API
- **CORS Support**: Cross-origin requests enabled
- **Full Feature Support**: All CLI options available via API

## Installation

No additional dependencies required - uses Python standard library.

## Configuration

Set environment variables:

```bash
# Required: API key for authentication
export VIDEO_API_KEY="your-secret-api-key-here"

# Optional: Server configuration
export VIDEO_API_HOST="127.0.0.1"  # Default: 127.0.0.1
export VIDEO_API_PORT="8080"       # Default: 8080

# Optional: Gemini API key (or pass in request)
export GEMINI_API_KEY="your-gemini-api-key"
```

## Starting the Server

```bash
# Basic usage
python3 video_api_server.py --api-key "your-secret-key"

# Using environment variable
export VIDEO_API_KEY="your-secret-key"
python3 video_api_server.py

# Custom host and port
python3 video_api_server.py --host 0.0.0.0 --port 9000

# Custom Python script path
python3 video_api_server.py --python-script /path/to/agento_video.py
```

## API Endpoint

**POST** `/`

### Authentication

Include API key in one of these ways:
- **Authorization Header**: `Authorization: Bearer your-api-key`
- **X-API-Key Header**: `X-API-Key: your-api-key`

### Request Body (JSON)

```json
{
  "image_path": "https://example.com/image.jpg",
  "prompt": "Create a dynamic product showcase video",
  "sync": true,
  "aspect_ratio": "16:9",
  "silent_video": false,
  "second_image": null,
  "no_auto_reference": false,
  "api_key": "optional-gemini-api-key",
  "base_path": "/var/www/html",
  "save_path": "pub/media/video",
  "base_url": "https://example.com"
}
```

#### Required Fields

- `image_path` (string or array): Image path(s) or URL(s)
- `prompt` (string): Video generation prompt

#### Optional Fields

- `second_image` (string): Second image path or URL
- `sync` (boolean): Wait for completion (default: `true`)
- `aspect_ratio` (string): Aspect ratio, e.g., "16:9" (default: "16:9")
- `silent_video` (boolean): Generate silent video (default: `false`)
- `no_auto_reference` (boolean): Disable auto image reference (default: `false`)
- `api_key` (string): Gemini API key (or use GEMINI_API_KEY env)
- `base_path` (string): Base path for Magento installation
- `save_path` (string): Custom video save path
- `base_url` (string): Base URL for video URLs
- `env_file` (string): Path to .env file

### Response (JSON)

#### Success Response

```json
{
  "success": true,
  "data": {
    "success": true,
    "status": "completed",
    "videoUrl": "https://example.com/media/video/veo_abc123.mp4",
    "videoPath": "/var/www/html/pub/media/video/veo_abc123.mp4",
    "embedUrl": "<video>...</video>"
  }
}
```

#### Error Response

```json
{
  "success": false,
  "error": "Error message here",
  "return_code": 1
}
```

## Usage Examples

### cURL Example

```bash
# Basic request
curl -X POST http://localhost:8080/ \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-api-key" \
  -d '{
    "image_path": "https://example.com/image.jpg",
    "prompt": "Create a dynamic product showcase video",
    "sync": true
  }'

# With second image
curl -X POST http://localhost:8080/ \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "image_path": "https://example.com/background.jpg",
    "second_image": "https://example.com/foreground.jpg",
    "prompt": "Use image1 as background and image2 as foreground",
    "sync": true
  }'
```

### Python Example

```python
import requests
import json

url = "http://localhost:8080/"
headers = {
    "Content-Type": "application/json",
    "Authorization": "Bearer your-api-key"
}
data = {
    "image_path": "https://example.com/image.jpg",
    "prompt": "Create a dynamic product showcase video",
    "sync": True,
    "aspect_ratio": "16:9"
}

response = requests.post(url, headers=headers, json=data)
result = response.json()

if result["success"]:
    print(f"Video URL: {result['data']['videoUrl']}")
else:
    print(f"Error: {result['error']}")
```

### JavaScript/Node.js Example

```javascript
const fetch = require('node-fetch');

const url = 'http://localhost:8080/';
const headers = {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer your-api-key'
};
const data = {
    image_path: 'https://example.com/image.jpg',
    prompt: 'Create a dynamic product showcase video',
    sync: true
};

fetch(url, {
    method: 'POST',
    headers: headers,
    body: JSON.stringify(data)
})
.then(res => res.json())
.then(result => {
    if (result.success) {
        console.log('Video URL:', result.data.videoUrl);
    } else {
        console.error('Error:', result.error);
    }
});
```

### PHP Example

```php
<?php
$url = 'http://localhost:8080/';
$data = [
    'image_path' => 'https://example.com/image.jpg',
    'prompt' => 'Create a dynamic product showcase video',
    'sync' => true
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer your-api-key'
]);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['success']) {
    echo "Video URL: " . $result['data']['videoUrl'] . "\n";
} else {
    echo "Error: " . $result['error'] . "\n";
}

curl_close($ch);
?>
```

## Error Codes

- **200**: Success
- **400**: Bad Request (invalid JSON, missing required fields)
- **401**: Unauthorized (invalid or missing API key)
- **500**: Internal Server Error (server-side error)

## Security Notes

1. **API Key**: Store `VIDEO_API_KEY` securely, never commit to version control
2. **HTTPS**: Use HTTPS in production (use reverse proxy like nginx)
3. **Rate Limiting**: Consider adding rate limiting for production use
4. **Input Validation**: Server validates required fields but consider additional validation

## Production Deployment

### Using systemd (Linux)

Create `/etc/systemd/system/video-api.service`:

```ini
[Unit]
Description=Video Generation API Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/vendor/genaker/imageaibundle/pygento
Environment="VIDEO_API_KEY=your-secret-key"
Environment="GEMINI_API_KEY=your-gemini-key"
ExecStart=/usr/bin/python3 /var/www/html/vendor/genaker/imageaibundle/pygento/video_api_server.py
Restart=always

[Install]
WantedBy=multi-user.target
```

Start service:
```bash
sudo systemctl enable video-api
sudo systemctl start video-api
```

### Using Docker

```dockerfile
FROM python:3.9-slim

WORKDIR /app
COPY pygento/ /app/

ENV VIDEO_API_KEY=your-secret-key
ENV GEMINI_API_KEY=your-gemini-key

EXPOSE 8080

CMD ["python3", "video_api_server.py", "--host", "0.0.0.0", "--port", "8080"]
```

### Behind Nginx Reverse Proxy

```nginx
server {
    listen 80;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

## Testing

Test the server with mock server:

```bash
# Terminal 1: Start mock Veo server
python3 mock_veo_server.py --port 9000

# Terminal 2: Start API server with mock
export GOOGLE_API_DOMAIN=http://127.0.0.1:9000/v1beta
export VIDEO_API_KEY=test-key
export GEMINI_API_KEY=test-key
python3 video_api_server.py --port 8080

# Terminal 3: Test API
curl -X POST http://localhost:8080/ \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer test-key" \
  -d '{
    "image_path": "https://example.com/image.jpg",
    "prompt": "test video",
    "sync": true
  }'
```
