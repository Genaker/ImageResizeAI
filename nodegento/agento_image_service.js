const fs = require('fs');
const path = require('path');
const axios = require('axios');
const { GoogleGenerativeAI } = require('@google/generative-ai');

class NanaBabanaImageService {
    constructor(apiKey = null, basePath = null, baseUrl = null, verbose = false) {
        this.apiKey = apiKey || process.env.GEMINI_API_KEY || '';
        this.verbose = verbose;

        // Base URL similar to video generator
        if (baseUrl) {
            this.baseUrl = baseUrl.replace(/\/$/, '');
        } else {
            this.baseUrl = process.env.GOOGLE_API_DOMAIN || 'https://generativelanguage.googleapis.com/v1beta';
        }

        // Use the actual Gemini image generation model
        this.modelName = process.env.MODEL_NAME || 'gemini-2.5-flash-image';

        // Base path for saving output
        this.basePath = basePath || process.cwd();
        this.outputDir = path.join(this.basePath, 'pub', 'media', 'lookbook');

        // Ensure output directory exists
        if (!fs.existsSync(this.outputDir)) {
            fs.mkdirSync(this.outputDir, { recursive: true });
        }
    }

    isAvailable() {
        return !!this.apiKey;
    }

    async _loadImage(imagePath) {
        const axiosConfig = require('axios');

        // Check if it's a URL
        if (imagePath.startsWith('http://') || imagePath.startsWith('https://')) {
            if (this.verbose) {
                console.error(`[DEBUG] Downloading image from: ${imagePath}`);
            }

            const response = await axiosConfig.get(imagePath, {
                responseType: 'arraybuffer',
                timeout: 30000
            });

            return Buffer.from(response.data);
        } else {
            // Local file
            return fs.readFileSync(imagePath);
        }
    }

    async generateImage(modelImage, lookImage = null, prompt) {
        if (!this.isAvailable()) {
            throw new Error('API key not configured');
        }

        // Try SDK approach first (like Python version)
        try {
            const { GoogleGenerativeAI } = require('@google/generative-ai');
            const genAI = new GoogleGenerativeAI(this.apiKey);
            
            // Try different model names that might work
            const modelNames = ['gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-pro'];
            let model = null;
            
            for (const modelName of modelNames) {
                try {
                    model = genAI.getGenerativeModel({ model: modelName });
                    break;
                } catch (e) {
                    continue;
                }
            }
            
            if (!model) {
                throw new Error('No suitable model found');
            }

            if (this.verbose) {
                console.error(`[DEBUG] Using model: ${model.model}`);
                console.error(`[DEBUG] Images: ${modelImage}${lookImage ? ', ' + lookImage : ''}`);
            }

            // Load images
            const img1Buffer = await this._loadImage(modelImage);
            const contents = [prompt, { inlineData: { data: img1Buffer.toString('base64'), mimeType: 'image/jpeg' } }];

            if (lookImage) {
                const img2Buffer = await this._loadImage(lookImage);
                contents.push({ inlineData: { data: img2Buffer.toString('base64'), mimeType: 'image/jpeg' } });
            }

            // Generate content
            const result = await model.generateContent(contents);
            const response = await result.response;

            // Convert SDK response to expected format
            const generatedSamples = [];
            for (const part of response.candidates[0].content.parts) {
                if (part.inlineData) {
                    generatedSamples.push({
                        image: {
                            data: part.inlineData.data,
                            mime_type: part.inlineData.mimeType
                        }
                    });
                }
            }

            if (generatedSamples.length === 0) {
                throw new Error('No generated images found in response');
            }

            // Return in format expected by save_asset_from_operation
            return {
                done: true,
                response: {
                    generateImageResponse: {
                        generatedSamples: generatedSamples
                    }
                }
            };
            
        } catch (sdkError) {
            if (this.verbose) {
                console.error(`[SDK Error] ${sdkError.message}`);
            }
            throw sdkError;
        }
    }

    saveAssetFromOperation(operationData, cacheKey = null) {
        // Look at response structure similar to video generator
        const resp = operationData.response || {};
        const gen = resp.generateVideoResponse || resp.generateImageResponse || {};
        const samples = gen.generatedSamples || [];

        if (samples.length === 0) {
            throw new Error('No generated samples found in operation response');
        }

        const sample = samples[0];
        let content;

        // Check for inline data first (SDK response)
        if (sample.image && sample.image.data) {
            // Direct data, decode from base64
            content = Buffer.from(sample.image.data, 'base64');
        } else {
            // URI-based download (legacy HTTP response)
            const uri = (sample.video || sample.image || {}).uri;
            if (!uri) {
                throw new Error('No URI or data found for generated asset');
            }
            // This would need to be implemented for legacy support
            throw new Error('URI-based download not implemented in Node.js version');
        }

        // Save to file using cache_key or generated name
        const name = cacheKey || `lookbook_${Math.floor(Date.now() / 1000)}`;
        const outPath = path.join(this.outputDir, `${name}.jpg`);
        fs.writeFileSync(outPath, content);

        return outPath;
    }
}

module.exports = { NanaBabanaImageService };