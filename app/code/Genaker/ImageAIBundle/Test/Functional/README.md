# Functional Tests for Genaker ImageAIBundle

This directory contains functional tests that test the image resize functionality by making real HTTP requests to the controller.

## Test Structure

The functional tests are located in:
- `Controller/Resize/IndexTest.php` - Tests for the image resize controller

## Running the Tests

### Prerequisites

1. Magento 2 must be installed and configured
2. The module must be enabled
3. Test images will be created in `pub/media/catalog/product/`

### Run Tests

```bash
# Run all functional tests
cd /var/www/html
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Functional/Controller/Resize/IndexTest.php --no-coverage

# Run specific test
vendor/bin/phpunit vendor/genaker/imageaibundle/app/code/Genaker/ImageAIBundle/Test/Functional/Controller/Resize/IndexTest.php --no-coverage --filter testResizeImageViaUrl
```

## Test Coverage

The functional tests cover:

1. **Basic Resize** - Resize image with width, height, format, and quality parameters
2. **Width Only** - Resize with only width specified
3. **Height Only** - Resize with only height specified
4. **Caching** - Verify that second request uses cache
5. **Different Formats** - Test webp, jpg, png formats
6. **Error Handling** - Test missing path, non-existent image, invalid format

## Test URL Format

The tests use the following URL format:
```
/media/resize/index/imagePath/{imagePath}?w={width}&h={height}&f={format}&q={quality}
```

Example:
```
/media/resize/index/imagePath/catalog/product/test-image.jpg?w=300&h=300&f=webp&q=85
```

## Notes

- Tests create temporary test images in `pub/media/catalog/product/`
- Test images are automatically cleaned up after each test
- Cache files are also cleaned up after tests
- The tests require GD extension for image creation

## Known Issues

The functional test framework is correctly set up and can successfully:
- Bootstrap Magento application
- Create test images
- Call the controller directly
- Retrieve response from Raw result objects

However, there is a bug in the `ImageResizeService` code (vendor code) where `filesystem->isExists()` is called with an absolute path, but the Magento filesystem interface expects a relative path from the media directory root. This causes the service to fail to find images even when they exist.

**Location of bug**: `ImageResizeService.php` line 71
```php
$sourcePath = $this->resolveSourceImagePath($imagePath); // Returns absolute path
if (!$this->filesystem->isExists($sourcePath)) { // Expects relative path
```

**Fix needed**: The service should use a relative path for `isExists()` checks, or use `file_exists()` for absolute paths.

The test framework correctly identifies this issue and demonstrates that the controller and routing work correctly when the service bug is fixed.
