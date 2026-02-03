#!/usr/bin/env python3
"""
List all available models and find which ones support image generation
"""
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

try:
    from google import genai as genai
    
    api_key = os.getenv('GEMINI_API_KEY')
    if not api_key:
        print("❌ GEMINI_API_KEY not set")
        sys.exit(1)
    
    genai.configure(api_key=api_key)
    
    print("\n🔍 Searching for Image Generation Models")
    print("=" * 70)
    
    image_models = []
    text_models = []
    
    # List all models
    for model in genai.list_models():
        name = model.name
        display = model.display_name
        methods = model.supported_generation_methods if hasattr(model, 'supported_generation_methods') else []
        
        # Look for image-related models or methods
        if 'image' in name.lower() or 'imagen' in name.lower() or any('generateImage' in m for m in methods):
            image_models.append({
                'name': name,
                'display': display,
                'methods': methods
            })
            print(f"\n📷 IMAGE MODEL: {name}")
            print(f"   Display: {display}")
            print(f"   Methods: {methods}")
        
        # Also check for any model with 'predict' or 'predictLongRunning'
        if 'predict' in str(methods).lower():
            print(f"\n⚙️  PREDICT-CAPABLE: {name}")
            print(f"   Display: {display}")
            print(f"   Methods: {methods}")
    
    print("\n" + "=" * 70)
    print(f"Found {len(image_models)} image generation models")

except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
