#!/usr/bin/env python3
"""
List available models and test image generation with google-generativeai 0.8.6
"""
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

try:
    from google import generativeai as genai
    
    api_key = os.getenv('GEMINI_API_KEY')
    if not api_key:
        print("❌ GEMINI_API_KEY not set")
        sys.exit(1)
    
    genai.configure(api_key=api_key)
    
    print("🔍 Available Models in google-generativeai 0.8.6:")
    print("=" * 70)
    
    # List all models
    for model in genai.list_models():
        print(f"\n📋 {model.name}")
        print(f"   Display: {model.display_name}")
        print(f"   Input token limit: {model.input_token_limit}")
        print(f"   Output token limit: {model.output_token_limit}")
        if hasattr(model, 'supported_generation_methods'):
            print(f"   Methods: {model.supported_generation_methods}")
    
    print("\n" + "=" * 70)
    print("✅ Model list complete")

except Exception as e:
    print(f"❌ Error: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
