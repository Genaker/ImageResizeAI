# Nginx Configuration for ImageAIBundle

This document explains how to configure Nginx to optimize image resize URL handling with cache-first approach.

## Overview

The ImageAIBundle supports two URL formats:
1. **Base64 format**: `/media/resize/{base64_encoded_params}.{extension}` 
2. **Query string format**: `/media/resize/{imagePath}?w=100&h=100&f=webp`

Nginx can serve cached base64 URLs directly without hitting PHP, improving performance significantly.

## Installation

### Option 1: Include in Main Nginx Config

Add the configuration snippet to your main Nginx configuration file (usually in `/etc/nginx/sites-available/your-site.conf` or similar):

```nginx
# Image resize endpoints - check cache first for base64 format, then forward to PHP
location ~ ^/media/resize/(.+)$ {
    set $cache_filename $1;
    set $attempted_cache_path /media/cache/resize/$cache_filename;
    
    # Try cache first, then PHP
    try_files /media/cache/resize/$cache_filename /index.php$is_args$args;
    
    # Cache headers
    expires 1y;
    add_header Cache-Control "public, immutable" always;
    add_header X-Cache-Status "HIT" always;
    add_header X-Served-By "nginx" always;
    add_header X-Cache-Path-Attempted $attempted_cache_path always;
    add_header X-Request-Uri $uri always;
    access_log off;
}
```

### Option 2: Include as Separate File

1. Copy `nginx.conf.example` to your Nginx configuration directory:
   ```bash
   cp nginx.conf.example /etc/nginx/conf.d/imageaibundle.conf
   ```

2. Include it in your main Nginx config:
   ```nginx
   include /etc/nginx/conf.d/imageaibundle.conf;
   ```

## How It Works

1. **Request arrives**: `/media/resize/{base64}.webp` or `/media/resize/image.jpg?w=100&h=100`

2. **Nginx checks cache**: 
   - For base64 URLs: Checks `/media/cache/resize/{base64}.webp`
   - If found: Serves directly from disk (fast!)
   - If not found: Forwards to PHP via `index.php`

3. **PHP processes**:
   - Detects if URL is base64 or query string format
   - Generates/resizes image if needed
   - Saves to cache directory
   - Returns image

4. **Subsequent requests**: Nginx serves from cache directly (no PHP overhead)

## Cache Directory Setup

Ensure the cache directory exists and has proper permissions:

```bash
mkdir -p pub/media/cache/resize
chmod -R 755 pub/media/cache/resize
chown -R www-data:www-data pub/media/cache/resize  # Adjust user/group as needed
```

## Performance Benefits

- **Base64 URLs**: Served directly by Nginx (no PHP overhead)
- **Query string URLs**: Processed by PHP, then cached for future requests
- **Cache hit rate**: Can reach 90%+ for frequently accessed images
- **Response time**: < 1ms for cached images vs 50-200ms for PHP processing

## Testing

After configuring Nginx, test with:

```bash
# Test base64 URL (should be served by Nginx if cached)
curl -I https://your-domain.com/media/resize/{base64}.webp

# Check headers
# X-Served-By: nginx (if served from cache)
# X-Served-By: php (if processed by PHP)
```

## Troubleshooting

### Images not being served by Nginx

1. Check cache directory permissions:
   ```bash
   ls -la pub/media/cache/resize/
   ```

2. Verify Nginx can read files:
   ```bash
   sudo -u www-data cat pub/media/cache/resize/{some_file}
   ```

3. Check Nginx error logs:
   ```bash
   tail -f /var/log/nginx/error.log
   ```

### Cache not working

1. Ensure cache directory is writable:
   ```bash
   chmod -R 777 pub/media/cache/resize
   ```

2. Check Magento file permissions:
   ```bash
   bin/magento setup:static-content:deploy
   ```

### Base64 URLs not matching

- Base64 URLs use URL-safe encoding (`-` and `_` instead of `+` and `/`)
- Ensure cache file names match exactly (case-sensitive)
- Check that parameters are sorted alphabetically in base64 encoding

## Advanced Configuration

### Custom Cache Location

If you want to store cache files in a different location:

```nginx
location ~ ^/media/resize/(.+)$ {
    set $cache_filename $1;
    set $attempted_cache_path /custom/cache/path/$cache_filename;
    
    try_files /custom/cache/path/$cache_filename /index.php$is_args$args;
    # ... rest of config
}
```

### Cache Warming

To pre-generate cache files, use Magento CLI:

```bash
# Process images from config file
php bin/magento genaker:image-resize:process --config=path/to/config.yml
```

## Security Considerations

- Base64 URLs can be long - ensure `client_max_body_size` is sufficient
- Consider rate limiting for `/media/resize/` endpoints
- Monitor cache directory size to prevent disk space issues

## See Also

- [README.md](README.md) - Module documentation
- [nginx.conf.example](nginx.conf.example) - Configuration template

