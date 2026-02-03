#!/usr/bin/env node
/**
 * Genaker ImageAIBundle - Node.js Console Command
 * Generate video from image using Google Gemini Veo 3.1 API
 * 
 * This script provides a Node.js implementation of the agento:video console command.
 * It mirrors the Python agento_video.py functionality.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { program } = require('commander');
const axios = require('axios');
const chalk = require('chalk');

// Load .env file if dotenv is available
let dotenv;
try {
    dotenv = require('dotenv');
    dotenv.config();
} catch (e) {
    // dotenv not available, skip
}

class GeminiVideoService {
    constructor(apiKey = null, baseUrl = null, verbose = false) {
        this.apiKey = apiKey || process.env.GEMINI_API_KEY || '';
        
        // Get base URL from parameter, env var, or default to production
        if (baseUrl) {
            this.baseUrl = baseUrl.replace(/\/$/, '');
        } else {
            const envBaseUrl = process.env.GOOGLE_API_DOMAIN;
            if (envBaseUrl) {
                this.baseUrl = envBaseUrl.replace(/\/$/, '');
            } else {
                // Default to production API
                this.baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
            }
        }
        
        this.modelName = 'veo-3.1-generate-preview';
        this.verbose = verbose;
    }
    
    isAvailable() {
        return !!this.apiKey;
    }
    
    async submitVideoGenerationRequest(prompt, imageData, mimeType, aspectRatio = '16:9', secondImageData = null, secondMimeType = null) {
        if (!this.isAvailable()) {
            throw new Error('Gemini video service is not available. Please configure the Gemini API key.');
        }
        
        const endpoint = `${this.baseUrl}/models/${this.modelName}:predictLongRunning`;
        
        // Prepare payload with first image
        const instanceData = {
            prompt: prompt,
            image: {
                bytesBase64Encoded: imageData.toString('base64'),
                mimeType: mimeType
            }
        };
        
        // Add second image if provided
        if (secondImageData && secondMimeType) {
            instanceData.image2 = {
                bytesBase64Encoded: secondImageData.toString('base64'),
                mimeType: secondMimeType
            };
        }
        
        // Prepare payload
        const payload = {
            instances: [instanceData],
            parameters: {}
        };
        
        // Add aspect ratio if specified
        if (aspectRatio) {
            payload.parameters.aspectRatio = aspectRatio;
        }
        
        // Debug output
        if (this.verbose) {
            console.error('='.repeat(80));
            console.error('DEBUG: Video Generation Request');
            console.error('='.repeat(80));
            console.error(`Endpoint: ${endpoint}`);
            console.error(`Model: ${this.modelName}`);
            console.error(`Prompt: ${prompt}`);
            console.error(`Image 1 - Size: ${imageData.length} bytes, MIME: ${mimeType}`);
            if (secondImageData) {
                console.error(`Image 2 - Size: ${secondImageData.length} bytes, MIME: ${secondMimeType}`);
            }
            console.error(`Aspect Ratio: ${aspectRatio}`);
            console.error('\nRequest Payload:');
            const debugPayload = JSON.parse(JSON.stringify(payload));
            if (debugPayload.instances && debugPayload.instances.length > 0) {
                const instance = debugPayload.instances[0];
                if (instance.image) {
                    const imgData = instance.image.bytesBase64Encoded;
                    instance.image.bytesBase64Encoded = `${imgData.substring(0, 50)}... (truncated, ${imgData.length} chars total)`;
                }
                if (instance.image2) {
                    const img2Data = instance.image2.bytesBase64Encoded;
                    instance.image2.bytesBase64Encoded = `${img2Data.substring(0, 50)}... (truncated, ${img2Data.length} chars total)`;
                }
            }
            console.error(JSON.stringify(debugPayload, null, 2));
            console.error('='.repeat(80));
        }
        
        // Make API request
        const headers = {
            'x-goog-api-key': this.apiKey,
            'Content-Type': 'application/json'
        };
        
        try {
            const response = await axios.post(endpoint, payload, { headers, timeout: 60000 });
            
            if (this.verbose) {
                console.error('\n[API Response] Status Code:', response.status);
                console.error('[API Response] Headers:', response.headers);
                console.error('[API Response] Body:');
                console.error(JSON.stringify(response.data, null, 2));
                console.error('='.repeat(80));
            }
            
            const data = response.data;
            
            if (!data.name) {
                throw new Error('Invalid API response: operation name not found');
            }
            
            return {
                operationName: data.name,
                done: data.done || false,
                status: data.done ? 'completed' : 'running'
            };
        } catch (error) {
            if (this.verbose) {
                console.error(`\n[API Error] Request Exception: ${error.message}`);
                if (error.response) {
                    console.error(`[API Error] Response Status: ${error.response.status}`);
                    console.error(`[API Error] Response Body: ${JSON.stringify(error.response.data, null, 2)}`);
                }
            }
            throw new Error(`Gemini API request failed: ${error.message}`);
        }
    }
    
    async pollOperationStatus(operationName, maxWaitSeconds = 300, pollIntervalSeconds = 10) {
        if (!this.isAvailable()) {
            throw new Error('Gemini video service is not available.');
        }
        
        const pollUrl = `${this.baseUrl}/${operationName}`;
        const headers = {
            'x-goog-api-key': this.apiKey
        };
        
        const startTime = Date.now();
        const spinnerFrames = [
            '🎬 Generating video',
            '🎥 Creating magic',
            '✨ Crafting frames',
            '🎞️  Processing scenes',
            '🎭 Building story',
            '🎨 Adding effects',
            '🌟 Finalizing'
        ];
        let spinnerIdx = 0;
        let lastSpinnerTime = startTime;
        
        // Show initial message
        if (!this.verbose) {
            process.stderr.write('🎬 Starting video generation...');
        }
        
        while (true) {
            // Check timeout
            const elapsed = (Date.now() - startTime) / 1000;
            if (elapsed > maxWaitSeconds) {
                if (!this.verbose) {
                    process.stderr.write('\n');
                }
                throw new Error(`Video generation timeout after ${maxWaitSeconds} seconds`);
            }
            
            // Update spinner animation (every 0.8 seconds, only if not verbose)
            if (!this.verbose) {
                const currentTime = Date.now();
                if (currentTime - lastSpinnerTime >= 800) {
                    const spinnerMsg = spinnerFrames[spinnerIdx % spinnerFrames.length];
                    const elapsedStr = ` (${Math.floor(elapsed)}s)`;
                    process.stderr.write(`\r${spinnerMsg}${elapsedStr}`);
                    spinnerIdx++;
                    lastSpinnerTime = currentTime;
                }
            }
            
            // Poll operation status
            try {
                const response = await axios.get(pollUrl, { headers, timeout: (pollIntervalSeconds + 5) * 1000 });
                
                // Debug output for polling
                if (this.verbose) {
                    console.error(`\n[Poll ${Math.floor(elapsed)}s] Status Code: ${response.status}`);
                    if (response.status === 200) {
                        try {
                            const pollData = response.data;
                            console.error(`  Elapsed: ${Math.floor(elapsed)}s`);
                            console.error(`  Done: ${pollData.done || false}`);
                            console.error(`\n[Poll ${Math.floor(elapsed)}s] Raw API Response:`);
                            console.error(JSON.stringify(pollData, null, 2));
                        } catch (e) {
                            console.error(`  Error parsing response: ${e.message}`);
                            console.error(`  Raw response: ${response.data ? response.data.substring(0, 500) : ''}`);
                        }
                    }
                }
                
                const data = response.data;
                
                // Check if operation is done
                if (data.done) {
                    if (!this.verbose) {
                        process.stderr.write('\r✅ Video generation complete!' + ' '.repeat(20) + '\n');
                    }
                    if (this.verbose) {
                        console.error('\n[Poll Complete] Final Response:');
                        console.error(JSON.stringify(data, null, 2));
                    }
                    return data;
                }
                
                // Not done yet, wait and poll again
                await new Promise(resolve => setTimeout(resolve, pollIntervalSeconds * 1000));
            } catch (error) {
                if (this.verbose) {
                    console.error(`\n[Poll Error] Request Exception: ${error.message}`);
                    if (error.response) {
                        console.error(`[Poll Error] Response Status: ${error.response.status}`);
                        console.error(`[Poll Error] Response Body: ${JSON.stringify(error.response.data, null, 2)}`);
                    }
                }
                throw new Error(`Video operation polling failed: ${error.message}`);
            }
        }
    }
    
    extractVideoUri(operationData) {
        if (operationData.response && operationData.response.generateVideoResponse) {
            const genRes = operationData.response.generateVideoResponse;
            
            // Check for safety filter blocks
            if (genRes.raiMediaFilteredReasons) {
                const reasons = Array.isArray(genRes.raiMediaFilteredReasons) 
                    ? genRes.raiMediaFilteredReasons.join(', ') 
                    : String(genRes.raiMediaFilteredReasons);
                const filteredCount = genRes.raiMediaFilteredCount || (Array.isArray(genRes.raiMediaFilteredReasons) ? genRes.raiMediaFilteredReasons.length : 1);
                throw new Error(`Video generation was filtered by safety filters. Reason: ${reasons}. Filtered count: ${filteredCount}`);
            }
            
            if (genRes.generatedSamples && genRes.generatedSamples.length > 0) {
                const sample = genRes.generatedSamples[0];
                if (sample.video && sample.video.uri) {
                    return sample.video.uri;
                }
            }
        }
        
        throw new Error('Video URI not found in operation response');
    }
    
    async downloadVideo(videoUri) {
        let currentUrl = videoUri;
        let redirectCount = 0;
        const maxRedirects = 5;
        
        while (redirectCount < maxRedirects) {
            try {
                const response = await axios.get(currentUrl, {
                    responseType: 'arraybuffer',
                    maxRedirects: 0,
                    validateStatus: (status) => status >= 200 && status < 400
                });
                
                // Check if it's a redirect
                if (response.status >= 300 && response.status < 400 && response.headers.location) {
                    currentUrl = response.headers.location;
                    redirectCount++;
                    continue;
                }
                
                return Buffer.from(response.data);
            } catch (error) {
                if (error.response && error.response.status >= 300 && error.response.status < 400 && error.response.headers.location) {
                    currentUrl = error.response.headers.location;
                    redirectCount++;
                    continue;
                }
                throw new Error(`Failed to download video: ${error.message}`);
            }
        }
        
        throw new Error(`Too many redirects (${maxRedirects}) when downloading video`);
    }
}

class GeminiVideoGenerator {
    constructor(apiKey = null, basePath = null, baseUrl = null, savePath = null, verbose = false) {
        this.apiKey = apiKey || process.env.GEMINI_API_KEY || '';
        
        // Resolve base_path
        if (basePath) {
            this.basePath = path.resolve(basePath);
        } else {
            const envBasePath = process.env.MAGENTO_BASE_PATH;
            if (envBasePath) {
                this.basePath = path.resolve(envBasePath);
            } else {
                this.basePath = process.cwd();
            }
        }
        
        // Resolve save_path
        if (savePath) {
            const savePathObj = path.resolve(savePath);
            if (path.isAbsolute(savePath)) {
                this.videoDir = savePathObj;
            } else {
                this.videoDir = path.join(this.basePath, savePath);
            }
        } else {
            const envSavePath = process.env.VIDEO_SAVE_PATH;
            if (envSavePath) {
                const savePathObj = path.resolve(envSavePath);
                if (path.isAbsolute(envSavePath)) {
                    this.videoDir = savePathObj;
                } else {
                    this.videoDir = path.join(this.basePath, envSavePath);
                }
            } else {
                // Default: pub/media/video
                this.videoDir = path.join(this.basePath, 'pub', 'media', 'video');
            }
        }
        
        // Ensure video directory exists
        if (!fs.existsSync(this.videoDir)) {
            fs.mkdirSync(this.videoDir, { recursive: true });
        }
        
        // Get base URL
        if (baseUrl) {
            this.baseUrl = baseUrl.replace(/\/$/, '');
        } else {
            try {
                this.baseUrl = this._getBaseUrl();
            } catch (e) {
                this.baseUrl = null;
            }
        }
        
        this.verbose = verbose;
        this.videoService = new GeminiVideoService(this.apiKey, null, verbose);
    }
    
    _getBaseUrl() {
        let baseUrl = process.env.MAGENTO_BASE_URL || process.env.BASE_URL;
        if (baseUrl) {
            baseUrl = baseUrl.replace(/\/$/, '');
            if (baseUrl.includes('/default/')) {
                baseUrl = baseUrl.replace('/default/', '/');
            }
            return baseUrl.replace(/\/$/, '');
        }
        
        const host = process.env.HTTP_HOST || process.env.SERVER_NAME;
        if (host) {
            const scheme = process.env.HTTPS === 'on' ? 'https' : 'http';
            return `${scheme}://${host}`;
        }
        
        return null;
    }
    
    isUrl(pathOrUrl) {
        return pathOrUrl.startsWith('http://') || pathOrUrl.startsWith('https://');
    }
    
    async downloadImageFromUrl(url) {
        try {
            const response = await axios.get(url, { responseType: 'arraybuffer', timeout: 30000 });
            const contentType = response.headers['content-type'];
            const mimeType = (contentType ? contentType.split(';')[0].trim() : null) || 'image/jpeg';
            return { data: Buffer.from(response.data), mimeType };
        } catch (error) {
            throw new Error(`Failed to download image from URL ${url}: ${error.message}`);
        }
    }
    
    resolveImagePath(imagePath) {
        if (this.isUrl(imagePath)) {
            return null;
        }
        
        const imagePathObj = path.resolve(imagePath);
        if (path.isAbsolute(imagePath)) {
            return imagePathObj;
        }
        
        // If path starts with pub/media/, remove it
        let cleanPath = imagePath;
        if (imagePath.startsWith('pub/media/')) {
            cleanPath = imagePath.substring(10);
        }
        
        return path.join(this.basePath, 'pub', 'media', cleanPath.replace(/^\//, ''));
    }
    
    generateCacheKey(imagePath, prompt, aspectRatio, secondImagePath = null) {
        const getImageHash = (imgPath) => {
            if (this.isUrl(imgPath)) {
                // For URLs, we need to download to hash (synchronous for cache key)
                // This is a limitation - in production you might want async cache key generation
                throw new Error('Cache key generation from URLs requires async operation. Use generateCacheKeyAsync instead.');
            } else {
                const imagePathObj = path.resolve(imgPath);
                if (!fs.existsSync(imagePathObj)) {
                    throw new Error(`Image not found: ${imgPath}`);
                }
                const imageData = fs.readFileSync(imagePathObj);
                return crypto.createHash('md5').update(imageData).digest('hex');
            }
        };
        
        const imageHash = getImageHash(imagePath);
        let secondImageHash = '';
        if (secondImagePath) {
            secondImageHash = getImageHash(secondImagePath);
        }
        
        const cacheData = `${imageHash}:${secondImageHash}:${prompt}:${aspectRatio}`;
        return crypto.createHash('md5').update(cacheData).digest('hex');
    }
    
    async generateCacheKeyAsync(imagePath, prompt, aspectRatio, secondImagePath = null) {
        const getImageHash = async (imgPath) => {
            if (this.isUrl(imgPath)) {
                const { data } = await this.downloadImageFromUrl(imgPath);
                return crypto.createHash('md5').update(data).digest('hex');
            } else {
                const imagePathObj = this.resolveImagePath(imgPath);
                if (!imagePathObj || !fs.existsSync(imagePathObj)) {
                    throw new Error(`Image not found: ${imgPath}`);
                }
                const imageData = fs.readFileSync(imagePathObj);
                return crypto.createHash('md5').update(imageData).digest('hex');
            }
        };
        
        const imageHash = await getImageHash(imagePath);
        let secondImageHash = '';
        if (secondImagePath) {
            secondImageHash = await getImageHash(secondImagePath);
        }
        
        const cacheData = `${imageHash}:${secondImageHash}:${prompt}:${aspectRatio}`;
        return crypto.createHash('md5').update(cacheData).digest('hex');
    }
    
    getCachedVideo(cacheKey) {
        const filename = `veo_${cacheKey}.mp4`;
        const videoPath = path.join(this.videoDir, filename);
        
        if (fs.existsSync(videoPath)) {
            const relativePath = this._getRelativeVideoPath(filename);
            
            let videoUrl;
            if (this.baseUrl) {
                const baseUrlClean = this.baseUrl.replace(/\/$/, '');
                videoUrl = `${baseUrlClean}/${relativePath}`;
            } else {
                videoUrl = `/${relativePath}`;
            }
            
            return {
                fromCache: true,
                videoUrl: videoUrl,
                videoPath: videoPath,
                status: 'completed'
            };
        }
        
        return null;
    }
    
    _getRelativeVideoPath(filename) {
        const videoFilePath = path.join(this.videoDir, filename);
        try {
            const relativePath = path.relative(this.basePath, videoFilePath);
            return relativePath.replace(/\\/g, '/');
        } catch (e) {
            return filename;
        }
    }
    
    async saveVideo(videoUri, cacheKey) {
        const filename = `veo_${cacheKey}.mp4`;
        const videoPath = path.join(this.videoDir, filename);
        
        const videoContent = await this.videoService.downloadVideo(videoUri);
        fs.writeFileSync(videoPath, videoContent);
        
        return videoPath;
    }
    
    async loadImage(imagePath) {
        if (this.verbose) {
            console.error(`\n[Image Processing] Loading: ${imagePath}`);
        }
        
        if (this.isUrl(imagePath)) {
            if (this.verbose) {
                console.error('  Type: External URL');
            }
            const { data, mimeType } = await this.downloadImageFromUrl(imagePath);
            if (this.verbose) {
                console.error(`  Downloaded: ${data.length} bytes`);
                console.error(`  MIME Type: ${mimeType}`);
            }
            return { imageData: data, mimeType, sourcePathStr: imagePath };
        } else {
            const sourcePath = this.resolveImagePath(imagePath);
            if (!sourcePath || !fs.existsSync(sourcePath)) {
                throw new Error(`Source image not found: ${imagePath}`);
            }
            
            if (this.verbose) {
                console.error('  Type: Local file');
                console.error(`  Path: ${sourcePath}`);
            }
            
            const imageData = fs.readFileSync(sourcePath);
            const ext = path.extname(sourcePath).toLowerCase();
            const mimeTypes = {
                '.jpg': 'image/jpeg',
                '.jpeg': 'image/jpeg',
                '.png': 'image/png',
                '.gif': 'image/gif',
                '.webp': 'image/webp'
            };
            const mimeType = mimeTypes[ext] || 'image/jpeg';
            
            if (this.verbose) {
                console.error(`  Size: ${imageData.length} bytes`);
                console.error(`  MIME Type: ${mimeType}`);
            }
            
            // Get relative path for reference
            let sourcePathStr;
            try {
                sourcePathStr = path.relative(this.basePath, sourcePath).replace(/\\/g, '/');
            } catch (e) {
                sourcePathStr = imagePath;
            }
            
            return { imageData, mimeType, sourcePathStr };
        }
    }
    
    _getImageReferenceName(imagePath) {
        if (this.isUrl(imagePath)) {
            const urlParts = imagePath.split('/');
            return urlParts[urlParts.length - 1] || 'image';
        }
        return path.basename(imagePath);
    }
    
    _enhancePromptWithImageReferences(prompt, imagePath, secondImagePath = null, autoReference = true) {
        if (!autoReference || !secondImagePath) {
            return prompt;
        }
        
        const image1Name = this._getImageReferenceName(imagePath);
        const image2Name = this._getImageReferenceName(secondImagePath);
        
        const context = `Context: You have two images - '${image1Name}' (image1/first image) and '${image2Name}' (image2/second image). `;
        return context + prompt;
    }
    
    async generateVideoFromImage(imagePath, prompt, aspectRatio = '16:9', silentVideo = false, secondImagePath = null, autoReferenceImages = true) {
        // Load images
        const { imageData, mimeType, sourcePathStr } = await this.loadImage(imagePath);
        
        let secondImageData = null;
        let secondMimeType = null;
        let secondSourcePathStr = null;
        
        if (secondImagePath) {
            const secondImage = await this.loadImage(secondImagePath);
            secondImageData = secondImage.imageData;
            secondMimeType = secondImage.mimeType;
            secondSourcePathStr = secondImage.sourcePathStr;
        }
        
        // Enhance prompt with image references if needed
        let finalPrompt = this._enhancePromptWithImageReferences(prompt, sourcePathStr, secondSourcePathStr, autoReferenceImages);
        
        if (this.verbose) {
            console.error('\n[Prompt Processing]');
            console.error(`Original Prompt: ${prompt}`);
            console.error(`Final Prompt: ${finalPrompt}`);
        }
        
        // Append "silent video" if requested
        if (silentVideo) {
            finalPrompt = `${finalPrompt.trim()} silent video`;
            if (this.verbose) {
                console.error(`Added 'silent video': ${finalPrompt}`);
            }
        }
        
        // Generate cache key (use async version to handle URLs)
        const cacheKey = await this.generateCacheKeyAsync(
            sourcePathStr,
            finalPrompt,
            aspectRatio,
            secondSourcePathStr || null
        );
        
        // Check cache
        const cachedVideo = this.getCachedVideo(cacheKey);
        if (cachedVideo) {
            return cachedVideo;
        }
        
        // Submit video generation request
        const result = await this.videoService.submitVideoGenerationRequest(
            finalPrompt,
            imageData,
            mimeType,
            aspectRatio,
            secondImageData,
            secondMimeType
        );
        
        return {
            operationName: result.operationName,
            cacheKey: cacheKey,
            done: result.done || false,
            status: result.status || 'running'
        };
    }
    
    async pollVideoOperation(operationName, maxWaitSeconds = 300, pollIntervalSeconds = 10, cacheKey = null) {
        const operationData = await this.videoService.pollOperationStatus(operationName, maxWaitSeconds, pollIntervalSeconds);
        
        const videoUri = this.videoService.extractVideoUri(operationData);
        
        // Save video
        const finalCacheKey = cacheKey || operationName.split('/').pop();
        const videoPath = await this.saveVideo(videoUri, finalCacheKey);
        
        // Generate video URL
        const videoFilename = path.basename(videoPath);
        const relativePath = this._getRelativeVideoPath(videoFilename);
        
        let videoUrl;
        if (this.baseUrl) {
            const baseUrlClean = this.baseUrl.replace(/\/$/, '');
            videoUrl = `${baseUrlClean}/${relativePath}`;
        } else {
            videoUrl = `/${relativePath}`;
        }
        
        const embedUrl = `<video controls width="100%" height="auto"><source src="${videoUrl}" type="video/mp4">Your browser does not support the video tag.</video>`;
        
        return {
            videoUrl: videoUrl,
            videoPath: videoPath,
            embedUrl: embedUrl,
            status: 'completed'
        };
    }
    
    isAvailable() {
        return this.videoService.isAvailable();
    }
}

// CLI Setup
program
    .name('agento-video')
    .description('Generate video from images using Google Gemini Veo 3.1 API')
    .version('1.0.0');

program
    .requiredOption('-ip, --image-path <paths...>', 'Path(s) to image file(s) or URL(s) (can specify multiple)')
    .requiredOption('-p, --prompt <prompt>', 'Video generation prompt')
    .option('-k, --api-key <key>', 'Google Gemini API key (overrides GEMINI_API_KEY environment variable)')
    .option('-ar, --aspect-ratio <ratio>', 'Aspect ratio (16:9, 9:16, 1:1)', '16:9')
    .option('-si, --second-image <path>', 'Path to second image file or URL')
    .option('--no-auto-reference', 'Disable automatic image reference enhancement in prompt')
    .option('-sv, --silent-video', 'Add "silent video" to prompt')
    .option('--sync', 'Wait for video generation to complete (synchronous mode)')
    .option('--base-path <path>', 'Base path for Magento installation (overrides MAGENTO_BASE_PATH environment variable)')
    .option('--save-path <path>', 'Path where videos should be saved (overrides VIDEO_SAVE_PATH environment variable)')
    .option('--base-url <url>', 'Base URL for generating full video URLs (overrides MAGENTO_BASE_URL environment variable)')
    .option('--env-file <path>', 'Path to .env file')
    .option('-v, --verbose', 'Enable verbose debug output');

// Load .env file if specified
program.parse();
const options = program.opts();

if (options.envFile) {
    dotenv.config({ path: options.envFile });
}

// Get API key
const apiKey = options.apiKey || process.env.GEMINI_API_KEY;
if (!apiKey) {
    console.error(JSON.stringify({
        success: false,
        error: 'API key is required. Use --api-key or set GEMINI_API_KEY environment variable.'
    }, null, 2));
    process.exit(1);
}

// Handle multiple image paths (commander returns array for variadic options)
const imagePaths = options.imagePath || [];

(async () => {
    try {
        // Initialize generator
        const generator = new GeminiVideoGenerator(
            apiKey,
            options.basePath || null,
            options.baseUrl || null,
            options.savePath || null,
            options.verbose || false
        );
        
        // Check if service is available
        if (!generator.isAvailable()) {
            console.error(JSON.stringify({
                success: false,
                error: 'Video service is not available. Please configure Gemini API key.'
            }, null, 2));
            process.exit(1);
        }
        
        const results = [];
        const errors = [];
        
        for (const imagePath of imagePaths) {
            try {
                // Generate video
                const operation = await generator.generateVideoFromImage(
                    imagePath,
                    options.prompt,
                    options.aspectRatio || '16:9',
                    options.silentVideo || false,
                    options.secondImage || null,
                    !options.noAutoReference
                );
                
                // Check if video was returned from cache
                if (operation.fromCache) {
                    const resultItem = {
                        imagePath: imagePath,
                        success: true,
                        status: 'completed',
                        videoUrl: operation.videoUrl,
                        videoPath: operation.videoPath,
                        cached: true
                    };
                    if (options.secondImage) {
                        resultItem.secondImagePath = options.secondImage;
                    }
                    results.push(resultItem);
                    continue;
                }
                
                // If sync option is set, wait for completion
                if (options.sync) {
                    const resultData = await generator.pollVideoOperation(
                        operation.operationName,
                        300,
                        10,
                        operation.cacheKey
                    );
                    
                    const resultItem = {
                        imagePath: imagePath,
                        success: true,
                        status: 'completed',
                        videoUrl: resultData.videoUrl,
                        videoPath: resultData.videoPath,
                        embedUrl: resultData.embedUrl
                    };
                    if (options.secondImage) {
                        resultItem.secondImagePath = options.secondImage;
                    }
                    results.push(resultItem);
                } else {
                    // Return operation ID for async mode
                    const resultItem = {
                        imagePath: imagePath,
                        success: true,
                        status: 'processing',
                        operationName: operation.operationName,
                        message: 'Video generation started. Use --sync option to wait for completion.'
                    };
                    if (options.secondImage) {
                        resultItem.secondImagePath = options.secondImage;
                    }
                    results.push(resultItem);
                }
            } catch (error) {
                errors.push({
                    imagePath: imagePath,
                    success: false,
                    error: error.message
                });
            }
        }
        
        // Prepare final result
        let result;
        if (imagePaths.length === 1) {
            // Single image - return single result format
            result = results.length > 0 ? results[0] : errors[0];
        } else {
            // Multiple images - return array format
            result = {
                success: errors.length === 0,
                total: imagePaths.length,
                succeeded: results.length,
                failed: errors.length,
                results: results,
                errors: errors
            };
        }
        
        console.log(JSON.stringify(result, null, 2));
        
        // Exit with error code if any failures
        if (errors.length > 0) {
            process.exit(1);
        }
        process.exit(0);
    } catch (error) {
        console.error(JSON.stringify({
            success: false,
            error: error.message
        }, null, 2));
        process.exit(1);
    }
})();
