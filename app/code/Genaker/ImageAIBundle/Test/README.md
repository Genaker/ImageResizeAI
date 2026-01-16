# Genaker ImageAIBundle Tests

## Test Structure

- `Unit/Service/ImageResizeServiceTest.php` - Tests for ImageResizeService
- `Unit/Controller/Resize/IndexTest.php` - Tests for Resize Controller

## Running Tests

### Run all tests for the module:

```bash
cd /var/www/html
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Unit/
```

### Run specific test class:

```bash
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Unit/Service/ImageResizeServiceTest.php
```

### Run specific test method:

```bash
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Unit/Service/ImageResizeServiceTest.php --filter testResizeImageWithPathUrl
```

## Test Coverage

### ImageResizeServiceTest
- ✅ Resize image with path URL
- ✅ Resize image with different formats (webp, jpg, png, gif)
- ✅ Image resize caching
- ✅ Resize with width only
- ✅ Resize with height only
- ✅ Validation - invalid width
- ✅ Validation - missing format
- ✅ Validation - invalid format
- ✅ Resize with path starting with slash
- ✅ Get original image path
- ✅ Image exists check

### IndexTest (Controller)
- ✅ Resize image with path URL parameters
- ✅ Resize image with width only
- ✅ Resize image with leading slash
- ✅ Resize image with inferred format
- ✅ Missing image path error handling

## Requirements

- PHPUnit ^9.5
- GD extension for image manipulation
- Magento 2.4.x

## Notes

- Tests use temporary files and directories
- Tests clean up after themselves
- Tests mock Magento dependencies to avoid requiring full Magento installation
