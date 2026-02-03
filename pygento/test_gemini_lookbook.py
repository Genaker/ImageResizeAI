#!/usr/bin/env python3
"""
Real Gemini API test with specified images.
Uses the standard generateContent endpoint instead of custom :predictLongRunning.
"""
import os
import sys
import requests
import json
from pathlib import Path
import time
import base64

def download_image(url: str, output_path: str) -> bytes:
    """Download image from URL and save locally, return bytes."""
    print(f"  Downloading: {url}")
    resp = requests.get(url, timeout=30)
    resp.raise_for_status()
    Path(output_path).write_bytes(resp.content)
    print(f"  ✓ Saved to: {output_path}")
    return resp.content


def main():
    api_key = os.getenv('GEMINI_API_KEY')
    if not api_key:
        print("❌ GEMINI_API_KEY environment variable not set")
        sys.exit(1)

    # Image URLs
    model_url = "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg"
    dress_url = "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg"

    # Temp directory for downloads
    temp_dir = Path("/tmp/gemini_test")
    temp_dir.mkdir(exist_ok=True)

    model_path = temp_dir / "model.jpg"
    dress_path = temp_dir / "dress.jpg"

    print("\n📥 Downloading images...")
    model_data = download_image(model_url, str(model_path))
    dress_data = download_image(dress_url, str(dress_path))

    print("\n🎬 Generating lookbook using Gemini API...")
    
    # Use Gemini's generateContent endpoint
    model_name = "gemini-2.0-flash-001"
    api_url = f"https://generativelanguage.googleapis.com/v1beta/models/{model_name}:generateContent?key={api_key}"
    
    # Encode images to base64
    model_b64 = base64.standard_b64encode(model_data).decode('utf-8')
    dress_b64 = base64.standard_b64encode(dress_data).decode('utf-8')

    # Create request payload with both images
    payload = {
        "contents": [{
            "parts": [
                {
                    "text": "You are an expert fashion stylist. I'm providing two images: 1) a model wearing a black outfit, 2) an orange dress. Create a detailed professional fashion lookbook description that shows how to style the orange dress on the model. Include styling tips, color combinations, and fashion advice. Then, imagine and describe what a professional lookbook photo would look like combining these elements."
                },
                {
                    "inline_data": {
                        "mime_type": "image/jpeg",
                        "data": model_b64
                    }
                },
                {
                    "inline_data": {
                        "mime_type": "image/jpeg",
                        "data": dress_b64
                    }
                }
            ]
        }]
    }

    try:
        print("  Calling Gemini API...")
        headers = {'Content-Type': 'application/json'}
        resp = requests.post(api_url, json=payload, headers=headers, timeout=60)
        resp.raise_for_status()
        
        result = resp.json()
        print(f"  ✓ API response received")

        # Extract the generated text
        if 'candidates' in result and result['candidates']:
            candidate = result['candidates'][0]
            if 'content' in candidate and 'parts' in candidate['content']:
                text_content = candidate['content']['parts'][0].get('text', '')
                
                # Save the result
                output_dir = Path.cwd() / "real_api_output"
                output_dir.mkdir(exist_ok=True)
                
                output_file = output_dir / f"lookbook_result_{int(time.time())}.txt"
                output_file.write_text(text_content)
                
                print(f"\n✅ Success!")
                print(f"   Output file: {output_file}")
                print(f"\n📄 Generated Lookbook Description:")
                print("=" * 70)
                print(text_content)
                print("=" * 70)
                
                return 0
        
        print(f"\n❌ Unexpected response format: {json.dumps(result, indent=2)}")
        return 1

    except Exception as e:
        print(f"\n❌ Error: {e}")
        import traceback
        traceback.print_exc()
        return 1


if __name__ == '__main__':
    sys.exit(main())
