#!/usr/bin/env python3
"""
Flask server for image generation using Gemini API.

This server provides the same functionality as agento_image.py CLI tool,
but accepts JSON payloads via HTTP endpoints.
"""
import os
import json
import traceback
from flask import Flask, request, jsonify, send_file
from pathlib import Path
import tempfile
import shutil

# Import the service classes from agento_image.py
from agento_image import NanaBabanaImageService, generate_descriptive_filename

app = Flask(__name__)

# Configuration
DEFAULT_PORT = int(os.getenv('PORT', 5000))
DEFAULT_HOST = os.getenv('HOST', '0.0.0.0')
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY', '')
BASE_PATH = Path(os.getenv('BASE_PATH', Path.cwd()))
UPLOAD_FOLDER = BASE_PATH / 'pub' / 'media' / 'lookbook'
UPLOAD_FOLDER.mkdir(parents=True, exist_ok=True)

# Global service instance
image_service = NanaBabanaImageService(
    api_key=GEMINI_API_KEY,
    base_path=str(BASE_PATH)
)

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'agento-image-server',
        'api_available': image_service.is_available()
    })

@app.route('/generate', methods=['POST'])
def generate_image():
    """
    Generate image from one or two input images.

    Expected JSON payload:
    {
        "model_image": "path/to/image.jpg or https://url/to/image.jpg",
        "look_image": "path/to/image.jpg or https://url/to/image.jpg (optional)",
        "prompt": "Description of what to generate",
        "api_key": "optional override API key"
    }
    """
    try:
        # Get JSON data
        data = request.get_json()
        if not data:
            return jsonify({
                'success': False,
                'error': 'No JSON payload provided'
            }), 400

        # Extract parameters
        model_image = data.get('model_image')
        look_image = data.get('look_image')  # Optional
        prompt = data.get('prompt')
        api_key = data.get('api_key', GEMINI_API_KEY)

        # Validate required parameters
        if not model_image:
            return jsonify({
                'success': False,
                'error': 'model_image is required'
            }), 400

        if not prompt:
            return jsonify({
                'success': False,
                'error': 'prompt is required'
            }), 400

        # Create service instance with optional API key override
        service = NanaBabanaImageService(
            api_key=api_key,
            base_path=str(BASE_PATH)
        )

        if not service.is_available():
            return jsonify({
                'success': False,
                'error': 'API key not configured'
            }), 500

        # Generate image
        op = service.generate_image(model_image, look_image, prompt)

        if op.get('done'):
            # Generate descriptive filename
            cache_key = generate_descriptive_filename(model_image, look_image, prompt)

            # Save the generated image
            saved_path = service.save_asset_from_operation(op, cache_key=cache_key)

            return jsonify({
                'success': True,
                'status': 'completed',
                'saved_path': saved_path,
                'filename': Path(saved_path).name,
                'message': f'Image generated and saved to {saved_path}',
                'input': {
                    'model_image': model_image,
                    'look_image': look_image,
                    'prompt': prompt
                }
            })
        else:
            # Async response (fallback)
            return jsonify(op)

    except Exception as e:
        app.logger.error(f"Error generating image: {str(e)}")
        app.logger.error(traceback.format_exc())
        return jsonify({
            'success': False,
            'error': str(e),
            'traceback': traceback.format_exc()
        }), 500

@app.route('/images/<filename>', methods=['GET'])
def get_image(filename):
    """Serve generated images."""
    try:
        image_path = UPLOAD_FOLDER / filename
        if not image_path.exists():
            return jsonify({
                'success': False,
                'error': f'Image {filename} not found'
            }), 404

        return send_file(str(image_path), mimetype='image/jpeg')

    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.errorhandler(404)
def not_found(error):
    return jsonify({
        'success': False,
        'error': 'Endpoint not found'
    }), 404

@app.errorhandler(500)
def internal_error(error):
    return jsonify({
        'success': False,
        'error': 'Internal server error'
    }), 500

def main():
    """Run the Flask server."""
    print(f"Starting Agento Image Server on {DEFAULT_HOST}:{DEFAULT_PORT}")
    print(f"Upload folder: {UPLOAD_FOLDER}")
    print(f"API available: {image_service.is_available()}")

    app.run(
        host=DEFAULT_HOST,
        port=DEFAULT_PORT,
        debug=os.getenv('DEBUG', 'false').lower() == 'true'
    )

if __name__ == '__main__':
    main()