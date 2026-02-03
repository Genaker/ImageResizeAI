#!/usr/bin/env python3
"""
Mock Veo API Server for Testing

This server mimics the Google Gemini Veo 3.1 API responses to allow testing
without consuming API quota or hitting rate limits.

Usage:
    python3 mock_veo_server.py [--port PORT] [--host HOST]

Environment Variables:
    PORT: Server port (default: 8080)
    HOST: Server host (default: 127.0.0.1)

Example:
    # Start mock server
    python3 mock_veo_server.py --port 8080
    
    # Set environment variable to use mock server
    export GOOGLE_API_DOMAIN=http://127.0.0.1:8080/v1beta
    
    # Run video generation (will use mock server)
    python3 agento_video.py -ip image.jpg -p "test prompt" --poll
"""

import json
import os
import time
import uuid
import argparse
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import base64


class MockVeoAPIHandler(BaseHTTPRequestHandler):
    """HTTP request handler for mock Veo API"""
    
    # Store operations in memory (in production, this would be a database)
    operations = {}
    
    def log_message(self, format, *args):
        """Override to suppress default logging"""
        # Uncomment to enable request logging
        # super().log_message(format, *args)
        pass
    
    def do_POST(self):
        """Handle POST requests (video generation submission)"""
        parsed_path = urlparse(self.path)
        
        # Check if this is a predictLongRunning request
        if ':predictLongRunning' in parsed_path.path:
            # Read request body
            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length)
            
            try:
                payload = json.loads(body.decode('utf-8'))
            except json.JSONDecodeError:
                self.send_error(400, "Invalid JSON")
                return
            
            # Validate API key (if provided)
            api_key = self.headers.get('x-goog-api-key') or parse_qs(parsed_path.query).get('key', [None])[0]
            if not api_key:
                self.send_error(401, "API key required")
                return
            
            # Create operation response
            operation_id = str(uuid.uuid4())
            operation_name = f"operations/{operation_id}"
            
            # Store operation (simulate async processing)
            self.operations[operation_name] = {
                'name': operation_name,
                'done': False,
                'payload': payload,
                'created_at': time.time()
            }
            
            # Return operation response
            response_data = {
                'name': operation_name,
                'done': False
            }
            
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(response_data).encode('utf-8'))
            
        else:
            self.send_error(404, "Endpoint not found")
    
    def do_GET(self):
        """Handle GET requests (operation polling and video download)"""
        parsed_path = urlparse(self.path)
        path_parts = parsed_path.path.strip('/').split('/')
        
        # Check if this is an operation status request
        # Handle both /operations/... and /v1beta/operations/... paths
        if 'operations' in path_parts:
            operation_idx = path_parts.index('operations')
            if operation_idx + 1 < len(path_parts):
                operation_name = '/'.join(path_parts[operation_idx:])
            else:
                self.send_error(404, "Operation ID not found")
                return
            
            if operation_name not in self.operations:
                self.send_error(404, "Operation not found")
                return
            
            operation = self.operations[operation_name]
            elapsed = time.time() - operation['created_at']
            
            # Simulate processing time (complete after 5 seconds)
            # Real Gemini API only returns minimal response when not done
            if elapsed < 5:
                # Still processing - return minimal response (matches real API)
                response_data = {
                    'name': operation_name,
                    'done': False
                }
            else:
                # Operation complete - decide response type based on payload
                operation['done'] = True
                payload = operation.get('payload', {})
                instance = None
                try:
                    instance = payload.get('instances', [])[0]
                except Exception:
                    instance = None

                # If the instance contains modelImage and lookImage, return an image response
                if instance and isinstance(instance, dict) and 'modelImage' in instance and 'lookImage' in instance:
                    image_id = str(uuid.uuid4())
                    operation['image_id'] = image_id
                    image_uri = f"http://{self.server.server_name}:{self.server.server_port}/images/{image_id}"

                    response_data = {
                        'name': operation_name,
                        'done': True,
                        'response': {
                            'generateImageResponse': {
                                'generatedSamples': [
                                    {
                                        'image': {
                                            'uri': image_uri,
                                            'mimeType': 'image/png'
                                        }
                                    }
                                ]
                            }
                        }
                    }
                else:
                    # Default to video response
                    # Generate mock video URI (points back to this server for download)
                    video_id = str(uuid.uuid4())
                    # Store video ID for later download
                    operation['video_id'] = video_id
                    video_uri = f"http://{self.server.server_name}:{self.server.server_port}/videos/{video_id}"

                    # Return response matching real Gemini API format
                    response_data = {
                        'name': operation_name,
                        'done': True,
                        'response': {
                            'generateVideoResponse': {
                                'generatedSamples': [
                                    {
                                        'video': {
                                            'uri': video_uri,
                                            'mimeType': 'video/mp4'
                                        }
                                    }
                                ]
                            }
                        }
                    }
            
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(response_data).encode('utf-8'))
            
        # Check if this is a video download request
        elif path_parts[0] == 'videos' and len(path_parts) > 1:
            video_id = path_parts[1]

            # Generate a minimal mock MP4 file (just headers, no actual video data)
            # This is a valid MP4 file structure (fMP4 format)
            mock_video_data = self._generate_mock_mp4()

            self.send_response(200)
            self.send_header('Content-Type', 'video/mp4')
            self.send_header('Content-Length', str(len(mock_video_data)))
            self.end_headers()
            self.wfile.write(mock_video_data)

        # Image download endpoint for nana-babana tests
        elif path_parts[0] == 'images' and len(path_parts) > 1:
            image_id = path_parts[1]
            mock_png = self._generate_mock_png()

            self.send_response(200)
            self.send_header('Content-Type', 'image/png')
            self.send_header('Content-Length', str(len(mock_png)))
            self.end_headers()
            self.wfile.write(mock_png)
            
        else:
            self.send_error(404, "Endpoint not found")
    
    def _generate_mock_mp4(self) -> bytes:
        """
        Generate a minimal valid MP4 file for testing
        
        Returns:
            Bytes of a minimal MP4 file
        """
        # Minimal MP4 file structure (fMP4 format)
        # This creates a very small valid MP4 file that can be downloaded
        # but won't play (it's just for testing the download mechanism)
        
        # ftyp box
        ftyp = b'ftyp'
        ftyp_size = b'\x00\x00\x00\x20'  # 32 bytes
        ftyp_data = b'isomiso2mp41'
        
        # mdat box (empty media data)
        mdat = b'mdat'
        mdat_size = b'\x00\x00\x00\x08'  # 8 bytes (just header)
        
        # moov box (minimal movie header)
        moov = b'moov'
        moov_size = b'\x00\x00\x00\x10'  # 16 bytes (minimal)
        moov_data = b'\x00' * 8  # Placeholder data
        
        mp4_data = (
            ftyp_size + ftyp + ftyp_data +
            mdat_size + mdat +
            moov_size + moov + moov_data
        )
        
        return mp4_data

    def _generate_mock_png(self) -> bytes:
        """
        Generate a minimal valid PNG file (1x1) for testing

        Returns:
            Bytes of a minimal PNG file
        """
        # 1x1 PNG file (binary) - minimal valid PNG
        # This is a standard 1x1 transparent PNG
        png_hex = (
            b'\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01'
            b'\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89'
            b'\x00\x00\x00\x0bIDATx\x9cc``\x00\x00\x00\x02\x00\x01' 
            b'\xe2!\xbc\x33\x00\x00\x00\x00IEND\xaeB`\x82'
        )
        return png_hex


def main():
    """Main entry point for mock server"""
    parser = argparse.ArgumentParser(
        description='Mock Veo API Server for Testing',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
    # Start server on default port 8080
    python3 mock_veo_server.py
    
    # Start server on custom port
    python3 mock_veo_server.py --port 9000
    
    # Use mock server for testing
    export GOOGLE_API_DOMAIN=http://127.0.0.1:8080/v1beta
    python3 agento_video.py -ip image.jpg -p "test" --poll
        """
    )
    
    parser.add_argument(
        '--port',
        type=int,
        default=int(os.getenv('PORT', '8080')),
        help='Server port (default: 8080 or PORT env var)'
    )
    
    parser.add_argument(
        '--host',
        type=str,
        default=os.getenv('HOST', '127.0.0.1'),
        help='Server host (default: 127.0.0.1 or HOST env var)'
    )
    
    args = parser.parse_args()
    
    # Create server
    server_address = (args.host, args.port)
    httpd = HTTPServer(server_address, MockVeoAPIHandler)
    
    print(f"Mock Veo API Server starting on http://{args.host}:{args.port}")
    print(f"Set GOOGLE_API_DOMAIN=http://{args.host}:{args.port}/v1beta to use this server")
    print("Press Ctrl+C to stop the server")
    
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down server...")
        httpd.shutdown()


if __name__ == '__main__':
    main()
