#!/usr/bin/env python3
"""
Simple test script for the image generation server.
"""
import requests
import json
import time

SERVER_URL = "http://localhost:5000"

def test_health():
    """Test health endpoint."""
    print("Testing health endpoint...")
    response = requests.get(f"{SERVER_URL}/health")
    print(f"Status: {response.status_code}")
    print(f"Response: {response.json()}")
    assert response.status_code == 200
    assert response.json()["status"] == "healthy"
    print("✅ Health check passed\n")

def test_single_image_generation():
    """Test single image generation."""
    print("Testing single image generation...")
    payload = {
        "model_image": "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg",
        "prompt": "Create a professional fashion photo from this image"
    }

    response = requests.post(f"{SERVER_URL}/generate", json=payload)
    print(f"Status: {response.status_code}")
    result = response.json()
    print(f"Response: {json.dumps(result, indent=2)}")

    assert response.status_code == 200
    assert result["success"] == True
    assert "filename" in result
    assert "ws01_black" in result["filename"]
    print("✅ Single image generation passed\n")

def test_two_image_generation():
    """Test two image generation."""
    print("Testing two image generation...")
    payload = {
        "model_image": "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/w/s/ws01-black_main_1.jpg",
        "look_image": "https://react-luma.cnxt.link/media/catalog/product/cache/decdbd3689b02cb033cfb093915217ec/m/j/mj12-orange_main_1.jpg",
        "prompt": "Create a professional fashion photo combining these images"
    }

    response = requests.post(f"{SERVER_URL}/generate", json=payload)
    print(f"Status: {response.status_code}")
    result = response.json()
    print(f"Response: {json.dumps(result, indent=2)}")

    assert response.status_code == 200
    assert result["success"] == True
    assert "filename" in result
    assert "ws01_black" in result["filename"] and "mj12_orange" in result["filename"]
    print("✅ Two image generation passed\n")

def test_list_images():
    """Test list images endpoint."""
    print("Testing list images endpoint...")
    response = requests.get(f"{SERVER_URL}/list-images")
    print(f"Status: {response.status_code}")
    result = response.json()
    print(f"Found {result['count']} images")

    assert response.status_code == 200
    assert result["success"] == True
    assert isinstance(result["images"], list)
    print("✅ List images passed\n")

def test_error_handling():
    """Test error handling."""
    print("Testing error handling...")

    # Test missing model_image
    payload = {"prompt": "test"}
    response = requests.post(f"{SERVER_URL}/generate", json=payload)
    assert response.status_code == 400
    print("✅ Missing model_image error handled")

    # Test missing prompt
    payload = {"model_image": "test.jpg"}
    response = requests.post(f"{SERVER_URL}/generate", json=payload)
    assert response.status_code == 400
    print("✅ Missing prompt error handled")

    print("✅ Error handling passed\n")

if __name__ == "__main__":
    print("🧪 Testing Agento Image Server")
    print("=" * 40)

    try:
        test_health()
        test_single_image_generation()
        test_two_image_generation()
        test_list_images()
        test_error_handling()

        print("🎉 All tests passed!")

    except Exception as e:
        print(f"❌ Test failed: {e}")
        raise