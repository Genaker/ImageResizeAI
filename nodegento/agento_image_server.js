#!/usr/bin/env node
/**
 * Agento Image Server - Node.js
 * HTTP server for image generation using Gemini API
 */

const express = require('express');
const path = require('path');
const fs = require('fs');
const { NanaBabanaImageService } = require('./agento_image_service');
const { generateDescriptiveFilename } = require('./agento_image_filename');

const app = express();
const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';

// Middleware
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Configuration
const GEMINI_API_KEY = process.env.GEMINI_API_KEY || '';
const BASE_PATH = process.env.BASE_PATH || process.cwd();
const UPLOAD_FOLDER = path.join(BASE_PATH, 'pub', 'media', 'lookbook');

// Global service instance
const imageService = new NanaBabanaImageService(
    GEMINI_API_KEY,
    BASE_PATH,
    null,
    process.env.DEBUG === 'true'
);

// Ensure upload directory exists
if (!fs.existsSync(UPLOAD_FOLDER)) {
    fs.mkdirSync(UPLOAD_FOLDER, { recursive: true });
}

// Routes

app.get('/health', (req, res) => {
    /** Health check endpoint. */
    res.json({
        status: 'healthy',
        service: 'agento-image-server-node',
        api_available: imageService.isAvailable()
    });
});

app.post('/generate', async (req, res) => {
    /**
     * Generate image from one or two input images.
     *
     * Expected JSON payload:
     * {
     *   "model_image": "path/to/image.jpg or https://url/to/image.jpg",
     *   "look_image": "path/to/image.jpg or https://url/to/image.jpg (optional)",
     *   "prompt": "Description of what to generate",
     *   "api_key": "optional override API key"
     * }
     */
    try {
        const { model_image, look_image, prompt, api_key } = req.body;

        // Validate required parameters
        if (!model_image) {
            return res.status(400).json({
                success: false,
                error: 'model_image is required'
            });
        }

        if (!prompt) {
            return res.status(400).json({
                success: false,
                error: 'prompt is required'
            });
        }

        // Create service instance with optional API key override
        const service = new NanaBabanaImageService(
            api_key || GEMINI_API_KEY,
            BASE_PATH
        );

        if (!service.isAvailable()) {
            return res.status(500).json({
                success: false,
                error: 'API key not configured'
            });
        }

        // Generate image
        const op = await service.generateImage(model_image, look_image, prompt);

        if (op.done) {
            // Generate descriptive filename
            const cacheKey = generateDescriptiveFilename(model_image, look_image, prompt);

            // Save the generated image
            const savedPath = service.saveAssetFromOperation(op, cacheKey);

            res.json({
                success: true,
                status: 'completed',
                saved_path: savedPath,
                filename: path.basename(savedPath),
                message: `Image generated and saved to ${savedPath}`,
                input: {
                    model_image,
                    look_image,
                    prompt
                }
            });
        } else {
            // Async response (fallback)
            res.json(op);
        }

    } catch (error) {
        console.error('Error generating image:', error);
        res.status(500).json({
            success: false,
            error: error.message,
            stack: process.env.DEBUG === 'true' ? error.stack : undefined
        });
    }
});

app.get('/images/:filename', (req, res) => {
    /** Serve generated images. */
    try {
        const filename = req.params.filename;
        const imagePath = path.join(UPLOAD_FOLDER, filename);

        if (!fs.existsSync(imagePath)) {
            return res.status(404).json({
                success: false,
                error: `Image ${filename} not found`
            });
        }

        res.sendFile(imagePath);

    } catch (error) {
        res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

// Error handling
app.use((err, req, res, next) => {
    console.error(err.stack);
    res.status(500).json({
        success: false,
        error: 'Internal server error'
    });
});

// 404 handler
app.use((req, res) => {
    res.status(404).json({
        success: false,
        error: 'Endpoint not found'
    });
});

// Start server
if (require.main === module) {
    app.listen(PORT, HOST, () => {
        console.log(`🚀 Agento Image Server (Node.js) listening on ${HOST}:${PORT}`);
        console.log(`📁 Upload folder: ${UPLOAD_FOLDER}`);
        console.log(`🔑 API available: ${imageService.isAvailable()}`);
        if (process.env.DEBUG === 'true') {
            console.log('🐛 Debug mode enabled');
        }
    });
}

module.exports = app;