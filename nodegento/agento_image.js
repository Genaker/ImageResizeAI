#!/usr/bin/env node
/**
 * Agento Image CLI - Node.js
 * Generate image from images using Google Gemini 2.5 Flash Image API
 */

const fs = require('fs');
const path = require('path');
const { program } = require('commander');
const chalk = require('chalk');
const { NanaBabanaImageService } = require('./agento_image_service');
const { generateDescriptiveFilename } = require('./agento_image_filename');

// Load .env file if dotenv is available
let dotenv;
try {
    dotenv = require('dotenv');
    dotenv.config();
} catch (e) {
    // dotenv not available, skip
}

async function main() {
    program
        .name('agento-image')
        .description('Generate image from one or two input images (Gemini 2.5 Flash Image)')
        .version('1.0.0');

    program
        .option('-k, --api-key <key>', 'API key (overrides env GEMINI_API_KEY)')
        .option('-u, --base-url <url>', 'Base API URL (overrides GOOGLE_API_DOMAIN)')
        .option('-m, --model-image <path>', 'Path to Image 1 (model image) or URL', null)
        .option('-l, --look-image [path]', 'Path to Image 2 (look/clothing image) or URL (optional)')
        .option('-p, --prompt <text>', 'Prompt for generation', null)
        .option('-b, --base-path <path>', 'Base path to save generated assets (defaults to cwd)')
        .option('-v, --verbose', 'Enable verbose output')
        .option('--image-1 <path>', 'Alias for --model-image')
        .option('--image-2 [path]', 'Alias for --look-image');

    program.parse();

    const options = program.opts();

    // Handle aliases
    const modelImage = options.modelImage || options.image1;
    const lookImage = options.lookImage || options.image2;
    const prompt = options.prompt;
    const apiKey = options.apiKey;
    const baseUrl = options.baseUrl;
    const basePath = options.basePath;
    const verbose = options.verbose;

    // Validate required parameters
    if (!modelImage) {
        console.error(chalk.red('Error: --model-image (-m) is required'));
        process.exit(1);
    }

    if (!prompt) {
        console.error(chalk.red('Error: --prompt (-p) is required'));
        process.exit(1);
    }

    try {
        if (verbose) {
            console.error(chalk.blue('🚀 Starting image generation...'));
        }

        const service = new NanaBabanaImageService(apiKey, basePath, baseUrl, verbose);

        if (!service.isAvailable()) {
            console.error(chalk.red('Error: API key not configured. Set GEMINI_API_KEY environment variable or use --api-key'));
            process.exit(1);
        }

        // Generate image
        const op = await service.generateImage(modelImage, lookImage, prompt);

        if (op.done) {
            // Generate descriptive filename
            const cacheKey = generateDescriptiveFilename(modelImage, lookImage, prompt);

            // Save the generated image
            const savedPath = service.saveAssetFromOperation(op, cacheKey);

            console.log(JSON.stringify({
                success: true,
                status: 'completed',
                saved_path: savedPath,
                message: `Image generated and saved to ${savedPath}`
            }, null, 2));

            if (verbose) {
                console.error(chalk.green(`✅ Image saved to: ${savedPath}`));
            }
        } else {
            // Async response (fallback)
            console.log(JSON.stringify(op, null, 2));
        }

    } catch (error) {
        console.error(chalk.red('Error:'), error.message);
        if (verbose) {
            console.error(chalk.red('Stack trace:'), error.stack);
        }
        process.exit(1);
    }
}

if (require.main === module) {
    main().catch(error => {
        console.error(chalk.red('Unexpected error:'), error);
        process.exit(1);
    });
}

module.exports = { main };